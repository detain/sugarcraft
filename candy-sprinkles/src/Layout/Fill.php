<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

/**
 * Fills all remaining space with proportional `$weight`.
 *
 * Mirrors ratatui `Constraint::Fill(weight)`.
 */
final class Fill extends Constraint
{
    public function __construct(public readonly int $weight = 1)
    {
        if ($weight < 0) {
            throw new \InvalidArgumentException('Fill weight must be non-negative');
        }
    }
}
