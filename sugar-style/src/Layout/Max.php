<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

use SugarCraft\Constraints\Constraint as ConstraintsConstraint;

/**
 * Upper-bound size cap; takes less if space is insufficient.
 *
 * Mirrors ratatui `Constraint::Max(n)`.
 *
 * Wraps {@see \SugarCraft\Constraints\Constraint\Max}.
 */
final class Max extends Constraint
{
    use ReadsConstraintPayload;

    private ConstraintsConstraint\Max $inner;

    public function __construct(int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Max must be non-negative');
        }
        $this->inner = ConstraintsConstraint\Max::max($n);
    }

    public function __get(string $name): mixed
    {
        return $this->payloadValue($name, ['n' => $this->inner->n]);
    }
}
