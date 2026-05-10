<?php

declare(strict_types=1);

namespace SugarCraft\Sprinkles\Layout;

/**
 * Base for all constraint types used by {@see Layout::split()}.
 *
 * Mirrors ratatui's `Constraint` enum — Length, Percentage, Min, Max,
 * Ratio, Fill — with a simple one-pass solver.
 */
abstract class Constraint
{
    private function __construct()
    {
    }

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
}
