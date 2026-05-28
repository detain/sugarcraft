<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Constraint;

/**
 * Base interface for all constraint types used by {@see LayoutSolver::solve()}.
 *
 * Mirrors ratatui's `Constraint` enum — Length, Percentage, Min, Max,
 * Ratio, Fill — solved via either Cassowary simplex or greedy fallback.
 */
abstract class Constraint
{
    private function __construct() {}

    /**
     * Fixed character-cell count.
     *
     * Mirrors ratatui `Constraint::Length(n)`.
     */
    public static function length(int $n): Length
    {
        return new Length($n);
    }

    /**
     * At least `$n` cells; takes more if space is available.
     *
     * Mirrors ratatui `Constraint::Min(n)`.
     */
    public static function min(int $n): Min
    {
        return new Min($n);
    }

    /**
     * Fills all remaining space; `$weight` controls proportional
     * distribution when multiple Fill constraints compete.
     *
     * Mirrors ratatui `Constraint::Fill(weight)`.
     */
    public static function fill(int $weight = 1): Fill
    {
        return new Fill($weight);
    }

    /**
     * Proportional size as a percentage (0-100) of the available area.
     *
     * Mirrors ratatui `Constraint::Percentage(n)`.
     */
    public static function percentage(int $n): Percentage
    {
        return new Percentage($n);
    }

    /**
     * Proportional size based on a ratio (numerator / denominator).
     *
     * Mirrors ratatui `Constraint::Ratio(n, d)`.
     */
    public static function ratio(int $numerator, int $denominator): Ratio
    {
        return new Ratio($numerator, $denominator);
    }

    /**
     * Upper-bound size cap; takes less if space is insufficient.
     *
     * Mirrors ratatui `Constraint::Max(n)`.
     */
    public static function max(int $n): Max
    {
        return new Max($n);
    }
}
