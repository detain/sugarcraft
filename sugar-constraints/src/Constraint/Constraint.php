<?php

declare(strict_types=1);

namespace SugarCraft\Constraints\Constraint;

/**
 * Abstract base class for all constraint types used by {@see LayoutSolver::solve()}.
 *
 * The static factories below are the public spelling; the concrete types live
 * in this namespace (Length, Percentage, Min, Max, Ratio, Fill) and each has a
 * value-refusing constructor.
 *
 * Mirrors ratatui's `Constraint` enum — solved by {@see GreedySolver}; the
 * deprecated {@see CassowarySolver} is a shim that delegates to it.
 */
abstract class Constraint
{
    /**
     * Fixed character-cell count.
     *
     * Plain "fixed" overstates it — unless the set carries neither Min nor
     * Fill AND a Max clamp hands cells back, the reclaim ladder inflates it:
     * [length(10), max(5)]@100 solves to [95, 5]. See the README Tiling
     * guarantee (ladder step 6) and
     * {@see \SugarCraft\Constraints\GreedySolver::applyMaxClamp()}.
     *
     * Mirrors ratatui `Constraint::Length(n)`.
     */
    public static function length(int $n): Length
    {
        return new Length($n);
    }

    /**
     * At least `$n` cells — a soft floor. Grows only when slack survives every
     * Fill and Max in the set (they eat slack first); does not guarantee `$n`
     * when demand exceeds the area.
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
     * Upper-bound size cap; takes slack greedily (proportional to its cap
     * among the slack consumers) but never more than `$n`. The clamp pass can
     * also give cells BACK from this type when the greedy share rode over the
     * cap — the hand-back rides Min first, then Fill, and reaches
     * Length/Percentage/Ratio only in a set with neither.
     *
     * Mirrors ratatui `Constraint::Max(n)`.
     */
    public static function max(int $n): Max
    {
        return new Max($n);
    }
}
