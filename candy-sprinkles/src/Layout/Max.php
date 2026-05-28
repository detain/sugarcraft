<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Layout\Constraint as LayoutConstraint;

/**
 * Upper-bound size cap; takes less if space is insufficient.
 *
 * Mirrors ratatui `Constraint::Max(n)`.
 *
 * Wraps {@see \SugarCraft\Layout\Constraint\Max}.
 */
final class Max extends Constraint
{
    private LayoutConstraint\Max $inner;

    public function __construct(int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Max must be non-negative');
        }
        $this->inner = LayoutConstraint\Max::max($n);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'n' => $this->inner->n,
            default => throw new \Error("Property {$name} does not exist on " . static::class),
        };
    }
}
