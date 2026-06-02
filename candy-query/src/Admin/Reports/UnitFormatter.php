<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\Reports;

use SugarCraft\Query\Admin\Format;

/**
 * Unit formatter for performance report column values.
 *
 * Applies appropriate unit formatting based on column type:
 * - Time: picoseconds → human-readable duration
 * - Bytes: raw bytes → IEC binary units (KiB, MiB, GiB)
 *
 * This formatter operates on raw values from sys schema x$ views
 * which store time in picoseconds and bytes as raw integers.
 *
 * @see Mirrors mysql-workbench wb_admin_perfschema_reports unit_formatters
 */
final class UnitFormatter
{
    private function __construct() {}

    /**
     * Format a value according to its column type.
     *
     * @param mixed  $value The raw value from the query result
     * @param string $type  The column type (int, bigint, float, time, bytes, string)
     * @return string The formatted value for display
     */
    public static function format(mixed $value, string $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return match ($type) {
            'time' => self::formatTime($value),
            'bytes' => self::formatBytes($value),
            'int', 'bigint' => self::formatInteger($value),
            'float' => self::formatFloat($value),
            default => (string) $value,
        };
    }

    /**
     * Format a picosecond value as human-readable duration.
     *
     * @param float|int|string $picoseconds
     */
    public static function formatTime(mixed $picoseconds): string
    {
        if (!is_numeric($picoseconds)) {
            return (string) $picoseconds;
        }

        return Format::picoseconds((float) $picoseconds);
    }

    /**
     * Format bytes using IEC binary units (KiB, MiB, GiB).
     *
     * Uses 1024-based scaling for proper binary unit display.
     *
     * @param float|int|string $bytes
     */
    public static function formatBytes(mixed $bytes): string
    {
        if (!is_numeric($bytes)) {
            return (string) $bytes;
        }

        return Format::scaleValue((float) $bytes);
    }

    /**
     * Format an integer value with thousands separator.
     *
     * @param int|float|string $value
     */
    public static function formatInteger(mixed $value): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        return number_format((int) $value);
    }

    /**
     * Format a float value with appropriate precision.
     *
     * @param float|int|string $value
     */
    public static function formatFloat(mixed $value): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        $float = (float) $value;
        if ($float === floor($float) && $float < 1e15) {
            return number_format((int) $float);
        }

        return number_format($float, 3);
    }
}
