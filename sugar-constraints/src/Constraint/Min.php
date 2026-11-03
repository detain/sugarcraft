<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Constraint;

/**
 * At least `$n` cells — a soft floor.
 *
 * Note: `Min(0)` is a no-op floor (always satisfied) and is intentionally
 * allowed — e.g. a panel that may collapse entirely yet still wants the
 * leftover-slack share of the proportional pass.
 *
 * Growth policy, precisely: a Min grows ONLY when slack survives every Fill
 * and Max in the set (they take slack first), or when a Max clamp pass hands
 * cells back and no Fill claims them. Plain "grows if space is available"
 * overstates it — [Min(10), Fill(1), Max(6)] in 17 cells stays 10/1/6.
 *
 * Mirrors ratatui `Constraint::Min(n)`.
 */
final class Min extends Constraint
{
    public function __construct(public readonly int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException("Min must be non-negative; got n={$n}");
        }
    }
}
