<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Monotonic deadline tracker for I/O timeout management.
 *
 * The budget is spoken in milliseconds and the clock ({@see hrtime()}) runs
 * in nanoseconds. The ms↔ns seam lives in exactly these two methods, so a
 * caller feeding {@see remaining()} to stream_select as `$remaining * 1000`
 * microseconds waits real milliseconds — never the 1000x-short window a
 * microsecond-worth-of-a-nanosecond-budget used to arm (lane p2, N1: the
 * multi-chunk terminal-probe replies of Mosaic::auto() truncated after about
 * a millisecond of inter-chunk gap, silently degrading capability detection).
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

    /** Remaining milliseconds, rounded, floored at 0. */
    public function remaining(): int
    {
        $r = $this->targetNs - hrtime(true);

        return $r <= 0 ? 0 : (int) round($r / 1_000_000);
    }

    /** Whether the budget has run out (nothing whole or partial left). */
    public function isExpired(): bool
    {
        return $this->remaining() === 0;
    }
}
