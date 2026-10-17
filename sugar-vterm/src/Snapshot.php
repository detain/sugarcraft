<?php

declare(strict_types=1);

namespace SugarCraft\Vterm;

use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Parser\CsiHandlerImpl;
use SugarCraft\Vterm\Parser\OscHandlerImpl;

/**
 * Frozen frame from the terminal emulator.
 *
 * Immutable snapshot of Buffer + Cursor at a point in time.
 */
final readonly class Snapshot
{
    public function __construct(
        public Buffer $grid,
        public Cursor $cursor,
        public float $time,
    ) {
    }

    public static function of(Terminal $t, float $time = 0.0): self
    {
        return new self($t->grid(), $t->cursor(), $time);
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
