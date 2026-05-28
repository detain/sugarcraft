<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Layout\Constraint as LayoutConstraint;

/**
 * Fixed character-cell count.
 *
 * Mirrors ratatui `Constraint::Length(n)`.
 *
 * Wraps {@see \SugarCraft\Layout\Constraint\Length}.
 */
final class Length extends Constraint
{
    private LayoutConstraint\Length $inner;

    public function __construct(int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Length must be non-negative');
        }
        $this->inner = LayoutConstraint\Length::length($n);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'n' => $this->inner->n,
            default => throw new \Error("Property {$name} does not exist on " . static::class),
        };
    }
}
