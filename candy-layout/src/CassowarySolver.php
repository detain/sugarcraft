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
 * `SUGARCRAFT_LAYOUT_SOLVER=cassowary`; with that env var set, sprinkles'
 * `Layout\Solver` routes every split through this class's `solve()`, so the
 * deprecation fires there on each solve. The notice is NOT `@`-suppressed:
 * whether it is shown or logged is the host's `error_reporting` /
 * error-handler decision, never this library's.
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
        trigger_error(
            'CassowarySolver never converges and is deprecated; delegating to GreedySolver. Use GreedySolver directly.',
            E_USER_DEPRECATED,
        );

        return (new GreedySolver())->solve($region, $dir, $constraints);
    }
}

// ─── Former simplex helpers ─────────────────────────────────────────────────
// Expression (src/Expression.php) is retained as an @internal standalone
// linear-expression value object — no solver consumes it any more; see its
// class docblock. The other former simplex helpers (Variable, Relation,
// ConstraintDef, EditInfo, Tableau) were internal to the removed pivot loop
// and were deleted along with it.
