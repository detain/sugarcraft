<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Constraint;

/**
 * Upper-bound size cap; takes slack greedily up to the cap, and its clamp pass
 * can give cells BACK to the other types (Min first, then Fill, then
 * everything else — README Tiling guarantee ladder step 6).
 *
 * Note: `Max(0)` is an explicit collapse request (the region is clamped to
 * zero width) and is intentionally allowed — it must not be mistaken for an
 * unset/default value.
 *
 * Mirrors ratatui `Constraint::Max(n)`.
 */
final class Max extends Constraint
{
    public function __construct(public readonly int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException("Max must be non-negative; got n={$n}");
        }
    }
}
