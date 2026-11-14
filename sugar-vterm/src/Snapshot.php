<?php

declare(strict_types=1);

namespace SugarCraft\Vterm;

use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Parser\CsiHandlerImpl;

/**
 * Frozen frame from the terminal emulator.
 *
 * Immutable VALUE of the capture: the grid, the cursor and the terminal's
 * feed revision at one point in time. Snapshots taken through
 * {@see self::of()} or {@see Terminal::snapshot()} deep-copy the live grid
 * on capture (Buffer's default clone copies its row arrays by value, and
 * {@see Cell} is readonly), so feeding more bytes into the terminal later
 * NEVER mutates an already-captured frame. A Snapshot built directly from
 * the constructor stores exactly what it is given — passing a live Buffer
 * aliases it; use {@see self::of()} for captures.
 */
final readonly class Snapshot
{
    public function __construct(
        public Buffer $grid,
        public Cursor $cursor,
        public float $time,
        public int $revision = 0,
    ) {
    }

    /**
     * Capture a frozen frame from a live terminal: the grid is deep-copied
     * so the Snapshot cannot retro-mutate as the terminal keeps consuming.
     */
    public static function of(Terminal $t, float $time = 0.0): self
    {
        return new self(clone $t->grid(), $t->cursor(), $time, $t->revision());
    }

    public function equals(self $other): bool
    {
        return $this->cursor->equals($other->cursor)
            && $this->grid->equals($other->grid);
    }

    /**
     * Compare snapshots including time.
     * Used when exact frame reproducibility at specific timestamps matters.
     */
    public function equalsWithTime(self $other): bool
    {
        return $this->time === $other->time
            && $this->cursor->equals($other->cursor)
            && $this->grid->equals($other->grid);
    }
}
