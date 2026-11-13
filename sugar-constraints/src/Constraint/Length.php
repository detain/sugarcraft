<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Constraint;

/**
 * Fixed character-cell count.
 *
 * Plain "fixed" overstates it — a set with neither Min nor Fill lets the Max
 * reclaim ladder hand cells back to this type ([length(10), max(5)]@100 →
 * [95, 5], README Tiling guarantee ladder step 6).
 *
 * Mirrors ratatui `Constraint::Length(n)`.
 */
final class Length extends Constraint
{
    public function __construct(public readonly int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException("Length must be non-negative; got n={$n}");
        }
    }
}
