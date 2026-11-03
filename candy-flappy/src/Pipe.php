<?php

declare(strict_types=1);

namespace SugarCraft\Flappy;

/**
 * One scrolling pipe pair. `gapHeight` is the number of OPEN rows
 * between the upper and lower sections (m25: it is not a centre
 * marker's symmetric radius). The band is anchored on `gapY` and,
 * for even heights, tilts one row toward the floor — see collides().
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
     *
     * M2 (docs-as-spec, feel change disclosed): the gap opens exactly
     * `gapHeight` rows — top = gapY - intdiv(h-1,2), bottom =
     * gapY + intdiv(h,2) — so the README difficulty bands (6/5/4/3)
     * are literal. The old symmetric ±intdiv(h,2) opened 2*intdiv(h,2)
     * + 1 rows (7/5/5/3): even bands were one row too generous and odd
     * bands silently shrank to h-1.
     */
    public function collides(int $col, int $row): bool
    {
        if ($col !== $this->x) {
            return false;
        }
        $top    = $this->gapY - intdiv($this->gapHeight - 1, 2);
        $bottom = $this->gapY + intdiv($this->gapHeight, 2);
        return $row < $top || $row > $bottom;
    }

    public function isOffScreen(): bool
    {
        return $this->x < 0;
    }
}
