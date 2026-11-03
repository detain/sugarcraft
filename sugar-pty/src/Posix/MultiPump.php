<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\PumpOptions;

/**
 * Supervises N master-PTY → stdout pumps from a single `stream_select`
 * so a host process (split-pane viewer, tmux-style supervisor, parallel
 * test harness) can demux output from many children without spinning a
 * pump-per-thread or polling each master in turn.
 *
 * Each session is master-output-only: bytes flow `master → stdoutSink`.
 * Stdin → master is the caller's responsibility (it knows which "pane"
 * has focus). That keeps the multiplexer small enough to reason about
 * and matches the existing {@see PosixPump} stdin model where the host
 * decides routing.
 *
 * @see \SugarCraft\Pty\Posix\MultiPump    — the multiplexer
 * @see examples/multi-pump.php            — live demo (two shells, tee'd to stdout)
 *
 * Designed so a stalled child never starves the others — the selector wakes for any
 * ready master and `tick()` only drains the ready set.
 */
final class MultiPump
{
    /** Default idle timeout per `stream_select` round in microseconds. */
    public const DEFAULT_IDLE_TIMEOUT_US = 50_000;

    /** @var array<int, MultiPumpSession> */
    private array $sessions = [];

    private int $nextId = 0;

    /**
     * Register a master / stdout-sink pair (and optional child handle)
     * for multiplexing. Returns a numeric session id that callers use
     * to {@see remove()} or look up the exit code in {@see run()}'s
     * return value.
     *
     * The master's blocking mode is deliberately NOT touched: every read
     * happens only after select (or the finite default read timeout) has
     * established there are bytes, so no flip is needed and the caller's
     * stream configuration survives the multiplexer.
     *
     * @param resource $stdoutSink  open writable stream — STDOUT, a
     *                              per-pane buffer, a network socket.
     */
    public function add(
        MasterPty $master,
        $stdoutSink,
        ?Child $child = null,
        ?PumpOptions $opts = null,
    ): int {
        if (!\is_resource($stdoutSink)) {
            throw new \InvalidArgumentException('MultiPump::add requires a resource $stdoutSink');
        }
        $id = $this->nextId++;
        $this->sessions[$id] = new MultiPumpSession(
            id: $id,
            master: $master,
            stdoutSink: $stdoutSink,
            child: $child,
            opts: $opts ?? new PumpOptions(),
        );
        return $id;
    }

    /**
     * Drop a session from the multiplexer. Does NOT close the master —
     * the caller owns its lifecycle. Returns false when the id is not
     * registered (idempotent, safe in finally blocks).
     */
    public function remove(int $id): bool
    {
        if (!isset($this->sessions[$id])) {
            return false;
        }
        unset($this->sessions[$id]);
        return true;
    }

    /** Number of sessions currently registered. */
    public function size(): int
    {
        return \count($this->sessions);
    }

    /** Whether session $id is registered. */
    public function has(int $id): bool
    {
        return isset($this->sessions[$id]);
    }

    /**
     * Run the multiplexer until every registered session is done
     * (child exited or master EOF'd). Returns a map of session-id →
     * exit code (null when there was no child or the child had not
     * captured an exit code at teardown).
     *
     * ## Caller-bounding contract (E717)
     *
     * Carries no internal deadline; callers MUST bound. With the default
     * `$pumpDeadlineUs = null` the loop only finishes once every session
     * reaches its done state, so one live-but-silent child pins the whole
     * multiplexer forever. Pass `$pumpDeadlineUs` (microseconds from this
     * call) for an opt-in bound: on expiry run() returns the partial exit
     * map WITHOUT marking anything done — sessions stay live and a later
     * run() call resumes draining them. Nothing is killed.
     *
     * @param int<1, max>|null $pumpDeadlineUs optional wall-clock bound for
     *                                         this drive of the loop, in
     *                                         microseconds from call time
     * @return array<int, int|null>
     */
    public function run(?int $pumpDeadlineUs = null): array
    {
        if ($pumpDeadlineUs !== null && $pumpDeadlineUs <= 0) {
            throw new \InvalidArgumentException(
                "pumpDeadlineUs must be > 0 or null (unbounded); got {$pumpDeadlineUs}"
            );
        }
        $deadlineAt = $pumpDeadlineUs === null
            ? null
            : \microtime(true) + $pumpDeadlineUs / 1_000_000;

        while (!$this->allDone()) {
            if ($deadlineAt !== null && \microtime(true) >= $deadlineAt) {
                break;
            }
            $this->tick();
        }

        return $this->exitMap();
    }

    /**
     * Non-blocking variant of {@see run()}: drives every registered
     * session through the React event loop (one {@see ReactPump} per
     * session, master → stdoutSink, no stdin — same output-only model
     * as the sync multiplexer) and returns a promise that resolves with
     * the same session-id → exit-code map once every child has exited /
     * every master has EOF'd.
     *
     * Sessions are marked done as their pumps resolve, so `allDone()` /
     * `has()` bookkeeping stays consistent with the sync path. Sessions
     * already done when runAsync() is called are skipped (their exit
     * codes still appear in the resolved map).

     *
     * @param LoopInterface|null $loop defaults to the global Loop::get()
     * @return PromiseInterface<array<int, int|null>>
     */
    public function runAsync(?LoopInterface $loop = null): PromiseInterface
    {
        // $loop stays null unless the caller injected one — ReactPump
        // resolves Loop::get() lazily in start(), so an all-done/empty
        // multiplexer never instantiates the global loop.
        $promises = [];
        foreach ($this->sessions as $session) {
            if ($session->isDone()) {
                continue;
            }
            $pump = new ReactPump($loop);
            $promises[] = $pump
                ->start(
                    $session->master,
                    stdoutStream: $session->stdoutSink,
                    child: $session->child,
                    opts: $session->opts,
                )
                ->then(static function (int $exit) use ($session): int {
                    $session->markDone();
                    return $exit;
                });
        }

        if ($promises === []) {
            return \React\Promise\resolve($this->exitMap());
        }

        return \React\Promise\all($promises)->then(fn (): array => $this->exitMap());
    }

    /** @return array<int, int|null> session-id → exit code (null when no child / not captured) */
    private function exitMap(): array
    {
        $exits = [];
        foreach ($this->sessions as $id => $session) {
            $exits[$id] = $session->child?->exitCode();
        }
        return $exits;
    }

    /**
     * Single iteration of the multiplexer: select across every live
     * master (and every owed sink), drain bytes from those that are
     * ready, fire idle callbacks otherwise. Returns the number of ready
     * masters that were drained on this tick (0 on idle / no live
     * sessions).
     *
     * Callback reach of the SYNC path: only {@see PumpOptions::$keepalive}
     * fires on idle ticks here. onIdle / onSigwinch / onChildExit are
     * PosixPump and ReactPump hooks — a multiplexed caller wanting them
     * drives runAsync() (one ReactPump per session) or observes children
     * around tick() itself.
     */
    public function tick(): int
    {
        $live = [];
        $now = \microtime(true);
        foreach ($this->sessions as $session) {
            if ($session->isDone()) {
                continue;
            }

            // Watermark the first moment we noticed the child exited
            // so we can give the master a finite flush window. Without
            // this, an `echo hello` that finishes between `add()` and
            // the first `tick()` would be marked done before its bytes
            // are drained from the master buffer.
            if ($session->child !== null
                && $session->child->exited()
                && $session->childExitedAt() === null
            ) {
                $session->markChildExitedAt($now);
            }

            // Past the flush deadline → one LAST finite read so bytes that
            // landed inside the final window are not silently discarded,
            // then mark done so the loop can finish.
            if ($session->childExitedAt() !== null
                && $now - $session->childExitedAt() > $session->opts->flushDeadlineSec
            ) {
                if ($session->pendingSink !== '') {
                    @\fwrite($session->stdoutSink, $session->pendingSink);
                    $session->pendingSink = '';
                }
                $tail = $session->master->read($session->opts->chunkBytes, 0.01);
                if ($tail !== null && $tail !== '') {
                    if ($session->opts->recorder !== null) {
                        $session->opts->recorder->recordOutput($tail);
                    }
                    @\fwrite($session->stdoutSink, $tail);
                }
                $session->markDone();
                continue;
            }

            $live[] = $session;
        }
        if ($live === []) {
            return 0;
        }

        $r = [];
        $w = [];
        $masterByFd = [];
        $sinkByFd = [];
        $timeoutUs = self::DEFAULT_IDLE_TIMEOUT_US;
        foreach ($live as $session) {
            if ($session->opts->selectTimeoutUs < $timeoutUs) {
                $timeoutUs = $session->opts->selectTimeoutUs;
            }
            $stream = $session->master->stream();
            $masterByFd[(int) $stream] = $session;
            if ($session->pendingSink === '') {
                $r[] = $stream;
            } else {
                // Back-pressure: this session owes bytes to its sink; watch
                // writability and hold off reading its master.
                $w[] = $session->stdoutSink;
                $sinkByFd[(int) $session->stdoutSink] = $session;
            }
        }
        // retryOnEintr narrows the by-reference sets to the READY subset.
        $wArg = $w === [] ? null : $w;
        $e = null;
        $ready = PosixMasterPty::retryOnEintr($r, $wArg, $e, 0, $timeoutUs);

        if ($ready === false) {
            return 0;
        }
        if ($ready === 0) {
            foreach ($live as $session) {
                if ($session->opts->keepalive !== null) {
                    ($session->opts->keepalive)();
                }
            }
            return 0;
        }

        $drained = 0;
        foreach ($r as $readyStream) {
            $session = $masterByFd[(int) $readyStream] ?? null;
            if ($session !== null) {
                $this->drainSession($session);
                $drained++;
            }
        }
        foreach ($wArg ?? [] as $readySink) {
            $session = $sinkByFd[(int) $readySink] ?? null;
            if ($session !== null && $session->pendingSink !== '') {
                $written = @\fwrite($session->stdoutSink, $session->pendingSink);
                if ($written === false) {
                    $session->pendingSink = '';
                    $session->markDone(); // sink reader is gone (M7 teardown)
                    continue;
                }
                if ($written > 0) {
                    $session->pendingSink = (string) \substr($session->pendingSink, $written);
                }
            }
        }
        return $drained;
    }

    /**
     * Returns true when every session has reached its `done` flag.
     * Sessions whose child has exited stay live until the flush
     * deadline lapses inside {@see tick()} — that's where the
     * post-child master drain happens.
     */
    public function allDone(): bool
    {
        foreach ($this->sessions as $session) {
            if (!$session->isDone()) {
                return false;
            }
        }
        return true;
    }

    private function drainSession(MultiPumpSession $session): void
    {
        if ($session->pendingSink !== '') {
            return; // handled by the writability arm
        }
        $bytes = $session->master->read($session->opts->chunkBytes);
        if ($bytes === null) {
            return;
        }
        if ($bytes === '') {
            // Master EOF — child is gone and there's nothing left to
            // tee. Mark done immediately; no point waiting for the
            // flush window when the kernel already returned 0.
            $session->markDone();
            return;
        }
        if ($session->opts->recorder !== null) {
            $session->opts->recorder->recordOutput($bytes);
        }
        $written = @\fwrite($session->stdoutSink, $bytes);
        if ($written === false) {
            // The sink's reader is gone (EPIPE): tear this session down
            // instead of pumping the next session's bytes into nowhere.
            $session->markDone();
            return;
        }
        if ($written < \strlen($bytes)) {
            $session->pendingSink = (string) \substr($bytes, $written);
        }
    }
}
