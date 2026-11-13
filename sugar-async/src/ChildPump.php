<?php

declare(strict_types=1);

namespace SugarCraft\Async;

/**
 * Read a child's output pipes to EOF without letting one wedge another,
 * bounded by a deadline and a per-stream byte ceiling.
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
 * Volume policy: each retained stream keeps the HEAD up to $maxBytes and counts
 * refused bytes ({@see DrainResult::droppedBytes()}); by default it keeps
 * READING (and discarding) so the child is never blocked on a full pipe.
 * $stopAtCap ends the walk instead, for callers that kill the child anyway.
 * Slots in $discard are drained to keep the child unblocked and never kept.
 *
 * Windows arm: proc_open pipes there cannot be made non-blocking, select()
 * reports every pipe ready while empty, and the fread() that follows parks
 * until the child writes or exits, so the select loop above would lose its
 * deadline and deadlock on an empty stderr while the child fills stdout
 * ({@see WindowsPipes}). On that runtime the pump instead polls each pipe's
 * readable byte count and reads only that much. A Windows pipe cannot report
 * EOF without a read that might block, so the walk ends when the `$running`
 * probe says the child exited and a pass finds nothing left: reason CLOSED,
 * meaning "the child is done and its output is read". Bytes a backgrounded
 * grandchild keeps writing after that are read for at most
 * {@see WindowsPipes::EXIT_DRAIN_GRACE_SECONDS}. Without a `$running` probe
 * the arm cannot tell a finished child from a silent one, so it reads until
 * the deadline and reports EXPIRED — bounded, never wedged.
 *
 * Not here: feeding stdin (interactive relays live in sugar-pty).
 */
final class ChildPump
{
    /** Bytes taken per ready pipe per iteration — one non-blocking read. */
    public const READ_BYTES = 8192;

    /** Consecutive failed selects ridden out before giving up. */
    public const SELECT_RETRIES = 128;

    /**
     * @param array<int|string, resource> $pipes  Readable pipes keyed by slot
     * @param float $deadline  Absolute instant on $clock's scale
     * @param int $maxBytes  Per-retained-stream ceiling (>= 0)
     * @param null|callable(): float $clock  Seconds; defaults to the monotonic clock
     * @param bool $stopAtCap  End the drain when a retained stream reaches the ceiling
     * @param array<int, int|string> $discard  Slots drained but never retained
     * @param float $sliceSeconds  Longest single select wait before the deadline is rechecked
     * @param null|callable(array &$read, mixed &$write, mixed &$except, int $sec, int $usec): (int|false) $select
     *        stream_select() seam: same contract, `$read` narrowed in place.
     *        Tests inject an EINTR-shaped false. POSIX arm only.
     * @param null|callable(): bool $running  Whether the child is still running
     *        ({@see WindowsPipes::runningProbe()}). The Windows arm needs it to
     *        end before the deadline; the select arm ends on EOF and ignores it.
     * @param null|bool $pollPipes  Force the Windows (true) or select (false)
     *        arm; null picks by the real runtime ({@see WindowsPipes::runtimeNeedsIt()}).
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
        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Max bytes must be >= 0');
        }
        if ($sliceSeconds <= 0 || !is_finite($sliceSeconds)) {
            throw new \InvalidArgumentException('Slice must be a positive finite number of seconds');
        }
        $clock ??= Clock::monotonicSeconds(...);

        $buffers = [];
        $dropped = [];
        foreach ($pipes as $slot => $pipe) {
            stream_set_blocking($pipe, false);
            if (!in_array($slot, $discard, true)) {
                $buffers[$slot] = '';
                $dropped[$slot] = 0;
            }
        }

        $capture = static function (int|string $slot, string $bytes) use (&$buffers, &$dropped, $maxBytes): void {
            if (!isset($buffers[$slot])) {
                return;
            }
            $room = $maxBytes - strlen($buffers[$slot]);
            if ($room > 0) {
                $buffers[$slot] .= substr($bytes, 0, $room);
            }
            $refused = strlen($bytes) - max(0, min($room, strlen($bytes)));
            $dropped[$slot] += $refused;
        };

        if ($pollPipes ?? WindowsPipes::runtimeNeedsIt()) {
            return self::pollDrain($pipes, $deadline, $maxBytes, $clock, $stopAtCap, $buffers, $dropped, $capture, $running);
        }

        $open = $pipes;
        $failures = 0;
        $reason = DrainResult::CLOSED;

        while ($open !== []) {
            if ($stopAtCap) {
                foreach ($buffers as $kept) {
                    if (strlen($kept) >= $maxBytes) {
                        $reason = DrainResult::STOPPED;
                        break 2;
                    }
                }
            }

            $remaining = $deadline - $clock();
            if ($remaining <= 0.0) {
                $reason = DrainResult::EXPIRED;
                break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;
            $slice = min($remaining, $sliceSeconds);
            $seconds = (int) $slice;
            $micros = (int) round(($slice - $seconds) * 1_000_000);

            $ready = $select !== null
                ? $select($read, $write, $except, $seconds, $micros)
                : @stream_select($read, $write, $except, $seconds, $micros);
            if ($ready === false) {
                if (++$failures > $selectRetries) {
                    $reason = DrainResult::RETRY_BUDGET_GONE;
                    break;
                }
                continue;
            }
            $failures = 0;

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
                $capture($slot, $chunk);
            }
        }

        if ($reason === DrainResult::EXPIRED || $reason === DrainResult::RETRY_BUDGET_GONE) {
            // Sweep what is already buffered in the kernel before letting go:
            // a partial reason beats none. Non-blocking, so it cannot park.
            foreach ($open as $slot => $pipe) {
                $rest = @stream_get_contents($pipe);
                if (is_string($rest) && $rest !== '') {
                    $capture($slot, $rest);
                }
            }
        }

        return new DrainResult($buffers, $dropped, $reason);
    }

    /**
     * The Windows arm: read only what each pipe reports readable, end on the
     * child's exit (see the class docblock).
     *
     * @param array<int|string, resource> $pipes
     * @param callable(): float $clock
     * @param array<int|string, string> $buffers
     * @param array<int|string, int> $dropped
     * @param \Closure(int|string, string): void $capture
     * @param null|callable(): bool $running
     */
    private static function pollDrain(
        array $pipes,
        float $deadline,
        int $maxBytes,
        callable $clock,
        bool $stopAtCap,
        array &$buffers,
        array &$dropped,
        \Closure $capture,
        ?callable $running,
    ): DrainResult {
        if ($pipes === []) {
            return new DrainResult($buffers, $dropped, DrainResult::CLOSED);
        }

        $exitedAt = null;

        while (true) {
            if ($stopAtCap) {
                foreach ($buffers as $kept) {
                    if (strlen($kept) >= $maxBytes) {
                        return new DrainResult($buffers, $dropped, DrainResult::STOPPED);
                    }
                }
            }

            $now = $clock();
            if ($now >= $deadline) {
                return new DrainResult($buffers, $dropped, DrainResult::EXPIRED);
            }

            // Sampled BEFORE the reads: everything a child wrote before it
            // exited is already in the pipe, so an empty pass after an exit
            // seen here means nothing more is coming from the child itself.
            if ($exitedAt === null && $running !== null && !$running()) {
                $exitedAt = $now;
            }

            $got = false;
            foreach ($pipes as $slot => $pipe) {
                $chunk = WindowsPipes::readAvailable($pipe, self::READ_BYTES);
                if ($chunk !== '') {
                    $capture($slot, $chunk);
                    $got = true;
                }
            }

            if ($exitedAt !== null && (!$got || $now - $exitedAt >= WindowsPipes::EXIT_DRAIN_GRACE_SECONDS)) {
                return new DrainResult($buffers, $dropped, DrainResult::CLOSED);
            }

            if (!$got) {
                $nap = min(WindowsPipes::POLL_MICROSECONDS, (int) ceil(($deadline - $now) * 1_000_000));
                usleep(max(1, $nap));
            }
        }
    }
}
