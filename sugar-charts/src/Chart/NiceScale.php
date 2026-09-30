<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

use SugarCraft\Charts\Support\Finite;

/**
 * Axis auto-scaling — round a data maximum up to a "nice" round ceiling.
 *
 * A streaming chart (sparkline / line chart) needs a stable upper bound for
 * its Y axis so the plot does not jitter on every new sample. Snapping the
 * observed max up to the next round number — leading digit incremented, the
 * remaining digits zeroed — yields a ceiling that only steps when the data
 * crosses a round boundary, with a floor of 100 so tiny series still get a
 * sensible axis.
 *
 * @see Mirrors mysql-workbench/charting.py DBTimeLineGraph.auto_scale ceiling logic
 */
final class NiceScale
{
    /** Smallest ceiling returned, so a near-flat series still gets a usable axis. */
    public const FLOOR = 100.0;

    private function __construct() {}

    /**
     * Compute a "nice ceiling" for a given data maximum.
     *
     * Takes the integer part of the max as a decimal string, increments the
     * leading digit, and zeros the remaining digits — e.g. 4500 → 5000,
     * 9000 → 10000 (carry widens by one digit rather than wrapping to 0),
     * 45 → 100 (floor). Non-positive maxima, and any result below the floor,
     * return {@see FLOOR}.
     *
     * FINITE DOMAIN (audit F2 fix): the digit walk runs on a formatted
     * decimal string and the scale is recombined as a FLOAT product, so the
     * guarantee "result >= max" holds for every accepted input. The former
     * `(string)(int)$max` saturated at PHP_INT_MAX — ceiling(1e20) answered
     * 8e18, BELOW its own input, silently clipping live consumer axes — and
     * the `(int)` concat overflowed for any ≥2^63 magnitude. Maxima so large
     * that the widened round ceiling would overflow a double (above
     * ~9e307) are rejected loudly rather than wrapped or clamped.
     */
    public static function ceiling(float $max): float
    {
        // Ingestion guard: NaN slips past `$max <= 0` (any comparison with
        // NaN is false), so reject non-finite maxima before scaling.
        Finite::assert($max);

        if ($max <= 0) {
            return self::FLOOR;
        }

        // floor() keeps the "integer part" semantics of the old (int) cast:
        // 9999.6 widens off 9999 → 10000, not off the rounded 10000 → 20000.
        $digits = sprintf('%.0f', floor($max));
        $leading = (int) $digits[0] + 1;
        if ($leading > 9) {
            // 9xxx rolls over to 10xxx — widen rather than wrap the leading digit.
            $leading = 10;
        }
        $scale = (float) $leading * (10.0 ** (strlen($digits) - 1));
        if (!is_finite($scale)) {
            throw new \InvalidArgumentException(sprintf(
                'NiceScale::ceiling(%s) overflows the finite ceiling domain (max ~9e307); no finite round ceiling exists',
                (string) $max,
            ));
        }

        return max($scale, self::FLOOR);
    }
}
