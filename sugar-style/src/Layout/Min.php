<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

use SugarCraft\Constraints\Constraint as ConstraintsConstraint;

/**
 * At least `$n` cells; takes more if space is available.
 *
 * Mirrors ratatui `Constraint::Min(n)`.
 *
 * Wraps {@see \SugarCraft\Constraints\Constraint\Min}.
 */
final class Min extends Constraint
{
    use ReadsConstraintPayload;

    private ConstraintsConstraint\Min $inner;

    public function __construct(int $n)
    {
        if ($n < 0) {
            throw new \InvalidArgumentException('Min must be non-negative');
        }
        $this->inner = ConstraintsConstraint\Min::min($n);
    }

    public function __get(string $name): mixed
    {
        return $this->payloadValue($name, ['n' => $this->inner->n]);
    }
}
