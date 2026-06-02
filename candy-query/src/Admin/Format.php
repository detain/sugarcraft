<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Formatting utilities for displaying database metrics.
 *
 * Provides methods to format byte values, durations, picoseconds,
 * and numeric scales (K/M/G/T).
 *
 * @see Mirrors charmbracelet/lazysql Format utilities
 */
final class Format
{
    private function __construct() {}

    /**
     * Format a value with 1024-based scale (K/M/G/T suffix).
     *
     * @param float|int $value
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
        while ($v >= 1024 && $i < count($suffixes) - 1) {
            $v /= 1024;
            $i++;
        }

        return round($v, $decimals) . $suffixes[$i];
    }

    /**
     * Format bytes using SI units (1000-based).
     *
     * @param float|int $bytes
     */
    public static function siBytes(float|int $bytes, int $decimals = 1): string
    {
        if ($bytes < 1000) {
            return $bytes . 'B';
        }

        $suffixes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $v = (float) $bytes;
        while ($v >= 1000 && $i < count($suffixes) - 1) {
            $v /= 1000;
            $i++;
        }

        return round($v, $decimals) . $suffixes[$i];
    }

    /**
     * Format picoseconds into human-readable duration.
     *
     * @param float|int $picoseconds
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
            $minutes = $minutes % 60;
            return sprintf('%dh %02dm %02ds', $hours, $minutes, (int) $seconds);
        }

        return sprintf('%dm %02ds', $minutes, (int) $seconds);
    }

    /**
     * Format seconds into human-readable duration string.
     *
     * @param float|int $seconds
     */
    public static function duration(float|int $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds, 1) . 's';
        }

        $minutes = (int) ($seconds / 60);
        $remainingSeconds = $seconds - ($minutes * 60);

        if ($minutes >= 60) {
            $hours = (int) ($minutes / 60);
            $minutes = $minutes % 60;
            if ($minutes === 0) {
                return $hours . 'h';
            }
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        if ($remainingSeconds === 0.0) {
            return $minutes . 'm';
        }

        return sprintf('%dm %ds', $minutes, (int) $remainingSeconds);
    }
}
