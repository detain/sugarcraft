<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Constraint;

/**
 * Upper-bound size cap; takes less if space is insufficient.
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
