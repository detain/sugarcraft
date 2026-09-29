<?php

declare(strict_types=1);

namespace SugarCraft\Layout;

/**
 * Linear expression: sum(a_i * x_i) + c.
 *
 * MUTABLE builder left over from the retired simplex prototype — NOT an
 * immutable value object: $terms and $constant are public and writable, and
 * plus()/times() themselves seed a fresh instance by assigning through those
 * fields. The arithmetic methods are copy-style (they never touch the
 * receiver), which is likely what the old "Immutable" claim meant, but the
 * type does not enforce it. Freezing the fields would break the public API,
 * so under the pre-1.0 API freeze the round-86 ruling keeps behaviour as-is
 * and states the measured truth here instead (findings/plan_candy-layout.md
 * row 3.2).
 */
final class Expression
{
    /** @var array<string, float> Variable coefficients */
    public array $terms = [];

    public float $constant = 0.0;

    public function __construct(array $terms = [], float $constant = 0.0)
    {
        $this->terms = $terms;
        $this->constant = $constant;
    }

    public static function zero(): self
    {
        return new self();
    }

    public static function constant(float $c): self
    {
        return new self([], $c);
    }

    public static function variable(string $name, float $coef = 1.0): self
    {
        return new self([$name => $coef], 0.0);
    }

    public function plus(Expression $other): self
    {
        $result = new self($this->terms, $this->constant);
        foreach ($other->terms as $name => $coef) {
            $result->terms[$name] = ($result->terms[$name] ?? 0.0) + $coef;
        }
        $result->constant += $other->constant;
        return $result;
    }

    public function minus(Expression $other): self
    {
        return $this->plus(new self(
            array_map(fn($v) => -$v, $other->terms),
            -$other->constant
        ));
    }

    public function times(float $scalar): self
    {
        $result = new self();
        foreach ($this->terms as $name => $coef) {
            $result->terms[$name] = $coef * $scalar;
        }
        $result->constant = $this->constant * $scalar;
        return $result;
    }
}
