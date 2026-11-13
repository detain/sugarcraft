<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Support;

/**
 * Range-pin ingestion guard shared by every axis-range setter.
 *
 * A chart accepts an explicit axis range (min/max pair) that overrides
 * auto-scaling; `null` on either end means "auto for that end" and stays
 * legal. Anything non-null must be finite (NaN silently defeats every
 * `$min == $max` and clamp guard downstream), and the resulting pair must
 * be ordered — an inverted (min > max) pair makes every normalization
 * division negative, mapping the whole series off-canvas with no error.
 *
 * Following the SugarCraft "no silent failures" convention, violations
 * throw at the setter that introduces them, so the stored state is always
 * a usable axis domain (parse at the boundary, trust it internally).
 */
final class Range
{
    private function __construct() {}

    /**
     * Assert one axis pair is a legal explicit range.
     *
     * @param string $axis human name ("Y", "X", "value") used in the error message
     *
     * @throws \InvalidArgumentException on a non-finite endpoint or min > max
     */
    public static function pin(?float $min, ?float $max, string $axis): void
    {
        if ($min !== null) {
            Finite::assert($min);
        }
        if ($max !== null) {
            Finite::assert($max);
        }
        if ($min !== null && $max !== null && $min > $max) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid %s range: min (%s) must not exceed max (%s)',
                $axis,
                (string) $min,
                (string) $max,
            ));
        }
    }

    /**
     * Combine pinned ends with the scanned data extents BEFORE anything
     * normalizes by (max − min) (sugar-charts-056). A lone pinned end is
     * legal at the door, but if the resolved pair comes out inverted —
     * e.g. a pinned min above every sample — the old silent clamp drew
     * all-zero bars, flat candles, and a misleading steady mid-glyph.
     * Fail loudly instead: the chart refuses to render a domain that
     * cannot contain its own data.
     *
     * @return array{0: float, 1: float} the effective [min, max] pair
     *
     * @throws \InvalidArgumentException when the resolved min exceeds the resolved max
     */
    public static function resolve(?float $pinMin, ?float $pinMax, float $scanMin, float $scanMax, string $axis): array
    {
        $min = $pinMin ?? $scanMin;
        $max = $pinMax ?? $scanMax;
        if ($min > $max) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid %s range: min (%s) must not exceed max (%s) — a lone pinned end that excludes the whole data set leaves no drawable domain',
                $axis,
                (string) $min,
                (string) $max,
            ));
        }
        return [$min, $max];
    }
}
