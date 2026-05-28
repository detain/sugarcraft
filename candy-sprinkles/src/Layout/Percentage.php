<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Layout\Constraint as LayoutConstraint;

/**
 * Proportional size as a percentage of the available area.
 *
 * Mirrors ratatui `Constraint::Percentage(n)` where n is 0-100.
 *
 * Wraps {@see \SugarCraft\Layout\Constraint\Percentage}.
 */
final class Percentage extends Constraint
{
    private LayoutConstraint\Percentage $inner;

    public function __construct(int $n)
    {
        if ($n < 0 || $n > 100) {
            throw new \InvalidArgumentException('Percentage must be between 0 and 100');
        }
        $this->inner = LayoutConstraint\Percentage::percentage($n);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'n' => $this->inner->n,
            default => throw new \Error("Property {$name} does not exist on " . static::class),
        };
    }
}
