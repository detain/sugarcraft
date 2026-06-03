<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

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
     */
    public static function ceiling(float $max): float
    {
        if ($max <= 0) {
            return self::FLOOR;
        }

        $digits = (string) (int) $max;
        $leading = (int) $digits[0] + 1;
        if ($leading > 9) {
            // 9xxx rolls over to 10xxx — widen rather than wrap the leading digit.
            $leading = 10;
        }
        $scale = (int) ($leading . str_repeat('0', strlen($digits) - 1));

        return max((float) $scale, self::FLOOR);
    }
}
