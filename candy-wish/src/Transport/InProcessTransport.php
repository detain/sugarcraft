<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use SugarCraft\Pty\Libc;
use SugarCraft\Pty\Pty;
use SugarCraft\Pty\PtyException;
use SugarCraft\Pty\SignalForwarder;
use SugarCraft\Pty\SizeIoctl;
use SugarCraft\Wish\Lang;
use SugarCraft\Wish\Middleware;
use SugarCraft\Wish\Session;
use SugarCraft\Wish\Transport;

/**
 * Default transport — allocates a `candy-pty` master/slave pair,
 * spawns the user's cmd as a subprocess with
 * `controllingTerminal: true`, and pumps bytes between the
 * supervisor's STDIN/STDOUT and the PTY master.
 *
 * The middleware stack walks like {@see HostSshdTransport} does
 * (Logger, RateLimit, Auth, etc. work unchanged). Spawning a child
 * is delegated to terminal middleware (PR3's `Spawn`) which calls
 * back into {@see runChild()} with the cmd produced from the
 * Session.
 *
 * @see plans/plan-candy-wish-pty.md
 */
final class InProcessTransport implements Transport, ChildSpawner
{
    /** Bytes per `fread` / `fwrite` chunk in the pump loop. */
    private const PUMP_CHUNK = 4096;

    /** `stream_select` poll interval — 50 ms keeps CPU near zero
     *  on idle while staying snappy under typing-rate I/O. */
    private const PUMP_TIMEOUT_USEC = 50_000;

    /** Final-flush window after the pump loop exits, before
     *  closing the PTY. Drains any tail bytes the kernel buffered
     *  on the master after the child exited or stdin EOF'd. */
    private const FLUSH_DEADLINE_SEC = 0.5;

    /** Grace window after STDIN EOF for the child to notice
     *  (via VEOF / SIGHUP) and exit on its own before we force-
     *  close the PTY (which delivers SIGHUP via lost-ctty). 300 ms
     *  is comfortable for shells / cat / well-behaved children;
     *  daemon-style children (sleep, non-tty-aware loops) get
     *  force-closed at the deadline. */
    private const STDIN_EOF_GRACE_SEC = 0.3;

    /** VEOF char (Ctrl+D, 0x04). In cooked-mode PTY this signals
     *  EOF on the slave's stdin to the inner child. */
    private const VEOF = "\x04";

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

    public function run(Session $session, array $stack): void
    {
        // Duck-typed transport injection: any middleware that exposes
        // a `setTransport(ChildSpawner)` method gets us handed in
        // before the dispatch loop runs. Currently the only consumer
        // is the {@see \SugarCraft\Wish\Middleware\Spawn} terminal
        // middleware (PR3), but the hook is open-ended for future
        // PTY-aware middleware (recorder taps, observability tees).
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
     * `$stdin` / `$stdout` are accepted as resources for testability
     * — a `proc_open` invocation's pipe halves let unit tests drive
     * the pump loop without a real SSH connection. Production
     * callers pass `null` and inherit `STDIN` / `STDOUT` (the slave
     * side of sshd's PTY in a ForceCommand deployment).
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

        $pty = Pty::open();

        try {
            $pty->setBlocking(false);
            @\stream_set_blocking($stdin, false);

            $child = $pty->spawn(
                $cmd,
                $env,
                $cols,
                $rows,
                controllingTerminal: true,
            );

            // SIGWINCH forwarding — when sshd resizes the supervisor's
            // PTY, the kernel sends SIGWINCH to us; the handler queries
            // the new dimensions via the size provider and propagates
            // them into the inner candy-pty via $pty->resize(). Caller
            // can override the provider via withSizeProvider() — useful
            // for tests that need to drive resize events without an
            // actual host PTY.
            $sigwinchAttached = false;
            if (SignalForwarder::pcntlReady() && \defined('SIGWINCH')) {
                $provider = $this->sizeProvider ?? fn (): array => $this->readHostStdinSize($stdin, $cols, $rows);
                $sigwinchAttached = SignalForwarder::attachSigwinch($pty, $provider);
            }

            try {
                $this->pump($pty, $child, $stdin, $stdout);

                // Drain any tail bytes the kernel buffered on the master
                // before we tear down. Best-effort — broken stdout is
                // silently ignored.
                $flushDeadline = \microtime(true) + self::FLUSH_DEADLINE_SEC;
                while (\microtime(true) < $flushDeadline) {
                    $tail = $pty->read(self::PUMP_CHUNK);
                    if ($tail === null || $tail === '') {
                        if ($child->exited()) {
                            break;
                        }
                        \usleep(20_000);
                        continue;
                    }
                    @\fwrite($stdout, $tail);
                }
            } finally {
                // Restore SIGWINCH default disposition before any
                // session-specific cleanup runs. Otherwise a stale
                // closure with this $pty captured would fire on the
                // next session's SIGWINCH and target a closed Pty.
                if ($sigwinchAttached && \defined('SIGWINCH')) {
                    SignalForwarder::reset(\SIGWINCH);
                }
            }
        } finally {
            // If the child is still running (pump exited because of
            // STDIN EOF / EPIPE), we have to terminate it explicitly:
            // closing the PTY master does NOT auto-deliver SIGHUP on
            // Linux. Send SIGHUP first (gentle, daemons handle it),
            // then SIGKILL after a brief grace if still alive.
            if (isset($child) && !$child->exited()) {
                if (\function_exists('posix_kill')) {
                    @\posix_kill($child->pid, \SIGHUP);
                    $killDeadline = \microtime(true) + 0.2;
                    while (\microtime(true) < $killDeadline) {
                        if ($child->exited()) {
                            break;
                        }
                        \usleep(20_000);
                    }
                    if (!$child->exited()) {
                        @\posix_kill($child->pid, \SIGKILL);
                    }
                }
            }
            if (isset($pty) && !$pty->isClosed()) {
                $pty->close();
            }
        }

        return isset($child) ? $child->wait() : 0;
    }

    /**
     * Inner pump loop. Exits when:
     *  - child exits,
     *  - STDOUT hits EPIPE (peer closed read end),
     *  - STDIN reached EOF AND the post-EOF grace window expired
     *    (child got VEOF + had {@see STDIN_EOF_GRACE_SEC} to notice;
     *    if it's still running we let runChild's PTY close deliver
     *    SIGHUP via lost-ctty).
     *
     * @param resource $stdin
     * @param resource $stdout
     */
    private function pump(Pty $pty, \SugarCraft\Pty\Child $child, $stdin, $stdout): void
    {
        $masterStream = $pty->stream();
        $stdinClosed = false;
        $stdinClosedDeadline = 0.0;

        while (true) {
            if ($child->exited()) {
                return;
            }

            if ($stdinClosed && \microtime(true) > $stdinClosedDeadline) {
                return; // grace expired; runChild's $pty->close() force-quits the child
            }

            $r = $stdinClosed ? [$masterStream] : [$stdin, $masterStream];
            $w = null;
            $e = null;
            $ready = @\stream_select($r, $w, $e, 0, self::PUMP_TIMEOUT_USEC);

            if ($ready === false) {
                if (\function_exists('pcntl_signal_dispatch')) {
                    @\pcntl_signal_dispatch();
                    continue;
                }
                return;
            }

            if ($ready === 0) {
                continue;
            }

            foreach ($r as $stream) {
                if ($stream === $stdin && !$stdinClosed) {
                    if (!$this->pumpStdinToMaster($stdin, $pty)) {
                        // STDIN EOF — flag and send VEOF so the inner
                        // child sees stdin EOF (cooked-mode kernel
                        // translates 0x04 to read() returning 0).
                        // Well-behaved shells / cat / read-loops exit
                        // on this; daemons (sleep, etc.) get killed
                        // by the grace-deadline + PTY close path.
                        $stdinClosed = true;
                        $stdinClosedDeadline = \microtime(true) + self::STDIN_EOF_GRACE_SEC;
                        @\fwrite($masterStream, self::VEOF);
                    }
                } elseif ($stream === $masterStream) {
                    if (!$this->pumpMasterToStdout($pty, $stdout)) {
                        return; // STDOUT EPIPE — peer closed.
                    }
                }
            }
        }
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

        // We need the underlying integer fd, not the PHP stream.
        // PHP exposes the fd for stdin/stdout/stderr as constants
        // STDIN/STDOUT/STDERR, but for arbitrary streams there's no
        // public API. As a heuristic: if the resource is the global
        // STDIN, use fd 0 — anything else (proc_open pipes, sockets,
        // etc.) almost certainly isn't a tty and the fallback is the
        // right answer.
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
     * @param resource $stdin
     * @return bool false if STDIN reached EOF (caller should exit pump)
     */
    private function pumpStdinToMaster($stdin, Pty $pty): bool
    {
        $bytes = @\fread($stdin, self::PUMP_CHUNK);
        if ($bytes === false || $bytes === '') {
            if (\feof($stdin)) {
                return false;
            }
            return true;
        }
        $pty->write($bytes);
        return true;
    }

    /**
     * @param resource $stdout
     * @return bool false if STDOUT hit EPIPE (caller should exit pump)
     */
    private function pumpMasterToStdout(Pty $pty, $stdout): bool
    {
        $bytes = $pty->read(self::PUMP_CHUNK);
        if ($bytes === null || $bytes === '') {
            return true;
        }
        $written = @\fwrite($stdout, $bytes);
        return $written !== false;
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
