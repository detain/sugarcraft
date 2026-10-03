<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

use SugarCraft\Layout\CassowarySolver;
use SugarCraft\Layout\GreedySolver;
use SugarCraft\Layout\LayoutSolver;

/**
 * Factory for creating a {@see LayoutSolver} instance.
 *
 * Respects the SUGARCRAFT_LAYOUT_SOLVER env var:
 *  - "cassowary" → CassowarySolver (deprecated; delegates to GreedySolver)
 *  - otherwise   → GreedySolver (default)
 *
 * Mirrors ratatui's pluggable solver architecture.
 */
final class SolverFactory
{
    /** @var bool tracks whether the cassowary deprecation notice has been raised */
    private static bool $cassowaryNoticeEmitted = false;

    /**
     * Pick the layout solver the environment asks for.
     *
     * Respects SUGARCRAFT_LAYOUT_SOLVER env var:
     *  - "cassowary" → CassowarySolver
     *  - otherwise   → GreedySolver (default)
     *
     * candy-layout's CassowarySolver is deprecated: its simplex never
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
        $env = getenv('SUGARCRAFT_LAYOUT_SOLVER');
        if ($env === 'cassowary') {
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
