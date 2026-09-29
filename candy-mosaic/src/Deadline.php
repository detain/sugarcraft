<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic;

/**
 * Monotonic deadline tracker for I/O timeout management.
 */
final class Deadline
{
    private float $targetUs;

    private function __construct(float $targetUs)
    {
        $this->targetUs = $targetUs;
    }

    public static function in(int $ms): self
    {
        // hrtime is nanoseconds
        return new self(hrtime(true) + ($ms * 1_000));
    }

    /** Remaining milliseconds, floored at 0. */
    public function remaining(): int
    {
        $r = $this->targetUs - hrtime(true);

        return $r <= 0 ? 0 : (int) ($r / 1_000);
    }
}
