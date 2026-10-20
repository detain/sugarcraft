<?php

declare(strict_types=1);

namespace SugarCraft\Image;

/**
 * Monotonic deadline tracker for I/O timeout management.
 *
 * The budget is spoken in milliseconds and the clock ({@see hrtime()}) runs
 * in nanoseconds. The ms↔ns seam lives in {@see in()} (arming: ms widened to
 * ns) and {@see remainingMs()} (reading: ns narrowed to whole ms, floored),
 * with {@see isExpired()} judging on the raw ns clock; {@see remaining()} is
 * the deprecated alias of remainingMs(). So a caller feeding remainingMs() to
 * stream_select as `$ms * 1000` microseconds waits real milliseconds — never
 * the 1000x-short window a microsecond-worth-of-a-nanosecond-budget used to
 * arm (lane p2, N1: the multi-chunk terminal-probe replies of Mosaic::auto()
 * truncated after about a millisecond of inter-chunk gap, silently degrading
 * capability detection).
 */
final class Deadline
{
    /** Target instant on the hrtime(true) nanosecond clock. */
    private float $targetNs;

    private function __construct(float $targetNs)
    {
        $this->targetNs = $targetNs;
    }

    public static function in(int $ms): self
    {
        // hrtime(true) is nanoseconds: one millisecond of budget is
        // 1_000_000 ns of clock width.
        return new self(hrtime(true) + $ms * 1_000_000.0);
    }

    /**
     * D-06: Remaining whole milliseconds, floored, clamped at 0.
     *
     * Floor, never round: a caller sleeping `remainingMs() * 1000`
     * microseconds stays inside the budget instead of overshooting the
     * target by up to half a millisecond, which is what the old round()
     * tail let `stream_select` waiters do. A zero here means "no WHOLE
     * millisecond left" — not "expired"; a target still 400 µs away reads
     * 0 and is very much alive. {@see isExpired()} answers the exact
     * question on the nanosecond clock itself.
     */
    public function remainingMs(): int
    {
        $r = ($this->targetNs - hrtime(true)) / 1_000_000;

        return $r <= 0 ? 0 : (int) $r;
    }

    /**
     * D-06: Whether the budget has run out, judged on the raw ns clock.
     *
     * Exact `<= 0`: the target instant having been reached is expiry (zero
     * budget left is nothing left to spend). Never derived through a
     * rounded or floored ms reading — fractional budget still live must
     * read as not-expired even while remainingMs() reads 0.
     */
    public function isExpired(): bool
    {
        return $this->targetNs - hrtime(true) <= 0;
    }

    /**
     * D-06: @deprecated alias for {@see remainingMs()}.
     *
     * This method used to round, so a 0.4 ms live budget read as 0/expired
     * and a `remaining() * 1000`-microsecond sleep could run up to 0.5 ms
     * past the target. It now delegates to the floored reading; existing
     * callers (`Detect`'s stdin pumps) keep working and land on the safe
     * side of the boundary. Prefer {@see remainingMs()} for sleeping and
     * {@see isExpired()} for judging.
     */
    public function remaining(): int
    {
        return $this->remainingMs();
    }
}
