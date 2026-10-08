<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot;

use SugarCraft\Core\Util\Color;

/**
 * btop's process-list distance fade: rows dim toward inactive_fg the
 * farther they sit from the selected row, and each metric column (cpu,
 * mem, threads) blends its value color back toward grey by the same
 * distance.
 *
 * Two derived ramps drive it, both injected by btop's theme loader rather
 * than defined by any theme file: `proc` (main_fg → inactive_fg) for the
 * text fade and `proc_color` (inactive_fg → process_start) for the dimmed
 * half of the metric blend. {@see ramp()} builds either with btop's
 * integer law.
 *
 * Indexing follows Proc::draw exactly: `$selected` is 1-based (0 = no
 * selection) while the row index is 0-based, so `|selected - row|` is 1
 * on the selected row itself and 0 on the row just below it. That
 * off-by-one is btop's, kept so a port renders the same shading: the row
 * under the cursor's bar is drawn at full brightness, the row above it
 * one step dimmer.
 *
 * Mirrors aristocratos/btop Proc::draw (proc_gradient / proc_colors,
 * src/btop_draw.cpp) and Theme::generateGradients' proc / proc_color
 * injection (src/btop_theme.cpp).
 */
final class DistanceFade
{
    private function __construct()
    {
    }

    /**
     * btop's two-stop ramp (`proc`: main_fg → inactive_fg, `proc_color`:
     * inactive_fg → process_start).
     *
     * @return list<Color> 101 entries
     */
    public static function ramp(Color $from, Color $to): array
    {
        return Gradient101::expand([$from, $to]);
    }

    /** btop's `calc`: |selected − row|, selected 1-based (0 = none), row 0-based. */
    public static function distance(int $selected, int $row): int
    {
        return abs($selected - $row);
    }

    /**
     * Ramp index for the text fade: clamp(calc * 100 / select_max, 0, 100)
     * with C++ truncating division.
     *
     * @throws \InvalidArgumentException when $selectMax < 1 (btop divides by it)
     */
    public static function fadeIndex(int $calc, int $selectMax): int
    {
        self::assertSelectMax($selectMax);
        return max(0, min(100, intdiv($calc * 100, $selectMax)));
    }

    /**
     * Row text color: `Theme::g("proc").at(fadeIndex)`.
     *
     * @param list<Color> $procRamp 101-entry main_fg → inactive_fg ramp
     * @throws \InvalidArgumentException on a ramp that is not 101 Colors or $selectMax < 1
     */
    public static function fadeColor(array $procRamp, int $calc, int $selectMax): Color
    {
        self::assertRamp($procRamp, 'proc');
        return $procRamp[self::fadeIndex($calc, $selectMax)];
    }

    /**
     * The blend position btop computes before picking a ramp:
     * `(min(v, 100) + 100) - calc * 100 / select_max`. Below 100 the
     * metric has been pulled into the grey `proc_color` half; at or above
     * it the remainder indexes `process`.
     *
     * @throws \InvalidArgumentException when $selectMax < 1
     */
    public static function metricPosition(int $value, int $calc, int $selectMax): int
    {
        self::assertSelectMax($selectMax);
        return (min($value, 100) + 100) - intdiv($calc * 100, $selectMax);
    }

    /**
     * Metric color with the distance blend (proc_gradient on):
     * position < 100 → proc_color[max(0, position)], else
     * process[clamp(position - 100, 0, 100)].
     *
     * @param list<Color> $procColorRamp 101-entry inactive_fg → process_start ramp
     * @param list<Color> $processRamp   101-entry process gradient
     * @throws \InvalidArgumentException on a malformed ramp or $selectMax < 1
     */
    public static function metricColor(
        array $procColorRamp,
        array $processRamp,
        int $value,
        int $calc,
        int $selectMax,
    ): Color {
        self::assertRamp($procColorRamp, 'proc_color');
        self::assertRamp($processRamp, 'process');
        $position = self::metricPosition($value, $calc, $selectMax);
        if ($position < 100) {
            return $procColorRamp[max(0, $position)];
        }
        return $processRamp[max(0, min(100, $position - 100))];
    }

    /**
     * Metric color with the distance blend off: `process[clamp(v, 0, 100)]`.
     *
     * @param list<Color> $processRamp
     * @throws \InvalidArgumentException on a malformed ramp
     */
    public static function flatMetricColor(array $processRamp, int $value): Color
    {
        self::assertRamp($processRamp, 'process');
        return $processRamp[max(0, min(100, $value))];
    }

    /**
     * The three integers btop feeds the metric law, each with its own
     * C++ coercion: cpu is `(int)round(cpu_p)`; mem is
     * `(int)round(mem * 100 / totalMem)` over unsigned integers, so the
     * division has already floored before round() sees it; threads are
     * `(int)threads / 3`, deliberately compressed so a 300-thread process
     * reads as "hot".
     *
     * @return array{0:int, 1:int, 2:int} [cpu, mem, threads]
     */
    public static function metricValues(float $cpuPercent, float $memPercent, int $threads): array
    {
        $cpu = is_finite($cpuPercent) ? (int) round(max(0.0, min(1.0e9, $cpuPercent))) : 0;
        $mem = is_finite($memPercent) ? (int) floor(max(0.0, min(1.0e9, $memPercent))) : 0;
        return [$cpu, $mem, intdiv(max(0, $threads), 3)];
    }

    private static function assertSelectMax(int $selectMax): void
    {
        if ($selectMax < 1) {
            throw new \InvalidArgumentException(sprintf(
                'DistanceFade needs selectMax >= 1, got %d',
                $selectMax,
            ));
        }
    }

    /**
     * @param array<mixed> $ramp
     */
    private static function assertRamp(array $ramp, string $name): void
    {
        if (count($ramp) !== 101 || !array_is_list($ramp) || !$ramp[0] instanceof Color || !$ramp[100] instanceof Color) {
            throw new \InvalidArgumentException(sprintf(
                'DistanceFade "%s" ramp must be a list of 101 Colors (see Gradient101::expand)',
                $name,
            ));
        }
    }
}
