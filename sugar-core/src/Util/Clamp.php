<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Shared value clamping utilities for the SugarCraft monorepo.
 * Mirrors charmbracelet/<repo>.clamp helpers.
 */
final class Clamp
{
    /**
     * Clamp an integer to the closed interval [min, max].
     * Returns min when $value < min, max when $value > max.
     */
    public static function int(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    /**
     * Clamp a float to the closed interval [min, max].
     */
    public static function float(float $value, float $min, float $max): float
    {
        return max($min, min($max, $value));
    }

    /**
     * Clamp a value to [0, max] (non-negative range).
     * Shorthand for int($value, 0, $max).
     */
    public static function nonNeg(int $value, int $max = PHP_INT_MAX): int
    {
        return max(0, min($max, $value));
    }

    /**
     * Clamp byte value to [0, 255] range.
     * Used primarily by color components.
     */
    public static function byte(int $value): int
    {
        return max(0, min(255, $value));
    }
}
