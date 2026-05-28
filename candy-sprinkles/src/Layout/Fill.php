<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Layout\Constraint as LayoutConstraint;

/**
 * Fills all remaining space with proportional `$weight`.
 *
 * Mirrors ratatui `Constraint::Fill(weight)`.
 *
 * Wraps {@see \SugarCraft\Layout\Constraint\Fill}.
 */
final class Fill extends Constraint
{
    private LayoutConstraint\Fill $inner;

    public function __construct(int $weight = 1)
    {
        if ($weight < 0) {
            throw new \InvalidArgumentException('Fill weight must be non-negative');
        }
        $this->inner = LayoutConstraint\Fill::fill($weight);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'weight' => $this->inner->weight,
            default => throw new \Error("Property {$name} does not exist on " . static::class),
        };
    }
}
