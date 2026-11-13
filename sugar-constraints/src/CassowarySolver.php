<?php

declare(strict_types=1);

namespace SugarCraft\Constraints;

use SugarCraft\Constraints\Constraint\Constraint;

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
 * The live caller is sugar-style: its `Layout\Solver` consults
 * `SolverFactory::fromEnvironment()`, and with `SUGARCRAFT_LAYOUT_SOLVER=cassowary`
 * set, every split that lib performs routes through this class's `solve()`.
 * Two notices guard that route at deliberately different rates: the factory's
 * selection announcement latches ONCE PER PROCESS (sugar-style's own
 * `SolverFactory` owns that one), while this class's `E_USER_DEPRECATED`
 * fires on EVERY `solve()`, not merely at construction — the per-solve
 * loudness is the documented contract (pinned by sugar-style's
 * DocumentationFidelityTest), complementary to the factory latch, not a bug.
 * The notice is NOT `@`-suppressed:
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
