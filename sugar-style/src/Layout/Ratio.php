<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

use SugarCraft\Constraints\Constraint as ConstraintsConstraint;

/**
 * Proportional size based on a ratio (numerator / denominator).
 *
 * Mirrors ratatui `Constraint::Ratio(n, d)`.
 *
 * Wraps {@see \SugarCraft\Constraints\Constraint\Ratio}.
 */
final class Ratio extends Constraint
{
    use ReadsConstraintPayload;

    private ConstraintsConstraint\Ratio $inner;

    public function __construct(int $numerator, int $denominator)
    {
        if ($numerator < 0) {
            throw new \InvalidArgumentException('Ratio numerator must be non-negative');
        }
        if ($denominator <= 0) {
            throw new \InvalidArgumentException('Ratio denominator must be positive');
        }
        $this->inner = ConstraintsConstraint\Ratio::ratio($numerator, $denominator);
    }

    public function __get(string $name): mixed
    {
        return $this->payloadValue($name, ['numerator' => $this->inner->numerator, 'denominator' => $this->inner->denominator]);
    }
}
