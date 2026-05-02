<?php

declare(strict_types=1);

namespace CandyCore\Bits\Table;

/**
 * Header column for {@see Table::setColumns()}. `$width = 0` lets the
 * table auto-size the column to fit its widest cell; any positive
 * value forces that cell width (truncating longer values).
 *
 * Mirrors Bubbles' `Column{Title, Width}` struct.
 */
final class Column
{
    public function __construct(
        public readonly string $title,
        public readonly int $width = 0,
    ) {
        if ($width < 0) {
            throw new \InvalidArgumentException('Column width must be >= 0');
        }
    }
}
