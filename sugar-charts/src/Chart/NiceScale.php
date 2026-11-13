<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Chart;

use SugarCraft\Charts\Canvas\Graph;
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
     * Finite domain: the digit walk runs on a formatted
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

    /**
     * Ascending "nice" tick values covering `[$min, $max]` — the ladder the
     * default (formatter-less) axes label with (sugar-charts-026).
     *
     * Delegates to {@see Graph::niceNumbers()}, the lib's single
     * rung-ladder implementation (1/2/5 × 10^e), and drops rungs that fall
     * outside the plotted domain so every returned tick maps to a real row.
     * The ladder counts boundary-inclusive ticks, so it is asked for one
     * rung more than `$labelBudget` — the emitted set then stays at least as
     * dense as the even-slot interpolation the axes used before the ladder.
     * Degenerate (empty) range returns no ticks: there is nothing to label.
     *
     * @return list<float> ascending
     */
    public static function ticks(float $min, float $max, int $labelBudget = 4): array
    {
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        if ($min === $max) {
            return [];
        }
        $ladder  = Graph::niceNumbers($min, $max, max(2, $labelBudget + 1));
        $epsilon = ($max - $min) * 1e-9;
        return array_values(array_filter(
            $ladder,
            static fn(float $t): bool => $t >= $min - $epsilon && $t <= $max + $epsilon,
        ));
    }

    /**
     * Format ladder ticks as axis labels using the smallest rung spacing
     * (sugar-charts-026): a 1/2/5 × 10^e ladder needs exactly as many
     * decimals as its step, which kills the raw-float blurt a bare
     * `(string)` cast gives (0.1 + 0.1 + 0.1 === 0.30000000000000004).
     *
     * @param list<float> $ticks ascending or descending, evenly spaced
     * @return list<string> same order
     */
    public static function formatTicks(array $ticks): array
    {
        $step = INF;
        $prev = null;
        foreach ($ticks as $t) {
            if ($prev !== null) {
                $step = min($step, abs($t - $prev));
            }
            $prev = $t;
        }
        $decimals = !is_finite($step) || $step <= 0.0
            ? 0
            : max(0, (int) ceil(-log10($step) - 1e-9));

        return array_map(
            static function (float $v) use ($decimals): string {
                $s = number_format($v, $decimals, '.', '');
                return $decimals > 0 ? rtrim(rtrim($s, '0'), '.') : $s;
            },
            $ticks,
        );
    }
}
