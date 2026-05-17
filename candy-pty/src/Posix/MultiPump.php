<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

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
 * Wired in plan step P6.3 ("MultiPump multiplexer"). Designed so a
 * stalled child never starves the others — the selector wakes for any
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
     * The master is flipped into non-blocking mode so reads never block
     * other sessions.
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
        \stream_set_blocking($master->stream(), false);
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
     * @return array<int, int|null>
     */
    public function run(): array
    {
        while (!$this->allDone()) {
            $this->tick();
        }

        $exits = [];
        foreach ($this->sessions as $id => $session) {
            $exits[$id] = $session->child?->exitCode();
        }
        return $exits;
    }

    /**
     * Single iteration of the multiplexer: select across every live
     * master, drain bytes from those that are ready, fire idle
     * callbacks otherwise. Returns the number of ready masters that
     * were drained on this tick (0 on idle / no live sessions).
     */
    public function tick(): int
    {
        $live = [];
        $now = \microtime(true);
        foreach ($this->sessions as $session) {
            if ($session->done) {
                continue;
            }

            // Watermark the first moment we noticed the child exited
            // so we can give the master a finite flush window. Without
            // this, an `echo hello` that finishes between `add()` and
            // the first `tick()` would be marked done before its bytes
            // are drained from the master buffer.
            if ($session->child !== null
                && $session->child->exited()
                && $session->childExitedAt === null
            ) {
                $session->childExitedAt = $now;
            }

            // Past the flush deadline → child long gone and the master
            // has been quiet — mark done so the loop can finish.
            if ($session->childExitedAt !== null
                && $now - $session->childExitedAt > $session->opts->flushDeadlineSec
            ) {
                $session->done = true;
                continue;
            }

            $live[] = $session;
        }
        if ($live === []) {
            return 0;
        }

        $r = [];
        $timeoutUs = self::DEFAULT_IDLE_TIMEOUT_US;
        foreach ($live as $session) {
            $r[] = $session->master->stream();
            if ($session->opts->selectTimeoutUs < $timeoutUs) {
                $timeoutUs = $session->opts->selectTimeoutUs;
            }
        }
        $w = null;
        $e = null;
        $ready = @\stream_select($r, $w, $e, 0, $timeoutUs);

        if ($ready === false) {
            if (\function_exists('pcntl_signal_dispatch')) {
                @\pcntl_signal_dispatch();
            }
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
        foreach ($live as $session) {
            $sessionStream = $session->master->stream();
            $isReady = false;
            foreach ($r as $readyStream) {
                if ($readyStream === $sessionStream) {
                    $isReady = true;
                    break;
                }
            }
            if (!$isReady) {
                continue;
            }
            $this->drainSession($session);
            $drained++;
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
            if (!$session->done) {
                return false;
            }
        }
        return true;
    }

    private function drainSession(MultiPumpSession $session): void
    {
        $bytes = $session->master->read($session->opts->chunkBytes);
        if ($bytes === null) {
            return;
        }
        if ($bytes === '') {
            // Master EOF — child is gone and there's nothing left to
            // tee. Mark done immediately; no point waiting for the
            // flush window when the kernel already returned 0.
            $session->done = true;
            return;
        }
        if ($session->opts->recorder !== null) {
            $session->opts->recorder->recordOutput($bytes);
        }
        @\fwrite($session->stdoutSink, $bytes);
    }
}

/**
 * Internal session record. Carries everything {@see MultiPump} needs
 * to demux a single master → stdoutSink pipe; not part of the public
 * API.
 */
final class MultiPumpSession
{
    public bool $done = false;

    /**
     * First wall-clock timestamp at which `tick()` observed the child
     * as exited. Used to bound the post-child master drain to
     * `opts->flushDeadlineSec`. Null until that moment.
     */
    public ?float $childExitedAt = null;

    /**
     * @param resource $stdoutSink
     */
    public function __construct(
        public readonly int $id,
        public readonly MasterPty $master,
        public readonly mixed $stdoutSink,
        public readonly ?Child $child,
        public readonly PumpOptions $opts,
    ) {}
}
