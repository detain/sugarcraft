<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Read a child's output pipes to EOF without letting one wedge another,
 * bounded by a deadline and a per-stream byte ceiling.
 *
 * Build one with {@see new()} and the `with*()` setters, then call
 * {@see pump()}:
 *
 *     $r = ChildPump::new([1 => $out, 2 => $err])
 *         ->withDeadline(Clock::monotonicSeconds() + 5.0)
 *         ->withCap(1_048_576)
 *         ->withOnChunk(static fn (int|string $slot, string $bytes) => $tail->write($bytes))
 *         ->pump();
 *
 * Every knob has a default: no deadline means ten seconds from the start of
 * {@see pump()}, and no cap means {@see DEFAULT_MAX_BYTES} per retained stream.
 * {@see ChildRun} is the usual entry point: it spawns the child and drives this
 * pump around it.
 *
 * The shape is forced by the kernel: reading stdout to EOF and then stderr
 * deadlocks as soon as the child fills a stderr pipe buffer (64 KiB on Linux)
 * nobody is draining. So the pump `stream_select()`s over every open pipe,
 * reads only what is ready from pipes switched non-blocking (a select that
 * wakes on a partial buffer cannot block inside the following fread()), and
 * wakes on a slice so the deadline is reachable while the child is silent.
 *
 * A `false` from select is RIDDEN OUT up to $selectRetries consecutive times:
 * PHP does not install handlers with SA_RESTART, so a signal while parked
 * (SIGWINCH under a TUI with async signals on) returns false/EINTR, and
 * treating that as end-of-output truncates what the child had not yet written.
 * A genuinely broken descriptor fails instantly and forever, hence finite.
 *
 * Volume policy: each retained stream keeps the HEAD up to the cap and counts
 * refused bytes ({@see DrainResult::droppedBytes()}); by default it keeps
 * READING (and discarding) so the child is never blocked on a full pipe.
 * `stopAtCap` ends the walk instead, for callers that kill the child anyway.
 * Slots in `discard` are drained to keep the child unblocked and never kept.
 *
 * Stdin: {@see withStdin()} writes a payload into a pipe the caller hands over
 * and closes that pipe when the payload is out (the child's EOF), interleaved
 * with the reads so a child that echoes its input cannot deadlock the pump.
 * An empty payload closes the pipe straight away. The Windows arm cannot do
 * this (see below), so a stdin feed there is refused with a LogicException
 * rather than parking on a blocking write.
 *
 * Heartbeat and reap: {@see withHeartbeat()} calls a hook at most every N
 * seconds while the walk is still open, so a TUI can keep its spinner alive
 * through a silent child. {@see withReap()} runs once after the walk has ended
 * (in a `finally`, so also after a throw), so the caller reaps the child only
 * after the last read.
 *
 * Windows arm: proc_open pipes there cannot be made non-blocking, select()
 * reports every pipe ready while empty, and the fread() that follows parks
 * until the child writes or exits, so the select loop above would lose its
 * deadline and deadlock on an empty stderr while the child fills stdout
 * ({@see WindowsPipes}). On that runtime the pump instead polls each pipe's
 * readable byte count and reads only that much. A Windows pipe cannot report
 * EOF without a read that might block, so the walk ends when the `running`
 * probe says the child exited and a pass finds nothing left: reason CLOSED,
 * meaning "the child is done and its output is read". Bytes a backgrounded
 * grandchild keeps writing after that are read for at most
 * {@see WindowsPipes::EXIT_DRAIN_GRACE_SECONDS}. Without a `running` probe
 * the arm cannot tell a finished child from a silent one, so it reads until
 * the deadline and reports EXPIRED — bounded, never wedged.
 *
 * Not here: the kill ladder and the reap of a child the pump did not spawn
 * ({@see \SugarCraft\Core\Util\Proc\BoundedShutdown}), and interactive relays,
 * which live in sugar-pty.
 */
final class ChildPump
{
    /** Bytes taken per ready pipe per iteration — one non-blocking read. */
    public const READ_BYTES = 8192;

    /** Consecutive failed selects ridden out before giving up. */
    public const SELECT_RETRIES = 128;

    /**
     * Seconds from the start of {@see pump()} when no deadline was set: the
     * timeout the candy-git-server BoundedCommand copy carries.
     */
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    /**
     * Per-retained-stream ceiling when no cap was set: 8 MiB, the cap the
     * candy-git-server BoundedCommand copy carries. The copies that had no
     * cap at all (candy-code CapturesProcessOutput) gain this one.
     */
    public const DEFAULT_MAX_BYTES = 8_388_608;

    /**
     * @param array<int|string, resource> $pipes  Readable pipes keyed by slot
     * @param null|float $deadline  Absolute instant on the clock's scale
     * @param int $maxBytes  Per-retained-stream ceiling (>= 0)
     * @param null|\Closure(): float $clock  Seconds; defaults to the monotonic clock
     * @param bool $stopAtCap  End the drain when a retained stream reaches the ceiling
     * @param list<int|string> $discard  Slots drained but never retained
     * @param float $sliceSeconds  Longest single select wait before the deadline is rechecked
     * @param int $selectRetries  Consecutive failed selects ridden out
     * @param null|\Closure(array &$read, mixed &$write, mixed &$except, int $sec, int $usec): (int|false) $select
     *        stream_select() seam: same contract, `$read` and `$write` narrowed in place.
     * @param null|\Closure(): bool $running  Whether the child is still running
     *        ({@see WindowsPipes::runningProbe()}). The Windows arm needs it to
     *        end before the deadline; the select arm ends on EOF and ignores it.
     * @param null|bool $pollPipes  Force the Windows (true) or select (false)
     *        arm; null picks by the real runtime ({@see WindowsPipes::runtimeNeedsIt()}).
     * @param null|\Closure(int|string, string): void $onChunk  Live feed, every chunk
     * @param mixed $stdinPipe  Writable pipe for the stdin feed, or null for none
     * @param null|string $stdinBytes  The stdin payload (with $stdinPipe)
     * @param null|float $heartbeatSeconds  Interval between heartbeats, or null
     * @param null|\Closure(): void $onBeat  The heartbeat hook
     * @param null|\Closure(): void $reap  Run once after the walk, even on a throw
     */
    private function __construct(
        private readonly array $pipes,
        private readonly ?float $deadline = null,
        private readonly int $maxBytes = self::DEFAULT_MAX_BYTES,
        private readonly ?\Closure $clock = null,
        private readonly bool $stopAtCap = false,
        private readonly array $discard = [],
        private readonly float $sliceSeconds = 0.1,
        private readonly int $selectRetries = self::SELECT_RETRIES,
        private readonly ?\Closure $select = null,
        private readonly ?\Closure $running = null,
        private readonly ?bool $pollPipes = null,
        private readonly ?\Closure $onChunk = null,
        private readonly mixed $stdinPipe = null,
        private readonly ?string $stdinBytes = null,
        private readonly ?float $heartbeatSeconds = null,
        private readonly ?\Closure $onBeat = null,
        private readonly ?\Closure $reap = null,
    ) {
    }

    /** @param array<int|string, resource> $pipes Readable pipes keyed by slot */
    public static function new(array $pipes): self
    {
        return new self($pipes);
    }

    /** Absolute instant on the clock's scale; default is ten seconds after pump() starts. */
    public function withDeadline(float $deadline): self
    {
        if (!is_finite($deadline)) {
            throw new \InvalidArgumentException('Deadline must be a finite instant');
        }

        return $this->mutate(['deadline' => $deadline]);
    }

    /** Per-retained-stream ceiling in bytes (>= 0). */
    public function withCap(int $maxBytes): self
    {
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Max bytes must be >= 0');
        }

        return $this->mutate(['maxBytes' => $maxBytes]);
    }

    /** End the walk when a retained stream reaches the cap (the caller kills the child anyway). */
    public function withStopAtCap(bool $stopAtCap = true): self
    {
        return $this->mutate(['stopAtCap' => $stopAtCap]);
    }

    /** @param list<int|string> $slots Slots drained but never retained */
    public function withDiscard(array $slots): self
    {
        return $this->mutate(['discard' => array_values($slots)]);
    }

    /** Longest single select wait before the deadline is rechecked. */
    public function withSlice(float $seconds): self
    {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Slice must be a positive finite number of seconds');
        }

        return $this->mutate(['sliceSeconds' => $seconds]);
    }

    public function withSelectRetries(int $retries): self
    {
        return $this->mutate(['selectRetries' => $retries]);
    }

    /** @param null|callable(): float $clock */
    public function withClock(?callable $clock): self
    {
        return $this->mutate(['clock' => $clock === null ? null : \Closure::fromCallable($clock)]);
    }

    /**
     * The stream_select() seam (POSIX arm); tests inject an EINTR-shaped false.
     *
     * @param null|callable(array &$read, mixed &$write, mixed &$except, int $sec, int $usec): (int|false) $select
     */
    public function withSelect(?callable $select): self
    {
        return $this->mutate(['select' => $select === null ? null : \Closure::fromCallable($select)]);
    }

    /**
     * Whether the child is still running ({@see WindowsPipes::runningProbe()}).
     *
     * @param null|callable(): bool $running
     */
    public function withRunning(?callable $running): self
    {
        return $this->mutate(['running' => $running === null ? null : \Closure::fromCallable($running)]);
    }

    /** Force the Windows (true) or select (false) arm; null picks by the real runtime. */
    public function withPollPipes(?bool $pollPipes): self
    {
        return $this->mutate(['pollPipes' => $pollPipes]);
    }

    /**
     * Live feed: called with (slot, bytes) for every chunk read, including the
     * slots that are drained but not kept. A TUI tail or a log tee rides this.
     *
     * @param null|callable(int|string, string): void $onChunk
     */
    public function withOnChunk(?callable $onChunk): self
    {
        return $this->mutate(['onChunk' => $onChunk === null ? null : \Closure::fromCallable($onChunk)]);
    }

    /**
     * Feed $bytes to $pipe (the child's stdin) while the walk reads, then close
     * $pipe: the child sees EOF. Refused on the Windows arm (LogicException).
     *
     * @param resource $pipe  A writable pipe the caller opened for the child's stdin
     */
    public function withStdin($pipe, string $bytes): self
    {
        if (!is_resource($pipe)) {
            throw new \InvalidArgumentException('Stdin must be a writable pipe resource');
        }

        return $this->mutate(['stdinPipe' => $pipe, 'stdinBytes' => $bytes]);
    }

    /**
     * Call $onBeat at most every $seconds while the walk is still open. The
     * first beat comes one interval in, not at the start.
     *
     * @param callable(): void $onBeat
     */
    public function withHeartbeat(float $seconds, callable $onBeat): self
    {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Heartbeat must be a positive finite number of seconds');
        }

        return $this->mutate([
            'heartbeatSeconds' => $seconds,
            'onBeat' => \Closure::fromCallable($onBeat),
        ]);
    }

    /**
     * Run $reap once after the walk ends, after the last read, in a `finally`:
     * the caller reaps the child (or kills it) only once its pipes are drained.
     *
     * @param null|callable(): void $reap
     */
    public function withReap(?callable $reap): self
    {
        return $this->mutate(['reap' => $reap === null ? null : \Closure::fromCallable($reap)]);
    }

    /**
     * Read the pipes until they are all at EOF, the deadline passes, or the cap
     * stops the walk, and return what was read and why it stopped.
     *
     * Named pump() rather than drain() because the static drain() keeps its
     * name for the callers that predate the builder: PHP does not allow a
     * static and an instance method of one name on one class.
     *
     * @throws \LogicException when a stdin feed meets the Windows arm
     */
    public function pump(): DrainResult
    {
        // Mirrors the arm choice the static drain() always made: the real
        // runtime unless the caller forces one, and sockets never need polling.
        $pollArm = $this->pollPipes
            ?? (WindowsPipes::runtimeNeedsIt()
                && array_filter($this->pipes, static fn ($p): bool => !self::isSocket($p)) !== []);

        if ($pollArm && $this->stdinPipe !== null && (string) $this->stdinBytes !== '') {
            throw new \LogicException(
                'A stdin feed needs non-blocking pipes, which the Windows poll arm cannot give; '
                . 'pass the payload to the child as a file instead.',
            );
        }

        $clock = $this->clock ?? Clock::monotonicSeconds(...);
        $deadline = $this->deadline ?? ($clock() + self::DEFAULT_TIMEOUT_SECONDS);

        foreach ($this->pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $capture = new ChildCapture(
            array_keys($this->pipes),
            $this->discard,
            $this->maxBytes,
            $this->onChunk,
        );

        $feed = null;
        if ($this->stdinPipe !== null) {
            if ((string) $this->stdinBytes === '') {
                fclose($this->stdinPipe);
            } else {
                stream_set_blocking($this->stdinPipe, false);
                $feed = ['pipe' => $this->stdinPipe, 'bytes' => (string) $this->stdinBytes, 'offset' => 0];
            }
        }

        try {
            return $pollArm
                ? $this->drainPolled($deadline, $clock, $capture)
                : $this->drainSelected($deadline, $clock, $capture, $feed);
        } finally {
            if ($feed !== null) {
                fclose($feed['pipe']);
            }
            if ($this->reap !== null) {
                ($this->reap)();
            }
        }
    }

    /**
     * Static entry kept for the callers that predate the builder (AsyncOps::drain
     * and candy-code's three call sites). Same contract as before the builder:
     * see the class docblock for each argument. New code uses {@see new()}.
     *
     * One deliberate tightening: a non-finite $deadline throws
     * InvalidArgumentException, as {@see withDeadline()} does. The earlier body
     * had no finiteness check on $deadline.
     *
     * @param array<int|string, resource> $pipes
     * @param array<int, int|string> $discard
     * @param null|callable(): float $clock
     * @param null|callable(array &$read, mixed &$write, mixed &$except, int $sec, int $usec): (int|false) $select
     * @param null|callable(): bool $running
     */
    public static function drain(
        array $pipes,
        float $deadline,
        int $maxBytes,
        ?callable $clock = null,
        bool $stopAtCap = false,
        array $discard = [],
        float $sliceSeconds = 0.1,
        int $selectRetries = self::SELECT_RETRIES,
        ?callable $select = null,
        ?callable $running = null,
        ?bool $pollPipes = null,
    ): DrainResult {
        return self::new($pipes)
            ->withDeadline($deadline)
            ->withCap($maxBytes)
            ->withClock($clock)
            ->withStopAtCap($stopAtCap)
            ->withDiscard($discard)
            ->withSlice($sliceSeconds)
            ->withSelectRetries($selectRetries)
            ->withSelect($select)
            ->withRunning($running)
            ->withPollPipes($pollPipes)
            ->pump();
    }

    /**
     * The select arm: one stream_select() over every open pipe and the stdin
     * feed, with the slice bounded by the deadline and the next heartbeat.
     *
     * @param null|array{pipe: resource, bytes: string, offset: int} $feed  Advanced in place
     */
    private function drainSelected(float $deadline, callable $clock, ChildCapture $capture, ?array &$feed): DrainResult
    {
        $open = $this->pipes;
        $failures = 0;
        $reason = DrainResult::CLOSED;
        $beatAt = $this->heartbeatSeconds === null ? null : $clock() + $this->heartbeatSeconds;

        // The feed outlives the output pipes: a child may close stdout and
        // stderr and still read its stdin, so the loop runs until the feed
        // is written, the pipe breaks, or the deadline passes.
        while ($open !== [] || $feed !== null) {
            if ($this->stopAtCap && $capture->anyFull()) {
                $reason = DrainResult::STOPPED;
                break;
            }

            $now = $clock();
            $remaining = $deadline - $now;
            if ($remaining <= 0.0) {
                $reason = DrainResult::EXPIRED;
                break;
            }

            if ($beatAt !== null && $now >= $beatAt) {
                $this->beat();
                $beatAt = $clock() + (float) $this->heartbeatSeconds;
            }

            $slice = min($remaining, $this->sliceSeconds);
            if ($beatAt !== null) {
                $slice = min($slice, max(0.0, $beatAt - $now));
            }

            $read = array_values($open);
            $write = $feed !== null ? [$feed['pipe']] : null;
            $except = null;
            $seconds = (int) $slice;
            $micros = (int) round(($slice - $seconds) * 1_000_000);

            $ready = $this->select !== null
                ? ($this->select)($read, $write, $except, $seconds, $micros)
                : @stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                if (++$failures > $this->selectRetries) {
                    $reason = DrainResult::RETRY_BUDGET_GONE;
                    break;
                }
                continue;
            }
            $failures = 0;

            if ($feed !== null && is_array($write) && in_array($feed['pipe'], $write, true)) {
                self::writeFeed($feed);
            }

            foreach ($open as $slot => $pipe) {
                if (!in_array($pipe, $read, true)) {
                    continue;
                }
                $chunk = fread($pipe, self::READ_BYTES);
                if ($chunk === false || $chunk === '') {
                    // feof(), not the empty read: a non-blocking pipe returns
                    // '' legitimately when the child has written nothing YET.
                    if (feof($pipe)) {
                        unset($open[$slot]);
                    }
                    continue;
                }
                $capture->offer($slot, $chunk);
            }
        }

        if ($reason === DrainResult::EXPIRED || $reason === DrainResult::RETRY_BUDGET_GONE) {
            // Sweep what is already buffered in the kernel before letting go:
            // a partial reason beats none. Non-blocking, so it cannot park.
            foreach ($open as $slot => $pipe) {
                $rest = @stream_get_contents($pipe);
                if (is_string($rest) && $rest !== '') {
                    $capture->offer($slot, $rest);
                }
            }
        }

        return $capture->result($reason);
    }

    /**
     * The Windows arm: read only what each pipe reports readable, end on the
     * child's exit (see the class docblock).
     */
    private function drainPolled(float $deadline, callable $clock, ChildCapture $capture): DrainResult
    {
        $pipes = $this->pipes;
        if ($pipes === []) {
            return $capture->result(DrainResult::CLOSED);
        }

        $exitedAt = null;
        $beatAt = $this->heartbeatSeconds === null ? null : $clock() + $this->heartbeatSeconds;

        while (true) {
            if ($this->stopAtCap && $capture->anyFull()) {
                return $capture->result(DrainResult::STOPPED);
            }

            $now = $clock();
            if ($now >= $deadline) {
                return $capture->result(DrainResult::EXPIRED);
            }

            if ($beatAt !== null && $now >= $beatAt) {
                $this->beat();
                $beatAt = $clock() + (float) $this->heartbeatSeconds;
            }

            // Sampled BEFORE the reads: everything a child wrote before it
            // exited is already in the pipe, so an empty pass after an exit
            // seen here means nothing more is coming from the child itself.
            if ($exitedAt === null && $this->running !== null && !($this->running)()) {
                $exitedAt = $now;
            }

            $got = false;
            foreach ($pipes as $slot => $pipe) {
                // A socket among the pipes reports fstat size 0, but it was
                // made non-blocking above, so a plain read answers at once.
                $chunk = self::isSocket($pipe)
                    ? (string) @fread($pipe, self::READ_BYTES)
                    : WindowsPipes::readAvailable($pipe, self::READ_BYTES);
                if ($chunk !== '') {
                    $capture->offer($slot, $chunk);
                    $got = true;
                }
            }

            if ($exitedAt !== null && (!$got || $now - $exitedAt >= WindowsPipes::EXIT_DRAIN_GRACE_SECONDS)) {
                return $capture->result(DrainResult::CLOSED);
            }

            if (!$got) {
                $nap = min(WindowsPipes::POLL_MICROSECONDS, (int) ceil(($deadline - $now) * 1_000_000));
                if ($beatAt !== null) {
                    $nap = min($nap, (int) ceil(($beatAt - $now) * 1_000_000));
                }
                usleep(max(1, $nap));
            }
        }
    }

    /**
     * One non-blocking write of the stdin feed. A broken pipe (the child exited
     * without reading) ends the feed at once; a completed feed closes its pipe.
     *
     * @param array{pipe: resource, bytes: string, offset: int} $feed  Advanced in place
     */
    private static function writeFeed(?array &$feed): void
    {
        if ($feed === null) {
            return;
        }

        $written = @fwrite($feed['pipe'], substr($feed['bytes'], $feed['offset'], self::READ_BYTES));
        if ($written === false) {
            $feed['offset'] = strlen($feed['bytes']);
        } else {
            $feed['offset'] += $written;
        }

        if ($feed['offset'] >= strlen($feed['bytes'])) {
            fclose($feed['pipe']);
            $feed = null;
        }
    }

    private function beat(): void
    {
        if ($this->onBeat !== null) {
            ($this->onBeat)();
        }
    }

    /** @param array<string, mixed> $changes Constructor arguments to replace; the rest carry over */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /**
     * Whether $stream is a socket (selectable and non-blocking capable on
     * every host) rather than a Windows-hostile pipe.
     *
     * @param resource $stream
     */
    private static function isSocket($stream): bool
    {
        if (!\is_resource($stream)) {
            return false;
        }

        return \str_contains((string) (\stream_get_meta_data($stream)['stream_type'] ?? ''), 'socket');
    }
}
