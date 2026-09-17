<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Constraint;

/**
 * At least `$n` cells; takes more if space is available.
 *
 * Note: `Min(0)` is a no-op floor (always satisfied) and is intentionally
 * allowed — e.g. a panel that may collapse entirely yet still wants the
 * leftover-slack share of the proportional pass (E736/3.1).
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
