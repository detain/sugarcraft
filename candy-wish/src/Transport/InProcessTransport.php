<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\PtySystemFactory;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Pty\SignalForwarder;
use SugarCraft\Pty\SizeIoctl;
use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport;

/**
 * Default transport — allocates a `candy-pty` master/slave pair via
 * the injected (or factory-resolved) {@see PtySystem}, spawns the
 * user's cmd as a subprocess with `controllingTerminal: true`, and
 * pumps bytes between the supervisor's STDIN/STDOUT and the PTY
 * master via {@see PosixPump}.
 *
 * The middleware stack walks like {@see HostSshdTransport} does
 * (Logger, RateLimit, Auth, etc. work unchanged). Spawning a child
 * is delegated to terminal middleware (PR3's `Spawn`) which calls
 * back into {@see runChild()} with the cmd produced from the
 * Session.
 *
 * @see plans/sugarcraft-is-a-mono-logical-twilight.md (P2.5, P4.2)
 */
final class InProcessTransport implements Transport, ChildSpawner
{
    /**
     * PTY backend used by `runChild()`. Injected for testability — a
     * stub can satisfy `PtySystem` without touching libc / FFI.
     * Defaults to {@see PtySystemFactory::default()} on first use.
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

    /**
     * The active master for the current session. Stored here so the
     * keepalive callback (registered by middleware via
     * {@see setKeepaliveCallback} at stack-walk time) can write
     * heartbeat bytes when it fires inside the pump loop.
     */
    private ?MasterPty $master = null;

    public function __construct(?PtySystem $system = null)
    {
        $this->system = $system ?? PtySystemFactory::default();
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
     * Returns the active master PTY for the current session. Valid
     * only while a session is actively being pumped — callers must
     * not store the reference across pump-loop boundaries.
     *
     * @throws \RuntimeException if called outside of pump loop
     */
    public function getPty(): MasterPty
    {
        if ($this->master === null) {
            throw new \RuntimeException('getPty() called outside of active pump loop');
        }
        return $this->master;
    }

    public function run(Session $session, array $stack): void
    {
        foreach ($stack as $mw) {
            if (\method_exists($mw, 'setTransport')) {
                $mw->setTransport($this);
            }
        }
        $this->dispatch($session, $stack, 0);
    }

    /**
     * Spawn `$cmd` as a subprocess inside a fresh PTY and pump bytes
     * between the caller's STDIN/STDOUT (or the supervisor's process
     * stdio if those are null) and the PTY master until the child
     * exits or one of the stdio streams hits EOF.
     *
     * Returns the child's exit code. Tears down the PTY before
     * returning regardless of which exit path fires; closing the
     * master signals SIGHUP to the child via lost-ctty so children
     * that don't react to STDIN EOF (sleep, daemons) still
     * terminate when the SSH client disconnects.
     *
     * @param list<string>              $cmd  argv passed to candy-pty
     * @param array<string,string>|null $env
     * @param resource|null             $stdin   defaults to STDIN
     * @param resource|null             $stdout  defaults to STDOUT
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

        // Fall back to a sensible 80×24 if the Session reports 0×0
        // (caveat 5 in the plan — ForceCommand deployments often
        // arrive without COLUMNS / LINES set).
        $cols = $session->cols > 0 ? $session->cols : 80;
        $rows = $session->rows > 0 ? $session->rows : 24;

        $pair = $this->system->open($cols, $rows);
        $master = $pair->master();
        $slave = $pair->slave();
        $child = null;
        $pumpResult = -1;

        try {
            \stream_set_blocking($master->stream(), false);
            @\stream_set_blocking($stdin, false);

            $child = $slave->spawn(
                $cmd,
                $env,
                $cols,
                $rows,
                controllingTerminal: true,
            );

            // SIGWINCH forwarding — when sshd resizes the supervisor's
            // PTY, the kernel sends SIGWINCH to us; the handler queries
            // the new dimensions via the size provider and propagates
            // them into the inner candy-pty via $master->resize().
            $sigwinchAttached = false;
            if (SignalForwarder::pcntlReady() && \defined('SIGWINCH')) {
                $provider = $this->sizeProvider ?? fn (): array => $this->readHostStdinSize($stdin, $cols, $rows);
                $sigwinchAttached = SignalForwarder::attachSigwinch($master, $provider);
            }

            try {
                $this->master = $master;
                $keepalive = $this->keepaliveCallback !== null
                    ? \Closure::fromCallable($this->keepaliveCallback)
                    : null;
                $opts = new PumpOptions(keepalive: $keepalive);

                $pumpResult = (new PosixPump())->run($master, $stdin, $stdout, $child, $opts);
            } finally {
                $this->master = null;
                if ($sigwinchAttached && \defined('SIGWINCH')) {
                    SignalForwarder::reset(\SIGWINCH);
                }
            }
        } finally {
            // If the child is still running (pump exited because of
            // STDIN EOF / EPIPE), terminate it explicitly: closing the
            // PTY master does NOT auto-deliver SIGHUP on Linux. Send
            // SIGHUP first (gentle, daemons handle it), then SIGKILL
            // after a brief grace if still alive.
            if ($child !== null && !$child->exited()) {
                if (\function_exists('posix_kill')) {
                    @\posix_kill($child->pid(), \SIGHUP);
                    $killDeadline = \microtime(true) + 0.2;
                    while (\microtime(true) < $killDeadline) {
                        if ($child->exited()) {
                            break;
                        }
                        \usleep(20_000);
                    }
                    if (!$child->exited()) {
                        @\posix_kill($child->pid(), \SIGKILL);
                    }
                }
            }
            if (!$master->isClosed()) {
                $master->close();
            }
        }

        if ($pumpResult >= 0) {
            return $pumpResult;
        }
        return $child !== null ? $child->wait() : 0;
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

    /**
     * @param list<Middleware> $stack
     */
    private function dispatch(Session $session, array $stack, int $idx): void
    {
        if ($idx >= \count($stack)) {
            return;
        }
        $next = function (Session $s) use ($stack, $idx): void {
            $this->dispatch($s, $stack, $idx + 1);
        };
        $stack[$idx]->handle($session, $next);
    }
}
