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
}
