<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Constraint;

/**
 * Upper-bound size cap; takes less if space is insufficient.
 *
 * Note: `Max(0)` is an explicit collapse request (the region is clamped to
 * zero width) and is intentionally allowed — it must not be mistaken for an
 * unset/default value (E736/3.1).
 *
 * Mirrors ratatui `Constraint::Max(n)`.
 */
final class Max extends Constraint
{
    public function __construct(public readonly int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Max must be non-negative');
        }
    }
}
