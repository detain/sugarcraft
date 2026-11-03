<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Constraint;

/**
 * Proportional size as a percentage of the available area.
 *
 * Mirrors ratatui `Constraint::Percentage(n)`: n is an integer 0-100
 * inclusive; the produced width is `(int) floor(total * n / 100)` by default.
 */
final class Percentage extends Constraint
{
    public function __construct(public readonly int $n)
    {
        if ($n < 0 || $n > 100) {
            throw new \InvalidArgumentException("Percentage must be 0-100 inclusive; got n={$n}");
        }
    }
}
