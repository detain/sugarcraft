<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Layout;

/**
 * Static helpers for responsive layout breakpoints.
 *
 * The 90 / 140 cell defaults are the fleet-wide values every layout
 * component consumes when it does not override them; they split
 * terminal widths into narrow / medium / wide bands.
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
     * Each bound is an UPPER BOUND EXCLUSIVE of its bucket: a width is
     * assigned to the bucket with the smallest bound it does not reach.
     * Buckets are compared in bound order, never map-literal order, so a
     * map declared out of sequence still resolves correctly.
     *
     * A null bound is the catch-all bucket. When the map carries none, a
     * width above every bound falls into the WIDEST bucket rather than a
     * magic name: a desktop terminal must not receive the narrow layout,
     * and the 'narrow' string is only a fallback when the map has no
     * bounds to choose from at all.
     *
     * @param int                     $width       Current terminal width in cells.
     * @param array<string, int|null> $thresholds  Map of bucket name to upper bound, exclusive (null = catch-all).
     *
     * @return string The matched bucket name, or 'narrow' when the map is empty.
     */
    public static function pick(int $width, array $thresholds): string
    {
        if ($thresholds === []) {
            return 'narrow';
        }

        // Buckets ordered by ascending bound so iteration tests the
        // narrowest bound first regardless of declaration order.
        $ordered = $thresholds;
        uasort($ordered, static function (int|null $a, int|null $b): int {
            if ($a === null) {
                return $b === null ? 0 : 1;   // catch-all sorts last
            }
            if ($b === null) {
                return -1;
            }
            return $a <=> $b;
        });

        foreach ($ordered as $name => $bound) {
            if ($bound === null) {
                return $name;  // nothing narrower matched
            }
            if ($width < $bound) {
                return $name;
            }
        }

        // Above every bound with no catch-all: stay in the widest bucket.
        return (string) array_key_last($ordered);
    }
}
