<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Layout\Constraint as LayoutConstraint;

/**
 * At least `$n` cells; takes more if space is available.
 *
 * Mirrors ratatui `Constraint::Min(n)`.
 *
 * Wraps {@see \SugarCraft\Layout\Constraint\Min}.
 */
final class Min extends Constraint
{
    private LayoutConstraint\Min $inner;

    public function __construct(int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Min must be non-negative');
        }
        $this->inner = LayoutConstraint\Min::min($n);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'n' => $this->inner->n,
            default => throw new \Error("Property {$name} does not exist on " . static::class),
        };
    }
}
