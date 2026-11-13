<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Support;

/**
 * Locale-safe numeric label rendering for axis gutters and corners.
 *
 * Whole values print bare ('8'); fractions keep at most two decimals
 * with trailing zeros stripped ('2.5'). `number_format` is called with
 * explicit separators so a hostile LC_NUMERIC (comma-decimal locales)
 * can never inject ',' into cell geometry (sugar-charts-045 posture).
 *
 * Antecedent: Heatmap::formatLabel, lifted to shared use by CH-09
 * (sugar-charts-033) — the Heatmap and axis gutters must speak with
 * one voice.
 */
final class NumberLabel
{
    private function __construct()
    {
    }

    public static function format(float $v): string
    {
        return $v == (int) $v
            ? (string) (int) $v
            : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }
}
