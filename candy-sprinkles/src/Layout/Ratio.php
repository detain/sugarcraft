<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Layout\Constraint as LayoutConstraint;

/**
 * Proportional size based on a ratio (numerator / denominator).
 *
 * Mirrors ratatui `Constraint::Ratio(n, d)`.
 *
 * Wraps {@see \SugarCraft\Layout\Constraint\Ratio}.
 */
final class Ratio extends Constraint
{
    private LayoutConstraint\Ratio $inner;

    public function __construct(int $numerator, int $denominator)
    {
        if ($numerator < 0) {
            throw new \InvalidArgumentException('Ratio numerator must be non-negative');
        }
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Ratio denominator must be positive');
        }
        $this->inner = LayoutConstraint\Ratio::ratio($numerator, $denominator);
    }

    public function __get(string $name): mixed
    {
        return match ($name) {
            'numerator' => $this->inner->numerator,
            'denominator' => $this->inner->denominator,
            default => throw new \Error("Property {$name} does not exist on " . static::class),
        };
    }
}
