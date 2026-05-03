<?php

declare(strict_types=1);

namespace CandyCore\Flap;

/**
 * One scrolling pipe pair. The gap between the upper and lower
 * sections is centred on `gapY`; both pipes are `gapHeight` tall in
 * total open cells.
 */
final class Pipe
{
    public function __construct(
        public readonly int $x,
        public readonly int $gapY,
        public readonly int $gapHeight,
    ) {}

    /** Slide the pipe one column to the left. */
    public function tick(): self
    {
        return new self($this->x - 1, $this->gapY, $this->gapHeight);
    }

    /**
     * True if the bird at (col, row) collides with the pipe. The
     * pipe occupies a single column; collisions happen iff the bird
     * is in that column AND the row is outside the open gap.
     */
    public function collides(int $col, int $row): bool
    {
        if ($col !== $this->x) {
            return false;
        }
        $top    = $this->gapY - intdiv($this->gapHeight, 2);
        $bottom = $this->gapY + intdiv($this->gapHeight, 2);
        return $row < $top || $row > $bottom;
    }

    public function isOffScreen(): bool
    {
        return $this->x < 0;
    }
}
