<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Constraint;

/**
 * At least `$n` cells; takes more if space is available.
 *
 * Mirrors ratatui `Constraint::Min(n)`.
 */
final class Min extends Constraint
{
    public function __construct(public readonly int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Min must be non-negative');
        }
    }
}
