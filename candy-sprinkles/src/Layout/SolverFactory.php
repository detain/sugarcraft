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
 *  - "cassowary" → CassowarySolver
 *  - otherwise   → GreedySolver (default)
 *
 * Mirrors ratatui's pluggable solver architecture.
 */
final class SolverFactory
{
    /** @var bool tracks whether the cassowary warning has been emitted */
    private static bool $cassowaryWarningEmitted = false;

    /**
     * Create the default layout solver based on environment.
     *
     * Respects SUGARCRAFT_LAYOUT_SOLVER env var:
     *  - "cassowary" → CassowarySolver (linear-arithmetic constraint solver)
     *  - otherwise   → GreedySolver (original ratatui-inspired algorithm, default)
     *
     * GreedySolver is the default because CassowarySolver has a pre-existing bug
     * where Ratio constraints (and others) return 0 instead of the expected value.
     * This causes 14 test failures when using CassowarySolver.
     * CassowarySolver is available via env var for comparison/testing purposes.
     * When SUGARCRAFT_LAYOUT_SOLVER=cassowary is set, a one-time E_USER_WARNING
     * is emitted to alert callers of the known bug.
     */
    public static function default(): LayoutSolver
    {
        $env = getenv('SUGARCRAFT_LAYOUT_SOLVER');
        if ($env === 'cassowary') {
            if (!self::$cassowaryWarningEmitted) {
                trigger_error(
                    'SUGARCRAFT_LAYOUT_SOLVER=cassowary is set: note that CassowarySolver '
                    . 'has a known Ratio-constraint bug that returns 0 instead of the '
                    . 'expected value (see CALIBER_LEARNINGS bug:cassowary-solver-ratio). '
                    . 'GreedySolver is recommended. This warning fires once per process.',
                    E_USER_WARNING,
                );
                self::$cassowaryWarningEmitted = true;
            }
            return new CassowarySolver();
        }
        return new GreedySolver();
    }
}
