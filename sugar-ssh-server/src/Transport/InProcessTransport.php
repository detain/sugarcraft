<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Transport;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Pty\SignalForwarder;
use SugarCraft\Pty\SizeIoctl;
use SugarCraft\SshServer\Channel\ChannelHandler;
use SugarCraft\SshServer\Channel\DefaultChannelHandler;
use SugarCraft\SshServer\Channel\Msg\WindowChangeMsg;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Lang;
use SugarCraft\SshServer\Session;
use SugarCraft\SshServer\Transport;
use SugarCraft\SshServer\TransportAware;

/**
 * Default transport — allocates a `sugar-pty` master/slave pair via
 * the injected (or factory-resolved) {@see PtySystem}, spawns the
 * user's cmd as a subprocess with `controllingTerminal: true`, and
 * pumps bytes between the supervisor's STDIN/STDOUT and the PTY
 * master via {@see PosixPump}.
 *
 * The middleware stack walks like {@see HostSshdTransport} does
 * (Logger, RateLimit, Auth, etc. work unchanged). Spawning a child
 * is delegated to a terminal middleware — `Spawn`, or this class's own
 * {@see DefaultChannelHandler} — which calls back into
 * {@see runChild()} with the cmd produced from the Session.
 */
final class InProcessTransport implements Transport, ChildSpawner, KeepaliveArming
{
    use DispatchesMiddlewareStack;

    /**
     * PTY backend used by `runChild()`. Injected for testability — a
     * stub can satisfy `PtySystem` without touching libc / FFI.
     * Defaults to {@see PtySystemFactory::new()} on first use.
     */
    private readonly PtySystem $system;

    /**
     * Override the size provider used by SIGWINCH forwarding.
     *
     * Defaults to {@see readHostStdinSize()} which queries
     * `TIOCGWINSZ` on fd 0 — i.e. the slave side of sshd's PTY in
     * a ForceCommand deployment. Tests inject a fake provider so
     * SIGWINCH-driven resize can be verified without setting up a
     * host PTY.
     *
     * Normalised to a plain Closure by withSizeProvider(), which is
     * the only writer (callable is not expressible as a property
     * type), so the field is natively `?\Closure`.
     *
     * @var (\Closure(): array{cols:int, rows:int})|null
     */
    private ?\Closure $sizeProvider = null;

    /**
     * Optional callback invoked in the pump loop whenever
     * `stream_select` times out (no I/O ready). The keepalive beat
     * armed through {@see armKeepalive()} rides this hook to send
     * periodic heartbeat bytes through the PTY master without
     * busy-polling.
     *
     * Normalised to a plain Closure by setKeepaliveCallback(), the
     * only setter; the runChild() teardown resets it back to null at
     * the end of every pumped run (the finally, beside the child-pid
     * reset): a beat armed for one run is this run's business only,
     * and a transport instance reused for a later run must not fire a
     * stale closure into a child that never asked for it (audit
     * sugar-ssh-server-016). Server::serve() re-arms at every entry,
     * so the shipped story is unaffected by the reset.
     *
     * @var ?\Closure
     */
    private ?\Closure $keepaliveCallback = null;

    /** PID of the live child, used for signal forwarding; null once runChild() tears it down. */
    private ?int $childPid = null;

    /**
     * The active master for the current session. Stored here so the
     * keepalive callback (armed via {@see armKeepalive()} at serve
     * time by {@see \SugarCraft\SshServer\Server::withKeepalive()})
     * can write heartbeat bytes when it fires inside the pump loop.
     */
    private ?MasterPty $master = null;

    /**
     * Channel handler for dispatching SSH channel-level messages.
     * Defaults to {@see DefaultChannelHandler} when not set.
     */
    private ?ChannelHandler $channelHandler = null;

    /**
     * Whether SIGWINCH can be forwarded on this platform.
     * Cached at construction so runChild() doesn't call pcntlReady() every time.
     */
    private readonly bool $sigwinchSupported;

    public function __construct(?PtySystem $system = null)
    {
        $this->system = $system ?? PtySystemFactory::new();
        $this->sigwinchSupported = SignalForwarder::pcntlReady() && \defined('SIGWINCH');
    }

    /**
     * Returns a clone of this transport with the given size
     * provider — used by SIGWINCH forwarding to query the host's
     * PTY winsize. Production code rarely needs to call this.
     *
     * The clone is shallow: a set channel handler and any pending
     * keepalive beat are shared references, and the beat captured the
     * transport it was armed on — a clone that pumps with an inherited
     * beat drives the ORIGINAL's pty(). The runChild() teardown clear
     * (audit sugar-ssh-server-016) bounds the window to a beat armed
     * but never yet run; callers wanting independent instances arm
     * each one separately (every Server::serve() arms its own).
     *
     * @param callable(): array{cols:int, rows:int} $provider
     */
    public function withSizeProvider(callable $provider): self
    {
        $clone = clone $this;
        $clone->sizeProvider = \Closure::fromCallable($provider);
        return $clone;
    }

    /**
     * {@inheritdoc}
     *
     * The beat: every `$intervalSeconds` of pump-loop idle a `\0` byte
     * travels through the PTY master into the child's input side. The
     * elapsed clock is anchored on the pump's FIRST idle tick, not at
     * arm time — arming happens at serve() entry, before the
     * handshake and any slow auth round-trips, and counting that
     * pre-pump time would burn the first interval (audit
     * sugar-ssh-server-016; see {@see keepaliveBeat()}). The byte
     * reaches the remote wire only where the tty echoes input —
     * line-oriented shells echo it back, while raw-mode TUIs suppress
     * echo and those sessions stay silent. It is NOT an SSH_MSG_IGNORE
     * packet; where the echo path exists, the periodic traffic keeps
     * NAT gateways and firewalls from timing out idle connections
     * (audit sugar-ssh-server-079 moved this wiring out of the
     * middleware that used to smuggle it in via `setTransport()`).
     */
    public function armKeepalive(int $intervalSeconds): void
    {
        $this->setKeepaliveCallback($this->keepaliveBeat($intervalSeconds));
    }

    /**
     * Build the idle beat: a callback the pump fires on every
     * `stream_select` timeout that writes one `\0` through the master
     * once `$intervalSeconds` have elapsed since the previous beat —
     * with the zero point anchored on the callback's first fire (the
     * loop's first idle observation) rather than at construction.
     *
     * `$clock` and `$send` are test seams: they let the elapsed-gate
     * arithmetic and the anchor micro-pin be driven without a real
     * PTY or wall-clock waits. Production arming passes neither and
     * the defaults route straight to `microtime(true)` and
     * {@see pty()}.
     *
     * @param (callable(): float)|null $clock injectable "now"
     * @param (callable(): void)|null  $send  injectable byte emitter
     *
     * @throws \InvalidArgumentException when $intervalSeconds < 1
     */
    private function keepaliveBeat(int $intervalSeconds, ?callable $clock = null, ?callable $send = null): \Closure
    {
        if ($intervalSeconds < 1) {
            throw new \InvalidArgumentException(Lang::t('keepalive.invalid_interval'));
        }

        $now = $clock ?? (static fn (): float => \microtime(true));
        $emit = $send ?? (function (): void {
            // Writing a null byte through the PTY master is safe
            // for shells and most line-oriented programs — it is
            // ignored at the application layer, and wherever the
            // tty echoes it back the periodic traffic keeps NAT
            // warm. Raw-mode TUIs never echo: silent by design.
            $this->pty()->write("\0");
        });

        // null = not yet anchored; the first fire sets the zero point
        // and sends nothing (audit sugar-ssh-server-016).
        $lastSent = null;

        return static function () use (&$lastSent, $intervalSeconds, $now, $emit): void {
            $tick = (float) $now();
            if ($lastSent === null) {
                $lastSent = $tick;
                return;
            }
            if ($tick - $lastSent >= $intervalSeconds) {
                $emit();
                $lastSent = $tick;
            }
        };
    }

    /**
     * Register a callback to be invoked in the pump loop whenever
     * `stream_select` reports no ready streams (idle timeout).
     *
     * Low-level seat beneath {@see armKeepalive()} — its single
     * legitimate caller in this library is that method. Embedders
     * wanting the shipped keepalive story go through
     * {@see \SugarCraft\SshServer\Server::withKeepalive()}, not this
     * setter; anything else registered here rides the pump loop with
     * no further guarantees.
     *
     * The callback receives no arguments; it is responsible for
     * writing keepalive bytes to the PTY when appropriate.
     *
     * @param callable(): void $callback
     */
    public function setKeepaliveCallback(callable $callback): void
    {
        $this->keepaliveCallback = \Closure::fromCallable($callback);
    }

    /**
     * Set the channel handler for dispatching SSH channel messages.
     *
     * The handler receives window-change events (forwarded from SIGWINCH
     * when the host PTY is resized) and can be queried by the
     * transport for current dimensions when spawning the child PTY.
     *
     * If not set, a {@see DefaultChannelHandler} is used.
     */
    public function setChannelHandler(ChannelHandler $handler): void
    {
        $this->channelHandler = $handler;
    }

    /**
     * Return the active channel handler, creating a default one if
     * none has been set.
     *
     * The fallback is minted fresh for each runChild() and never
     * stored: a default handler's channel state (window changes seen
     * during a run) is that run's story and does not survive to the
     * next runChild() over the same instance — consistent with the
     * one-command-per-connection ForceCommand shape this transport
     * ships for. An embedder driving several commands over one
     * transport installs an explicit handler via setChannelHandler()
     * (audit sugar-ssh-server-051).
     */
    private function channelHandler(Session $session): ChannelHandler
    {
        return $this->channelHandler ?? new DefaultChannelHandler($this, $session);
    }

    /**
     * Returns the active master PTY for the current session. Valid
     * only while a session is actively being pumped — callers must
     * not store the reference across pump-loop boundaries. Its single
     * legitimate caller in this library is the armed keepalive beat
     * ({@see armKeepalive()}); the accessor stays public so custom
     * pump callbacks registered via {@see setKeepaliveCallback()} can
     * reach the master too.
     *
     * @throws \RuntimeException if called outside of pump loop
     */
    public function pty(): MasterPty
    {
        if ($this->master === null) {
            throw new \RuntimeException(Lang::t('transport.pty_outside_loop'));
        }
        return $this->master;
    }

    public function run(Context $ctx, Session $session, array $stack): int
    {
        foreach ($stack as $mw) {
            if ($mw instanceof TransportAware) {
                $mw->setTransport($this);
            }
        }

        // Protocol metadata, best-effort from the environment (the 2026-11
        // audit's two metadata findings). This transport performs no SSH handshake and speaks
        // no wire protocol, so nothing here is a fact about the peer's
        // negotiation: the session id is MINTED for this process and is good
        // for tying this run's own lines together and nothing more; the auth
        // method is sniffed from a variable sshd only exports when told to
        // (ExposeAuthInfo, and only for a publickey session); the fingerprint
        // has no producer here at all; and the two version fields read names
        // stock OpenSSH never sets, so on an ordinary host they arrive null.
        // Absent means absent — a deployment that supplies one out of band
        // owns what the value then means.
        $authMethod = null;
        $authEnv = $_SERVER['SSH_USER_AUTH'] ?? getenv('SSH_USER_AUTH') ?: '';
        if ($authEnv !== '') {
            // SSH_USER_AUTH may be "publickey,password" or a key blob.
            // Extract the first method token as a hint.
            if (str_contains($authEnv, 'publickey')) {
                $authMethod = 'publickey';
            } elseif (str_contains($authEnv, 'password')) {
                $authMethod = 'password';
            } elseif (str_contains($authEnv, 'keyboard-interactive')) {
                $authMethod = 'keyboard-interactive';
            }
        }

        $clientVersion = ($_SERVER['SSH_CLIENT_VERSION'] ?? getenv('SSH_CLIENT_VERSION')) ?: null;
        $serverVersion = ($_SERVER['SSH_SERVER_VERSION'] ?? getenv('SSH_SERVER_VERSION')) ?: null;

        $authenticatedSession = $session->withProtocolMetadata(
            sessionId:      \bin2hex(\random_bytes(16)),
            authMethod:     $authMethod,
            keyFingerprint: null,
            clientVersion:  $clientVersion,
            serverVersion:  $serverVersion,
        );

        // The session carries the answer: the pumped child's code when a
        // Spawn / DefaultChannelHandler path ran, EXIT_DENIED when a gate
        // refused, 0 when the chain never reached a terminal (this
        // used to be `lastChildStatus ?? 0`, which could
        // only ever report success, and which outlived a transport reuse).
        return $this->dispatch($ctx, $authenticatedSession, $stack, 0);
    }

    /**
     * Spawn `$cmd` as a subprocess inside a fresh PTY and pump bytes
     * between the caller's STDIN/STDOUT (or the supervisor's process
     * stdio if those are null) and the PTY master until the child
     * exits or one of the stdio streams hits EOF.
     *
     * Returns the child's exit code. Tears down the PTY before
     * returning regardless of which exit path fires, and the teardown
     * is an explicit ladder, not a side effect of closing the master:
     * `finally` sends SIGHUP, waits ~200 ms, then SIGKILL if the child
     * is still alive (audit M14, and see the CALIBER entry on this
     * file). Closing a PTY master does NOT deliver SIGHUP on Linux —
     * the kernel only hangs the master up, so a child that ignores
     * STDIN EOF (sleep, a daemon) would otherwise outlive the session
     * and hold the socket open. That final SIGKILL escalates to the
     * child's PROCESS GROUP (audit 018): the child is a session
     * leader (the pty-shim runs setsid), so backgrounded descendants
     * share its group and fall with it — while the leader is still
     * unreaped, which is what keeps the pid number ours. The honest
     * boundary: a leader that itself died of the SIGHUP above is not
     * swept for survivors. By contrast `signalChild()` stays
     * pid-scoped — RFC 4254 signals name a process, not a group.
     *
     * The two streams are not treated alike, and the asymmetry is the
     * contract (2026-11 audit): STDIN and the PTY master are flipped to
     * non-blocking below because the pump polls them with
     * `stream_select()`, while `$stdout` is deliberately LEFT BLOCKING,
     * because the pump writes whatever it reads from the master in one
     * `fwrite()` per chunk and treats a short write as success — see
     * `PosixPump::pumpMasterToStdout()` and `flushMaster()` in sugar-pty.
     * A ForceCommand child inherits STDOUT from sshd in blocking mode, so
     * a full pipe slows the pump instead of losing bytes. Hand this method
     * a non-blocking `$stdout` (an async host that flipped its own stdio)
     * and any chunk larger than the pipe buffer is silently truncated: the
     * remainder is never retried. Buffering the unwritten tail and
     * re-arming on writability is sugar-pty's fix to make; until it lands
     * there, keep `$stdout` blocking, and do not "fix" the missing
     * `stream_set_blocking($stdout, false)` call below — it is what keeps
     * output intact, not an oversight.
     *
     * @param list<string>              $cmd  argv passed to sugar-pty
     * @param array<string,string>|null $env
     * @param resource|null             $stdin   defaults to STDIN
     * @param resource|null             $stdout  defaults to STDOUT; must
     *                                           stay blocking (see above)
     * @param Context|null              $ctx     when given, bounds the pump
     *                                           (audit sugar-ssh-server-017):
     *                                           a chain deadline is armed as
     *                                           the pump's own bound and an
     *                                           expiry at any boundary raises
     *                                           the context's error after the
     *                                           child is reaped; null leaves
     *                                           the run unbounded, which is
     *                                           the sshd-role contract —
     *                                           there sshd's SIGHUP, not a
     *                                           PHP timer, owns the child's
     *                                           lifetime
     * @throws PtyException             if the host can't allocate a
     *                                  PTY or pcntl is missing
     * @throws \RuntimeException        the context's own error
     *                                  (CancellationException /
     *                                  DeadlineExceededException) when the
     *                                  run is aborted by $ctx
     */
    public function runChild(
        Session $session,
        array $cmd,
        ?array $env = null,
        $stdin = null,
        $stdout = null,
        ?Context $ctx = null,
    ): int {
        $stdin  ??= \STDIN;
        $stdout ??= \STDOUT;

        // Cooperative entry gate (audit sugar-ssh-server-017): a context
        // already done must not see a child spawned only to be killed by
        // the teardown ladder. The error is the chain's own — deadline or
        // cancel, whichever actually fired.
        $abort = $ctx?->err();
        if ($abort !== null) {
            throw $abort;
        }

        if (!\is_resource($stdin)) {
            throw new \InvalidArgumentException(Lang::t('transport.bad_stdin'));
        }
        if (!\is_resource($stdout)) {
            throw new \InvalidArgumentException(Lang::t('transport.bad_stdout'));
        }

        // Defense-in-depth: strip the plaintext SSH_PASSWORD from the
        // ambient process environment (and any explicit env map) before
        // spawning ANY child. PasswordAuth also scrubs it, but only if it
        // ran — a spawner middleware placed *before* PasswordAuth in the
        // stack would otherwise leak the password into the child via
        // /proc/<pid>/environ (sugar-pty's proc_open inherits the parent
        // environment when $env is null). Scrubbing here makes the
        // guarantee independent of middleware ordering.
        unset($_SERVER['SSH_PASSWORD']);
        \putenv('SSH_PASSWORD');
        if ($env !== null) {
            unset($env['SSH_PASSWORD']);
        }

        $handler = $this->channelHandler($session);

        // Consult the channel handler for dimensions — it may have
        // received a window-change message before this call.
        $cols = $handler->cols() > 0 ? $handler->cols() : ($session->cols > 0 ? $session->cols : 80);
        $rows = $handler->rows() > 0 ? $handler->rows() : ($session->rows > 0 ? $session->rows : 24);

        $pair = $this->system->open($cols, $rows);
        $master = $pair->master();
        $slave = $pair->slave();
        $child = null;
        $pumpResult = -1;

        try {
            // Failure is non-fatal: stream_set_blocking returns false when
            // the stream does not support non-blocking mode (e.g. certain
            // pipe types in Windows or sandboxed environments). The pump
            // loop uses stream_select which handles blocking correctly,
            // so a failure here simply means we fall back to blocking I/O
            // for that particular stream — no data corruption or protocol
            // violation can result.
            \stream_set_blocking($master->stream(), false);
            @\stream_set_blocking($stdin, false);

            $child = $slave->spawn(
                $cmd,
                $env,
                $cols,
                $rows,
                controllingTerminal: true,
            );

            // Track the child PID so handleSignal() can forward signals.
            // pid() is the Contract\Child accessor — a bare ->pid property
            // read only works for implementations extending Pty\Child and
            // warns (returning null) on any other Contract\Child.
            $this->childPid = $child->pid();

            // SIGWINCH forwarding — when sshd resizes the supervisor's
            // PTY, the kernel sends SIGWINCH to us; dispatch a
            // WindowChangeMsg through the handler so it can update its
            // state, then apply the resize to the master PTY.
            //
            // Disposition caveat (audit 049): SIGWINCH has ONE
            // process-wide handler slot, the attach takes it, and the
            // teardown reset below restores the DEFAULT disposition —
            // NOT whatever handler ran before this session. An embedding
            // app that owns its own SIGWINCH handler must therefore
            // re-attach it after each served session (a second
            // Server::serve() disposes of it either way), and an app
            // that needs resizes kept across sessions should skip this
            // stack's attach entirely: read the size and call resize()
            // itself, as sugar-pty's SignalForwarder documents.
            $sigwinchAttached = false;
            if ($this->sigwinchSupported) {
                $sizeProvider = $this->sizeProvider ?? fn (): array => $this->readHostStdinSize($stdin, $cols, $rows);
                $sigwinchAttached = SignalForwarder::attachSigwinch(
                    $master,
                    function () use ($handler, $session, $master, $sizeProvider): array {
                        $size = $sizeProvider();
                        $msg = new WindowChangeMsg(
                            cols: $size['cols'],
                            rows: $size['rows'],
                        );
                        $handler->handleWindowChange($msg, $session);
                        $master->resize($size['cols'], $size['rows']);
                        return $size;
                    },
                );
            }

            try {
                $this->master = $master;
                $beat = $this->keepaliveCallback !== null
                    ? \Closure::fromCallable($this->keepaliveCallback)
                    : null;
                $opts   = PumpOptions::sshDefault();
                $boundUs = self::pumpBoundUs($ctx);
                if ($boundUs !== null) {
                    // The pump's own absolute bound (audit 017): the
                    // deadline is a wall-clock instant, so one arm at
                    // entry is exact — PosixPump re-checks it at every
                    // loop head.
                    $opts = $opts->withPumpDeadlineUs($boundUs);
                }
                $opts = $opts->withOnIdle($this->idleTickGuard(
                    $ctx,
                    $beat,
                    static function () use ($child): void {
                        // The cancel arm asks the child to leave with the
                        // very signal sshd uses at session end, delivered
                        // through Child::kill like the teardown ladder
                        // does; the pump then unwinds via its own
                        // loop-head check.
                        $child->kill(\defined('SIGHUP') ? \SIGHUP : 1);
                    },
                ));

                $pumpResult = (new PosixPump())->run($master, $stdin, $stdout, $child, $opts);
                // Pump-end snapshot (audit 017): the abort decision is
                // taken NOW, before the teardown ladder burns its grace
                // window, so a fast child finishing just inside the
                // deadline keeps its code.
                $abort = $ctx !== null && $ctx->done() ? $ctx->err() : null;
            } finally {
                $this->master = null;
                if ($sigwinchAttached && \defined('SIGWINCH')) {
                    // Restores DEFAULT disposition, not a prior handler
                    // (audit 049 — see the attach-site caveat above).
                    SignalForwarder::reset(\SIGWINCH);
                }
                // Give the caller's stream back the way we found it
                // (2026-11 audit). We flipped STDIN non-blocking because the
                // pump selects on it; a host that keeps using that handle
                // after serve() - a shell script, a later `read()` in the
                // entry point - would otherwise be reading a descriptor it
                // never asked to have put into non-blocking mode. `$stdout`
                // is deliberately NOT touched here: it was never flipped,
                // and the blocking-stdout contract above is what keeps
                // output intact. Memory streams ignore this call, which is
                // why the pin that guards it is structural.
                @\stream_set_blocking($stdin, true);
            }
        } finally {
            // If the child is still running (pump exited because of
            // STDIN EOF / EPIPE), terminate it explicitly: closing the
            // PTY master does NOT auto-deliver SIGHUP on Linux. Send
            // SIGHUP first (gentle, daemons handle it), then SIGKILL
            // after a brief grace if still alive.
            //
            // Through Child::kill(), not a function_exists('posix_kill')
            // guard: sugar-pty routes it to posix_kill() or libc kill(2)
            // over FFI, so ext-posix is optional. The old guard skipped the
            // whole teardown without ext-posix, and a child that ignores
            // SIGHUP then held runChild() in wait() for its entire life.
            // The nested finally keeps the master close unconditional even
            // if an injected Child::kill() throws.
            try {
                if ($child !== null && !$child->exited()) {
                    $child->kill(\defined('SIGHUP') ? \SIGHUP : 1);
                    $killDeadline = \microtime(true) + 0.2;
                    while (\microtime(true) < $killDeadline) {
                        if ($child->exited()) {
                            break;
                        }
                        \usleep(20_000);
                    }
                    if (!$child->exited()) {
                        $sigkill = \defined('SIGKILL') ? \SIGKILL : 9;
                        // Final escalation goes to the process GROUP,
                        // not just the pid (audit sugar-ssh-server-018):
                        // the runChild child is a session leader — the
                        // pty-shim runs setsid() + TIOCSCTTY — so -pid
                        // is exactly its own session's group, and every
                        // descendant it backgrounded without job control
                        // (a `cmd &` from a non-interactive shell keeps
                        // the leader's group) shares it. The old
                        // pid-scoped SIGKILL left those grandchildren
                        // running behind a dead leader.
                        //
                        // Gated on a live leader on purpose: exited()
                        // reaps as a side effect (tryWaitpid), and only
                        // an unreaped leader pins the group's pid number
                        // to us — signalling a number the kernel may
                        // already have recycled is the stale-pid hazard
                        // ChildSpawner::signalChild() documents against.
                        // The honest boundary this keeps: a leader that
                        // itself died of the SIGHUP above reports
                        // exited() here, and a survivor of ITS group is
                        // no longer ours to touch.
                        //
                        // Through Libc::kill — posix_kill() when
                        // ext-posix is loaded, libc kill(2) over FFI
                        // otherwise — the same routing Child::kill
                        // uses, so ext-posix stays optional. Two
                        // self-preservation guards: never target group
                        // -1 (the broadcast) and never our own group —
                        // a child reporting this process's pid is a
                        // test fake or corrupt state, and kill(-pgid)
                        // from a group leader hits every process in
                        // the supervisor's own session.
                        $pid = $child->pid();
                        $swept = $pid > 1
                            && $pid !== \getmypid()
                            && Libc::kill(-$pid, $sigkill);
                        // If the sweep reached nobody (ESRCH/EPERM —
                        // including the guarded fake-pid shapes), the
                        // leader itself must still fall.
                        if (!$swept) {
                            $child->kill($sigkill);
                        }
                    }
                }
            } finally {
                // The child is gone (or SIGKILLed) from here on. Forgetting
                // its pid keeps signalChild() a no-op once no child is
                // running, as ChildSpawner promises: a stale pid outlives
                // the reap and the kernel recycles it, so a late
                // RFC 4254 signal would otherwise hit an unrelated process.
                $this->childPid = null;
                // The keepalive beat belonged to this pumped run.
                // Dropping it here (audit sugar-ssh-server-016) means a
                // transport instance reused for a later run never wires
                // a stale onIdle closure — with the clear absent, a run
                // over the SAME instance whose stack carries no
                // Keepalive would still fire the previous registration
                // (and its captured references) into the new child.
                // Server::serve() re-arms at every entry, so the
                // shipped cadence is unchanged.
                $this->keepaliveCallback = null;
                if (!$master->isClosed()) {
                    $master->close();
                }
            }
        }

        $status = $pumpResult >= 0
            ? $pumpResult
            : ($child !== null ? $child->wait() : 0);

        // Hand the settled code to the session, which is what the stack
        // walk reads on its way out. Same object, one hop away from the
        // exit() in the server script.
        $session->recordExitStatus($status);

        // Bounded exit (audit sugar-ssh-server-017): the pump-end
        // snapshot above decides, so the teardown ladder's own seconds
        // cannot widen an abort that the run did not earn — a child
        // that exited on its own just before expiry keeps its code.
        if ($abort !== null) {
            throw $abort;
        }

        return $status;
    }

    /**
     * Microseconds from now until the context's chain deadline, clamped
     * to at least 1 (PumpOptions rejects a non-positive bound and "fire
     * on the next loop head" is the truth for an expired one).
     *
     * Pure clock math — `$nowSeconds` is injectable for tests. A context
     * with no deadline returns null: the pump keeps its unbounded
     * contract, and cancellation rides the idle-tick guard instead.
     *
     * @param float|null $nowSeconds seconds since the epoch; null reads the wall clock
     */
    private static function pumpBoundUs(?Context $ctx, ?float $nowSeconds = null): ?int
    {
        $deadline = $ctx?->deadline();
        if ($deadline === null) {
            return null;
        }
        $now = $nowSeconds ?? \microtime(true);
        $remaining = (float) $deadline->format('U.u') - $now;

        return (int) max(1, floor($remaining * 1_000_000));
    }

    /**
     * The pump's onIdle closure: a cooperative cancel poll in front of
     * the keepalive beat (audit sugar-ssh-server-017).
     *
     * The guard runs on every idle tick (~one select timeout apart).
     * When the context is done it asks the child to leave — exactly the
     * SIGHUP sshd sends at session end — once, then stays quiet: the
     * child's own exit (or the deadline bound, when one was armed) ends
     * the pump through its loop-head check, and the teardown ladder
     * still owns any escalation. The beat is short-circuited on a dying
     * session: a NUL into the child's stdin is noise.
     *
     * The arm deliberately is NOT a mid-run `$master->close()`: the
     * pump restores blocking mode on the stream handle it captured at
     * entry (PosixPump's finally), and a closed resource turns that
     * teardown into a TypeError that would replace the context's own
     * error. That cross-lib constraint was discovered by this row's
     * tests; until sugar-pty can survive a mid-pump hangup, the
     * child-side idiom is the honest one.
     *
     * Returns null when there is nothing to poll, keeping the pump's
     * zero-overhead path for the plain unbounded run.
     *
     * @param callable():void $onContextDone fires once, on the first
     *                          idle tick that sees the context dead
     */
    private function idleTickGuard(?Context $ctx, ?\Closure $beat, callable $onContextDone): ?\Closure
    {
        if ($ctx === null && $beat === null) {
            return null;
        }
        $hangupSent = false;

        return function () use (&$hangupSent, $ctx, $beat, $onContextDone): void {
            if ($ctx !== null && $ctx->done()) {
                if (!$hangupSent) {
                    $hangupSent = true;
                    $onContextDone();
                }

                return;
            }
            if ($beat !== null) {
                $beat();
            }
        };
    }

    /**
     * Default size-provider implementation — queries TIOCGWINSZ on
     * fd 0 (the supervisor's stdin) which is sshd's slave PTY in a
     * ForceCommand deployment.
     *
     * Falls back to the spawn-time `[$cols, $rows]` when stdin
     * isn't a tty (test environments, non-pty pipes) — TIOCGWINSZ
     * returns ENOTTY there and we don't want to surface that as a
     * "size went to 0" event.
     *
     * The broad `\Throwable` catch is not sloppiness (2026-11 audit). The
     * ioctl request code is resolved per platform, and a libc binding that
     * cannot answer it raises rather than returns — and it would raise on
     * every idle tick, not once at boot. A resize probe is a bonus on top of
     * the spawn-time size, so this path is deliberately quiet and returns the
     * size we already had. A caller who wants failures loud owns the question
     * through {@see withSizeProvider()} and may throw from inside it.
     *
     * @param resource $stdin
     * @return array{cols:int, rows:int}
     */
    private function readHostStdinSize($stdin, int $fallbackCols, int $fallbackRows): array
    {
        if (!\is_resource($stdin)) {
            return ['cols' => $fallbackCols, 'rows' => $fallbackRows];
        }

        if ($stdin !== \STDIN) {
            return ['cols' => $fallbackCols, 'rows' => $fallbackRows];
        }

        try {
            $libc = Libc::lib();
            $ws = SizeIoctl::emptyBuffer();
            $rc = $libc->ioctl(0, SizeIoctl::getRequest(), $ws);
            if ($rc !== 0) {
                return ['cols' => $fallbackCols, 'rows' => $fallbackRows];
            }
            $unpacked = SizeIoctl::unpack($ws);
            $cols = $unpacked['cols'] > 0 ? $unpacked['cols'] : $fallbackCols;
            $rows = $unpacked['rows'] > 0 ? $unpacked['rows'] : $fallbackRows;
            return ['cols' => $cols, 'rows' => $rows];
        } catch (\Throwable) {
            return ['cols' => $fallbackCols, 'rows' => $fallbackRows];
        }
    }

    public function signalChild(int $signal): void
    {
        if ($this->childPid === null) {
            return;
        }
        // Libc::kill() falls back to libc kill(2) over FFI when ext-posix
        // is absent; a function_exists('posix_kill') guard here silently
        // dropped every forwarded RFC 4254 signal on such hosts.
        Libc::kill($this->childPid, $signal);
    }
}
