<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

/**
 * Fixed character-cell count.
 *
 * Mirrors ratatui `Constraint::Length(n)`.
 */
final class Length extends Constraint
{
    public function __construct(public readonly int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Length must be non-negative');
        }
    }
}
