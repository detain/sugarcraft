<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Constraint;

/**
 * Proportional size based on a ratio (numerator / denominator).
 *
 * Mirrors ratatui `Constraint::Ratio(n, d)`.
 */
final class Ratio extends Constraint
{
    public function __construct(
        public readonly int $numerator,
        public readonly int $denominator,
    ) {
        if ($numerator < 0) {
            throw new \InvalidArgumentException('Ratio numerator must be non-negative');
        }
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Ratio denominator must be positive');
        }
    }
}
