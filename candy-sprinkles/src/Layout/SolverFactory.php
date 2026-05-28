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
 *  - "greedy"  → GreedySolver
 *  - otherwise → CassowarySolver (default)
 *
 * Mirrors ratatui's pluggable solver architecture.
 */
final class SolverFactory
{
    /**
     * Create the default layout solver based on environment.
     *
     * Respects SUGARCRAFT_LAYOUT_SOLVER env var:
     *  - "greedy"  → GreedySolver (original ratatui-inspired algorithm)
     *  - otherwise  → CassowarySolver (linear-arithmetic constraint solver, default)
     *
     * CassowarySolver is default because it matches upstream ratatui behaviour.
     * GreedySolver is available as an escape hatch for the 14 known Ratio-bug
     * failures when using CassowarySolver.
     */
    public static function default(): LayoutSolver
    {
        $env = getenv('SUGARCRAFT_LAYOUT_SOLVER');
        if ($env === 'greedy') {
            return new GreedySolver();
        }
        return new CassowarySolver();
    }
}
