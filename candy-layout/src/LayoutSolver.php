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
 * Mirrors ratatui's layout constraint solving (charmbracelet/bubbletea).
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

    /**
     * Create a new GreedySolver instance.
     */
    public static function greedy(): GreedySolver;

    /**
     * Create a new CassowarySolver instance.
     */
    public static function cassowary(): CassowarySolver;
}
