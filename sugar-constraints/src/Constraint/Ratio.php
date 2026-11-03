<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Constraint;

/**
 * Proportional size based on a ratio (numerator / denominator).
 *
 * Mirrors ratatui `Constraint::Ratio(n, d)`. The ratio is resolved against
 * the WHOLE region along the solved axis only — Ratio(1, 3) claims a third of
 * the area, not of the remaining slack, and carries no meaning on the other
 * axis.
 */
final class Ratio extends Constraint
{
    public function __construct(
        public readonly int $numerator,
        public readonly int $denominator,
    ) {
        if ($numerator < 0) {
            throw new \InvalidArgumentException("Ratio numerator must be non-negative; got {$numerator}/{$denominator}");
        }
        if ($denominator <= 0) {
            throw new \InvalidArgumentException("Ratio denominator must be positive; got {$numerator}/{$denominator}");
        }
    }
}
