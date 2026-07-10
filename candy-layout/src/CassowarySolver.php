<?php

declare(strict_types=1);

namespace SugarCraft\Layout;

use SugarCraft\Layout\Constraint\Constraint;

/**
 * DEPRECATED constraint solver — retained for backward compatibility only.
 *
 * The original Big-M simplex prototype never converged: `solveCore()` hit its
 * 1000-pivot iteration cap on EVERY call — including trivial pure-Length sets —
 * and then silently returned partial/garbage state (the fail-fast guard was
 * commented out). Its Ratio path additionally returned 0 instead of the ratio
 * size. Rewriting the simplex is high-risk and out of scope, so `solve()` now
 * emits `E_USER_DEPRECATED` and delegates WHOLLY to {@see GreedySolver}, which
 * fully and correctly implements every constraint type
 * (Length/Min/Max/Fill/Percentage/Ratio). Delegating therefore also FIXES the
 * long-standing Ratio-returns-0 bug.
 *
 * The only in-repo caller is candy-sprinkles' `SolverFactory`, gated behind
 * `SUGARCRAFT_LAYOUT_SOLVER=cassowary`; it constructs this class but does not
 * call `solve()`.
 *
 * @deprecated Use {@see GreedySolver} directly. Kept only so existing
 *             `new CassowarySolver()` call-sites keep working.
 */
final class CassowarySolver implements LayoutSolver
{
    /**
     * Default factory — matches the LayoutSolver convention.
     */
    public static function new(): self
    {
        return new self();
    }

    /** @return GreedySolver */
    public static function greedy(): GreedySolver
    {
        return new GreedySolver();
    }

    /**
     * @return CassowarySolver
     */
    public static function cassowary(): CassowarySolver
    {
        return new self();
    }

    /**
     * Solve constraints against a region in the given direction.
     *
     * The simplex prototype never converged, so this hard-deprecated method
     * delegates entirely to {@see GreedySolver}. The signature is preserved to
     * honour the {@see LayoutSolver} contract.
     *
     * @deprecated Never converged; delegates to {@see GreedySolver}.
     *
     * @param list<Constraint> $constraints
     * @return list<Region>
     */
    public function solve(Region $region, Direction $dir, array $constraints): array
    {
        @trigger_error(
            'CassowarySolver never converges and is deprecated; delegating to GreedySolver. Use GreedySolver directly.',
            E_USER_DEPRECATED,
        );

        return (new GreedySolver())->solve($region, $dir, $constraints);
    }
}

// ─── Supporting value class ─────────────────────────────────────────────────
// Expression is a standalone linear-expression value object still covered by
// ExpressionTest; it is retained even though the simplex that consumed it is
// gone. The other former simplex helpers (Variable, Relation, ConstraintDef,
// EditInfo, Tableau) were internal to the removed pivot loop and have been
// deleted along with it.

/**
 * Linear expression: sum(a_i * x_i) + c.
 *
 * Immutable value object left over from the retired simplex prototype; kept as
 * a general-purpose linear-expression helper (see ExpressionTest).
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
