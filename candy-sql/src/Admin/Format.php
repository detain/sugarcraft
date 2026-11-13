<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Admin;

use SugarCraft\Core\Util\Format as CoreFormat;

/**
 * Formatting utilities for displaying database metrics.
 *
 * Thin delegate over {@see \SugarCraft\Core\Util\Format} — the byte/duration/
 * scale logic moved to sugar-core so the whole ecosystem can share it. This
 * class is retained as the established candy-sql entry point; prefer
 * `SugarCraft\Core\Util\Format` directly in new code.
 *
 * @see Mirrors jorgerojas26/lazysql Format utilities
 */
final class Format
{
    private function __construct() {}

    /** Binary (1024) scale with bare K/M/G/T suffix. */
    public static function scaleValue(float|int $value, int $decimals = 1): string
    {
        return CoreFormat::scaleValue($value, $decimals);
    }

    /** Decimal (1000) SI bytes (B/KB/MB/GB/TB/PB). */
    public static function siBytes(float|int $bytes, int $decimals = 1): string
    {
        return CoreFormat::siBytes($bytes, $decimals);
    }

    /** Picoseconds → human-readable duration. */
    public static function picoseconds(float|int $picoseconds): string
    {
        return CoreFormat::picoseconds($picoseconds);
    }

    /** Seconds → compact human-readable duration. */
    public static function duration(float|int $seconds): string
    {
        return CoreFormat::duration($seconds);
    }

    /**
     * Count with a correctly pluralised noun: "1 instrument", "2 instruments".
     *
     * SQL-P17 (adminpages-019): one shared count-plural convention for admin
     * copy, replacing the per-site `count === 1 ? '' : 's'` drift. Library
     * local on purpose — English pluralisation is copy concern of this lib,
     * not ecosystem-wide formatting, so it does not delegate to core.
     *
     * @param string $plural Optional irregular plural (defaults to singular + "s")
     */
    public static function plural(int $count, string $singular, ?string $plural = null): string
    {
        $noun = $count === 1 ? $singular : ($plural ?? $singular . 's');

        return sprintf('%d %s', $count, $noun);
    }
}
