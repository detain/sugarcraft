<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

/**
 * Static helpers for responsive layout breakpoints.
 *
 * Defaults (90 / 140) are the Homedash convention values and serve
 * as a sensible baseline for terminal UI layouts.
 */
final class Breakpoint
{
    /**
     * True when the terminal width is considered narrow.
     *
     * A narrow layout collapses multi-column arrangements into a
     * single column so content remains readable on small terminals.
     *
     * @param int $width     Current terminal width in cells.
     * @param int $threshold  Upper bound (exclusive) for narrow — default 90.
     */
    public static function narrow(int $width, int $threshold = 90): bool
    {
        return $width < $threshold;
    }

    /**
     * True when the terminal width is in the "medium" range.
     *
     * Medium means the terminal is neither narrow nor wide — e.g. a
     * standard 100-cell wide terminal falls here when defaults are used.
     *
     * @param int $width   Current terminal width in cells.
     * @param int $narrow  Upper bound (exclusive) for narrow — default 90.
     * @param int $wide    Lower bound (inclusive) for wide — default 140.
     */
    public static function medium(int $width, int $narrow = 90, int $wide = 140): bool
    {
        return $width >= $narrow && $width < $wide;
    }

    /**
     * True when the terminal width is considered wide.
     *
     * A wide layout can accommodate multi-column side-by-side arrangements.
     *
     * @param int $width     Current terminal width in cells.
     * @param int $threshold Lower bound (inclusive) for wide — default 140.
     */
    public static function wide(int $width, int $threshold = 140): bool
    {
        return $width >= $threshold;
    }

    /**
     * Pick a bucket name from a thresholds map based on current width.
     *
     * Example:
     *   Breakpoint::pick(80, ['narrow' => 90, 'medium' => 140, 'wide' => null])
     *   // → 'narrow'
     *
     * Thresholds are treated as lower-inclusive bounds. The last entry
     * with a null threshold is the catch-all "wide" bucket.
     *
     * @param array<string, int|null> $thresholds  Map of bucket name to lower bound (null = catch-all).
     * @param int                     $width      Current terminal width in cells.
     *
     * @return string The matched bucket name, or 'narrow' as fallback.
     */
    public static function pick(int $width, array $thresholds): string
    {
        foreach ($thresholds as $name => $bound) {
            if ($bound === null) {
                continue;
            }
            if ($width >= $bound) {
                continue;
            }
            return $name;
        }

        // No threshold matched — fall back to the catch-all (null bound) if present,
        // otherwise 'narrow' as a safe default.
        foreach ($thresholds as $name => $bound) {
            if ($bound === null) {
                return $name;
            }
        }

        return 'narrow';
    }
}
