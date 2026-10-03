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
    /** @var bool tracks whether the cassowary warning has been emitted */
    private static bool $cassowaryWarningEmitted = false;

    /**
     * Pick the layout solver the environment asks for.
     *
     * Respects SUGARCRAFT_LAYOUT_SOLVER env var:
     *  - "cassowary" → CassowarySolver
     *  - otherwise   → GreedySolver (default)
     *
     * candy-layout's CassowarySolver is deprecated: its simplex never
     * converged, so its `solve()` now delegates wholly to GreedySolver
     * (results are identical) and raises E_USER_DEPRECATED on every call.
     * Selecting it is therefore pointless, so the opt-in raises a one-time
     * E_USER_WARNING saying so; whether that is shown is the host's
     * `error_reporting` / error-handler decision.
     */
    public static function fromEnvironment(): LayoutSolver
    {
        $env = getenv('SUGARCRAFT_LAYOUT_SOLVER');
        if ($env === 'cassowary') {
            if (!self::$cassowaryWarningEmitted) {
                trigger_error(
                    'SUGARCRAFT_LAYOUT_SOLVER=cassowary selects the deprecated CassowarySolver, '
                    . 'which now delegates every solve to GreedySolver (identical results) and '
                    . 'raises E_USER_DEPRECATED on each call. Unset the variable or use "greedy". '
                    . 'This warning fires once per process.',
                    E_USER_WARNING,
                );
                self::$cassowaryWarningEmitted = true;
            }
            return new CassowarySolver();
        }
        return new GreedySolver();
    }
}
