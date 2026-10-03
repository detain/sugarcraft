<?php

declare(strict_types=1);

namespace SugarCraft\Layout;

/**
 * Interface for constraint-based layout solvers.
 *
 * Solves a list of constraints against a region in the given direction,
 * returning a list of sub-regions whose widths (or heights) sum to the
 * region's total dimension.
 *
 * Mirrors ratatui's layout constraint solving.
 *
 * The contract is `solve()` alone. Construction lives on the concrete classes
 * (`GreedySolver::new()`/`::greedy()`/`::compat()`, the deprecated
 * `CassowarySolver::new()`), so an implementer never has to know — or build —
 * any other solver.
 */
interface LayoutSolver
{
    /**
     * Solve constraints against a region in the given direction.
     *
     * @param Region $region      The available region to split.
     * @param Direction $dir    Horizontal or vertical split.
     * @param list<Constraint> $constraints  Ordered list of constraints.
     * @return list<Region>      Sub-regions in order.
     */
    public function solve(Region $region, Direction $dir, array $constraints): array;
}
