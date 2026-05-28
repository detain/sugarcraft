<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Constraint;

/**
 * Proportional size as a percentage of the available area.
 *
 * Mirrors ratatui `Constraint::Percentage(n)` where n is 0-100.
 */
final class Percentage extends Constraint
{
    public function __construct(public readonly int $n)
    {
        if ($n < 0 || $n > 100) {
            throw new \InvalidArgumentException('Percentage must be between 0 and 100');
        }
    }
}
