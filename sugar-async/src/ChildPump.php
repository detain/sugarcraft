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
     *        Tests inject an EINTR-shaped false.
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
}
