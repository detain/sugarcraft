<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

use SugarCraft\Constraints\Constraint as ConstraintsConstraint;

/**
 * Proportional size as a percentage of the available area.
 *
 * Mirrors ratatui `Constraint::Percentage(n)` where n is 0-100.
 *
 * Wraps {@see \SugarCraft\Constraints\Constraint\Percentage}.
 */
final class Percentage extends Constraint
{
    use ReadsConstraintPayload;

    private ConstraintsConstraint\Percentage $inner;

    public function __construct(int $n)
    {
        if ($n < 0 || $n > 100) {
            throw new \InvalidArgumentException('Percentage must be between 0 and 100');
        }
        $this->inner = ConstraintsConstraint\Percentage::percentage($n);
    }

    public function __get(string $name): mixed
    {
        return $this->payloadValue($name, ['n' => $this->inner->n]);
    }
}
