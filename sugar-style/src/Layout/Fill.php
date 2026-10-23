<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

use SugarCraft\Constraints\Constraint as ConstraintsConstraint;

/**
 * Fills all remaining space with proportional `$weight`.
 *
 * Mirrors ratatui `Constraint::Fill(weight)`.
 *
 * Wraps {@see \SugarCraft\Constraints\Constraint\Fill}.
 */
final class Fill extends Constraint
{
    use ReadsConstraintPayload;

    private ConstraintsConstraint\Fill $inner;

    public function __construct(int $weight = 1)
    {
        if ($weight < 0) {
            throw new \InvalidArgumentException('Fill weight must be non-negative');
        }
        $this->inner = ConstraintsConstraint\Fill::fill($weight);
    }

    public function __get(string $name): mixed
    {
        return $this->payloadValue($name, ['weight' => $this->inner->weight]);
    }
}
