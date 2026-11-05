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
final class InProcessTransport implements Transport, ChildSpawner
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
     * @var (callable(): array{cols:int, rows:int})|null
     */
    private $sizeProvider = null;

    /**
     * Optional callback invoked in the pump loop whenever
     * `stream_select` times out (no I/O ready). Middleware such as
     * Keepalive use this hook to send periodic heartbeat bytes
     * through the PTY master without busy-polling.
     *
     * @var callable|null
     */
    private $keepaliveCallback = null;

    /** PID of the live child, used for signal forwarding; null once runChild() tears it down. */
    private ?int $childPid = null;

    /**
    /**
     * The active master for the current session. Stored here so the
     * keepalive callback (registered by middleware via
     * {@see setKeepaliveCallback} at stack-walk time) can write
     * heartbeat bytes when it fires inside the pump loop.
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
     * @param callable(): array{cols:int, rows:int} $provider
     */
    public function withSizeProvider(callable $provider): self
    {
        $clone = clone $this;
        $clone->sizeProvider = $provider;
        return $clone;
    }

    /**
     * Register a callback to be invoked in the pump loop whenever
     * `stream_select` reports no ready streams (idle timeout).
     * Keepalive middleware uses this to send periodic heartbeat
     * bytes through the PTY master.
     *
     * The callback receives no arguments; it is responsible for
     * writing keepalive bytes to the PTY when appropriate.
     *
     * @param callable(): void $callback
     */
    public function setKeepaliveCallback(callable $callback): void
    {
        $this->keepaliveCallback = $callback;
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
     */
    private function channelHandler(Session $session): ChannelHandler
    {
        return $this->channelHandler ?? new DefaultChannelHandler($this, $session);
    }

    /**
     * Returns the active master PTY for the current session. Valid
     * only while a session is actively being pumped — callers must
     * not store the reference across pump-loop boundaries.
     *
     * @throws \RuntimeException if called outside of pump loop
     */
    public function pty(): MasterPty
    {
        if ($this->master === null) {
            throw new \RuntimeException('pty() called outside of active pump loop');
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

        // Protocol metadata, best-effort from the environment (audit MAJOR 3
        // and MAJOR 21). This transport performs no SSH handshake and speaks
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
     * and hold the socket open.
     *
     * The two streams are not treated alike, and the asymmetry is the
     * contract (audit K9): STDIN and the PTY master are flipped to
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
     * @throws PtyException             if the host can't allocate a
     *                                  PTY or pcntl is missing
     */
    public function runChild(
        Session $session,
        array $cmd,
        ?array $env = null,
        $stdin = null,
        $stdout = null,
    ): int {
        $stdin  ??= \STDIN;
        $stdout ??= \STDOUT;

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
                $onIdle = $this->keepaliveCallback !== null
                    ? \Closure::fromCallable($this->keepaliveCallback)
                    : null;
                $opts = PumpOptions::sshDefault()->withOnIdle($onIdle);

                $pumpResult = (new PosixPump())->run($master, $stdin, $stdout, $child, $opts);
            } finally {
                $this->master = null;
                if ($sigwinchAttached && \defined('SIGWINCH')) {
                    SignalForwarder::reset(\SIGWINCH);
                }
                // Give the caller's stream back the way we found it
                // (audit MINOR 3). We flipped STDIN non-blocking because the
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
                        $child->kill(\defined('SIGKILL') ? \SIGKILL : 9);
                    }
                }
            } finally {
                // The child is gone (or SIGKILLed) from here on. Forgetting
                // its pid keeps signalChild() a no-op once no child is
                // running, as ChildSpawner promises: a stale pid outlives
                // the reap and the kernel recycles it, so a late
                // RFC 4254 signal would otherwise hit an unrelated process.
                $this->childPid = null;
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

        return $status;
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
     * The broad `\Throwable` catch is not sloppiness (audit MINOR 12). The
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
