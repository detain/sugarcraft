<?php

declare(strict_types=1);

namespace SugarCraft\Layout;

/**
 * Linear expression: sum(a_i * x_i) + c.
 *
 * Formerly the fully-public mutable builder of the retired simplex prototype.
 * As of the round-90 audit sweep $terms is frozen: private readonly, seeded by
 * the constructor and read via the bare {@see terms()} accessor (PHP array
 * value semantics make every read a defensive copy). The arithmetic methods
 * build results through the constructor, never through field writes.
 *
 * $constant intentionally STAYS public and writable: the natural reader name
 * `constant()` is taken by the {@see self::constant()} static factory, and
 * inventing a second-spelling accessor would widen the API for no behaviour.
 * Externally mutating $constant does not desynchronise any cached derived
 * state, so this residual mutability is disclosed rather than frozen (the
 * round-86 keep-as-is ruling on the class, findings/plan_candy-layout.md row
 * 3.2, stood until a cheap freeze became available — for $terms, it did).
 *
 * No solver in this package consumes it any more: the Big-M simplex that built
 * its tableau rows from these expressions was retired (see
 * {@see CassowarySolver}), and {@see GreedySolver} works in integer cells, not
 * linear terms. It is kept as a self-contained, fully tested value object
 * rather than deleted, but it is NOT part of candy-layout's supported surface —
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
        public float $constant = 0.0,
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
