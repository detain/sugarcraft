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

// ─── Supporting helper class ────────────────────────────────────────────────
// Expression is a standalone linear-expression helper still covered by
// ExpressionTest; it is retained even though the simplex that consumed it is
// gone. The other former simplex helpers (Variable, Relation, ConstraintDef,
// EditInfo, Tableau) were internal to the removed pivot loop and have been
// deleted along with it.
