<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

use SugarCraft\Vt\Parser\CsiHandlerImpl;
use SugarCraft\Vt\Parser\OscHandlerImpl;

/**
 * Frozen frame from the terminal emulator.
 *
 * Immutable snapshot of CellGrid + Cursor at a point in time.
 */
final readonly class Snapshot
{
    public function __construct(
        public CellGrid $grid,
        public Cursor $cursor,
        public float $time,
    ) {
    }

    public static function of(Terminal $t, float $time = 0.0): self
    {
        return new self($t->grid(), $t->cursor(), $time);
    }
}
