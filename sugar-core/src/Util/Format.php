<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Human-readable formatting for numbers, byte sizes, and time spans.
 *
 * Pure, allocation-light static helpers shared across the ecosystem
 * (charts axes, dashboards, metrics, log fields). Kept here in sugar-core
 * so libraries can format values without depending on a heavier component
 * lib.
 *
 * Two byte conventions are provided deliberately:
 *   - {@see scaleValue()} uses binary (1024) steps with bare K/M/G/T
 *     suffixes — matches `SHOW GLOBAL STATUS`-style counters.
 *   - {@see siBytes()} uses decimal (1000) steps with B/KB/MB/… suffixes.
 */
final class Format
{
    private function __construct() {}

    /**
     * Format a value with binary (1024-based) scale and a bare K/M/G/T suffix.
     *
     * Values below 1024 render as the integer/float itself with no suffix.
     */
    public static function scaleValue(float|int $value, int $decimals = 1): string
    {
        if ($value < 0) {
            return '-' . self::scaleValue(-$value, $decimals);
        }
        if ($value < 1024) {
            return (string) $value;
        }

        $suffixes = ['', 'K', 'M', 'G', 'T'];
        $i = 0;
        $v = (float) $value;
        $suffixCount = count($suffixes);
        while ($v >= 1024 && $i < $suffixCount - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, $decimals) . $suffixes[$i];
    }

    /**
     * Format a byte count with decimal (1000-based) SI units (B/KB/MB/GB/TB/PB).
     */
    public static function siBytes(float|int $bytes, int $decimals = 1): string
    {
        if ($bytes < 0) {
            return '-' . self::siBytes(-$bytes, $decimals);
        }
        if ($bytes < 1000) {
            return $bytes . 'B';
        }

        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $v = (float) $bytes;
        $suffixCount = count($suffixes);
        while ($v >= 1000 && $i < $suffixCount - 1) {
            $v /= 1000;
            $i++;
        }

        return round($v, $decimals) . $suffixes[$i];
    }

    /**
     * Format a byte count with binary (1024-based) IEC units: `B`, `KiB`,
     * `MiB`, `GiB`, `TiB`, `PiB`.
     *
     * Counts below 1 KiB render as whole bytes (`512 B`); larger counts
     * render with exactly one decimal (`4.0 KiB`, `1.5 MiB`). A value that
     * rounds up to 1024 of one unit is promoted to the next, so the output
     * never reads `1024.0 KiB`. PiB is the largest unit, so very large counts
     * grow the PiB figure rather than changing unit. Negative counts keep a
     * leading minus sign.
     *
     * Shaped after the byte-size copies that migrate to it (`candy-code`
     * DoctorChecks, `sugar-animation` Decoder): one binary unit set, one
     * decimal, one space before the unit. Those consumers move in later work
     * packages; this helper changes no caller.
     */
    public static function iecBytes(int $bytes): string
    {
        $sign = $bytes < 0 ? '-' : '';
        // abs() on the float view: -PHP_INT_MIN overflows an int.
        $value = abs((float) $bytes);
        if ($value < 1024) {
            return $sign . (string) (int) $value . ' B';
        }

        $units = ['KiB', 'MiB', 'GiB', 'TiB', 'PiB'];
        $i = 0;
        $last = count($units) - 1;
        $value /= 1024;
        while (round($value, 1) >= 1024 && $i < $last) {
            $value /= 1024;
            $i++;
        }

        return $sign . number_format($value, 1, '.', '') . ' ' . $units[$i];
    }

    /**
     * Format a picosecond count into a human-readable duration.
     *
     * Steps ps → us → ms → s, then composes m/h for longer spans. Useful for
     * Performance-Schema-style timer values.
     */
    public static function picoseconds(float|int $picoseconds): string
    {
        if ($picoseconds < 1000) {
            return round($picoseconds, 3) . 'ps';
        }
        if ($picoseconds < 1_000_000) {
            return round($picoseconds / 1000, 3) . 'us';
        }
        if ($picoseconds < 1_000_000_000) {
            return round($picoseconds / 1_000_000, 3) . 'ms';
        }
        if ($picoseconds < 60 * 1_000_000_000) {
            return round($picoseconds / 1_000_000_000, 3) . 's';
        }

        $totalSeconds = $picoseconds / 1_000_000_000;
        $minutes = (int) ($totalSeconds / 60);
        $seconds = $totalSeconds - ($minutes * 60);

        if ($minutes >= 60) {
            $hours = (int) ($minutes / 60);
            $minutes %= 60;
            return sprintf('%dh %02dm %02ds', $hours, $minutes, (int) $seconds);
        }

        return sprintf('%dm %02ds', $minutes, (int) $seconds);
    }

    /**
     * Format a second count into a compact human-readable duration string.
     *
     * Examples: `42.0s`, `3m 5s`, `2h 07m`, `5h`.
     */
    public static function duration(float|int $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }

        $minutes = (int) ($seconds / 60);
        // Cast the remainder to int up front: comparing a float remainder with
        // `=== 0.0` silently failed for integer inputs (0 !== 0.0), printing a
        // spurious "5m 0s" instead of "5m".
        $remainingSeconds = (int) ($seconds - ($minutes * 60));

        if ($minutes >= 60) {
            $hours = (int) ($minutes / 60);
            $minutes %= 60;
            if ($minutes === 0) {
                return $hours . 'h';
            }
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        if ($remainingSeconds === 0) {
            return $minutes . 'm';
        }

        return sprintf('%dm %ds', $minutes, $remainingSeconds);
    }
}
