<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Shared value clamping utilities for the SugarCraft monorepo.
 * Original: clamping is a banal idiom repeated across the upstream; no
 * single mirrorable symbol exists, so the citation is deliberately
 * absent rather than invented (sugar-core-073).
 *
 * sc-38 role line: Clamp COERCES — an out-of-range input is totalled into
 * the nearest legal value and nothing is signalled. Where an out-of-range
 * input means the CALLER made a mistake that must surface, use
 * {@see Validation} (refuses at the boundary with a named throw); the two
 * families are deliberately distinct and neither forwards to the other.
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
