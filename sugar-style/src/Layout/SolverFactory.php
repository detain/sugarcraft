<?php

declare(strict_types=1);

namespace SugarCraft\Style\Layout;

use SugarCraft\Constraints\CassowarySolver;
use SugarCraft\Constraints\GreedySolver;
use SugarCraft\Constraints\LayoutSolver;

/**
 * Factory for creating a {@see LayoutSolver} instance.
 *
 * Respects the SUGARCRAFT_LAYOUT_SOLVER env var:
 *  - "cassowary" → CassowarySolver (deprecated; delegates to GreedySolver)
 *  - otherwise   → GreedySolver (default)
 *
 * The variable is a boot-time setting: it is read ONCE per process and the
 * decision memoized, so per-split solves cost no getenv. A mid-process
 * change takes effect only via {@see resetEnvironmentResolutionForTesting()}
 * (the test seam) or a fresh process. A fresh solver instance is still
 * constructed on every call — the factory caches the decision, never an
 * instance.
 *
 * Mirrors ratatui's pluggable solver architecture.
 */
final class SolverFactory
{
    /** @var bool tracks whether the cassowary deprecation notice has been raised */
    private static bool $cassowaryNoticeEmitted = false;

    /** @var bool|null the once-resolved SUGARCRAFT_LAYOUT_SOLVER decision (null = not yet read) */
    private static ?bool $cassowaryRequested = null;

    /**
     * Drop the memoized environment decision and the notice latch.
     * Test seam: production code resolves exactly once and never calls this.
     */
    public static function resetEnvironmentResolutionForTesting(): void
    {
        self::$cassowaryRequested     = null;
        self::$cassowaryNoticeEmitted = false;
    }

    /**
     * Pick the layout solver the environment asks for.
     *
     * Respects SUGARCRAFT_LAYOUT_SOLVER env var:
     *  - "cassowary" → CassowarySolver
     *  - otherwise   → GreedySolver (default)
     *
     * sugar-constraints's CassowarySolver is deprecated: its simplex never
     * converged, so its `solve()` delegates wholly to GreedySolver (results
     * are identical — every constraint type, Ratio included, solves
     * correctly) and raises E_USER_DEPRECATED on every call.
     *
     * The opt-in is therefore a deprecated-but-harmless setting, not a
     * correctness hazard, so it is reported at deprecation severity: a
     * one-time E_USER_DEPRECATED naming the env var, which the solver's own
     * per-solve notice cannot (it does not know how it was selected). It used
     * to be an E_USER_WARNING because the solver then returned wrong sizes;
     * with that gone, a warning would only break hosts that escalate
     * warnings (PHPUnit `failOnWarning`, warning-to-exception handlers) over
     * a setting that changes nothing. Whether the notice is shown or logged
     * is the host's `error_reporting` / error-handler decision.
     */
    public static function fromEnvironment(): LayoutSolver
    {
        self::$cassowaryRequested ??= getenv('SUGARCRAFT_LAYOUT_SOLVER') === 'cassowary';
        if (self::$cassowaryRequested) {
            if (!self::$cassowaryNoticeEmitted) {
                self::$cassowaryNoticeEmitted = true;
                trigger_error(
                    'SUGARCRAFT_LAYOUT_SOLVER=cassowary selects the deprecated CassowarySolver, '
                    . 'which delegates every solve to GreedySolver (identical results). '
                    . 'Unset the variable or use "greedy". This notice fires once per process.',
                    E_USER_DEPRECATED,
                );
            }
            return new CassowarySolver();
        }
        return new GreedySolver();
    }

    /**
     * Alias of {@see fromEnvironment()}, kept so callers written before the
     * rename keep working (external consumers track dev-master).
     *
     * @deprecated Use {@see fromEnvironment()}, which names what it does.
     */
    public static function default(): LayoutSolver
    {
        return self::fromEnvironment();
    }
}
