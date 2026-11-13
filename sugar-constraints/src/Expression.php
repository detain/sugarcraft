<?php

declare(strict_types=1);

namespace SugarCraft\Constraints;

/**
 * Linear expression: sum(a_i * x_i) + c.
 *
 * Formerly the fully-public mutable builder of the retired simplex prototype.
 * Both fields are now frozen: `$terms` and `$constant` are private readonly,
 * seeded by the constructor and read via the bare {@see terms()} and
 * {@see constant()} accessors (PHP array value semantics make every terms()
 * read a defensive copy). The arithmetic methods build results through the
 * constructor, never through field writes, and {@see withConstant()} is the
 * fluent door for a changed scalar term. The 2026-11 freeze retired the last
 * asymmetry: the static factory that used to squat on the reader name
 * `constant()` (PHP forbids a static and an instance method sharing one) is
 * now `ofConstant()`, matching the house factory vocabulary.
 *
 * No solver in this package consumes it any more: the Big-M simplex that built
 * its tableau rows from these expressions was retired (see
 * {@see CassowarySolver}), and {@see GreedySolver} works in integer cells, not
 * linear terms. It is kept as a self-contained, fully tested value object
 * rather than deleted, but it is NOT part of sugar-constraints's supported surface —
 * {@see LayoutSolver} is the only public contract — so no downstream lib should
 * start depending on it.
 *
 * @internal
 */
final class Expression
{
    /** @param array<string, float> $terms Variable coefficients */
    public function __construct(
        private readonly array $terms = [],
        private readonly float $constant = 0.0,
    ) {
    }

    /**
     * Bare reader for the coefficient map (returns a copy — arrays are values).
     *
     * @return array<string, float>
     */
    public function terms(): array
    {
        return $this->terms;
    }

    /**
     * Bare reader for the scalar term — the name the static factory used to
     * occupy; the factory is {@see ofConstant()} now.
     */
    public function constant(): float
    {
        return $this->constant;
    }

    /**
     * Fluent door for the scalar term: same terms, new constant, new instance.
     */
    public function withConstant(float $value): self
    {
        return new self($this->terms, $value);
    }

    public static function zero(): self
    {
        return new self();
    }

    public static function ofConstant(float $c): self
    {
        return new self([], $c);
    }

    public static function variable(string $name, float $coef = 1.0): self
    {
        return new self([$name => $coef], 0.0);
    }

    public function plus(Expression $other): self
    {
        $summed = $this->terms;
        foreach ($other->terms as $name => $coef) {
            $summed[$name] = ($summed[$name] ?? 0.0) + $coef;
        }
        return new self($summed, $this->constant + $other->constant);
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
        return new self(
            array_map(static fn(float $v): float => $v * $scalar, $this->terms),
            $this->constant * $scalar,
        );
    }
}
