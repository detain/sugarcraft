<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * Human-readable byte counts — the subset of btop floating_humanizer the
 * frame needs today (binary units, two significant decimals below 10).
 *
 * Mirrors aristocratos/btop Tools::floating_humanizer (src/btop_tools.cpp).
 */
final class Units
{
    private const UNITS = ['Byte', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB'];

    private function __construct()
    {
    }

    public static function bytes(int|float $value, bool $perSecond = false): string
    {
        $value = max(0.0, (float) $value);
        $i = 0;
        while ($value >= 1024.0 && $i < count(self::UNITS) - 1) {
            $value /= 1024.0;
            $i++;
        }
        $text = match (true) {
            $i === 0 => (string) (int) $value,
            $value < 10.0 => number_format($value, 2, '.', ''),
            $value < 100.0 => number_format($value, 1, '.', ''),
            default => (string) (int) round($value),
        };

        return $text . ' ' . self::UNITS[$i] . ($perSecond ? '/s' : '');
    }
}
