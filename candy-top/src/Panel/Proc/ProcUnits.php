<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

/**
 * btop's byte humanizer as the proc box uses it: "1.50 GiB" for the
 * detailed view, the shortened "1.5G" / "500B" form for the MemB and
 * IO/R / IO/W columns.
 *
 * Integer arithmetic exactly as btop's (value × 100, shifted by 10 bits
 * per unit, or divided by 1000 with base_10_sizes) so the digits match.
 *
 * Mirrors aristocratos/btop Tools::floating_humanizer (src/btop_tools.cpp).
 */
final class ProcUnits
{
    private const MEBI = ['Byte', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB', 'EiB', 'ZiB', 'YiB', 'RiB', 'QiB'];

    private const MEGA = ['Byte', 'kB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB', 'RB', 'QB'];

    private function __construct()
    {
    }

    public static function human(int|float $value, bool $shorten = false, int $start = 0, bool $perSecond = false, bool $mega = false): string
    {
        $units = $mega ? self::MEGA : self::MEBI;
        $v = (int) max(0, min(PHP_INT_MAX / 100, (float) $value)) * 100;
        if ($mega) {
            while ($v >= 100000) {
                $v = intdiv($v, 1000);
                $start++;
            }
        } else {
            while ($v >= 102400) {
                $v >>= 10;
                $start++;
            }
        }
        $start = min($start, count($units) - 1);
        $out = (string) $v;
        if (!$mega && strlen($out) === 4 && $start > 0) {
            $out = substr($out, 0, 2) . '.' . substr($out, 2, 1);
        } elseif (strlen($out) === 3 && $start > 0) {
            $out = substr($out, 0, 1) . '.' . substr($out, 1);
        } elseif (strlen($out) >= 2) {
            $out = substr($out, 0, -2);
        }
        if ($out === '') {
            $out = '0';
        }

        if ($shorten) {
            $sep = str_contains($out, '.');
            if ($sep) {
                $out = sprintf('%.1f', (float) $out);
            }
            if (strlen($out) > 3) {
                if ($sep) {
                    $out = sprintf('%.0f', (float) $out);
                } else {
                    $out = $out[0] . '.0';
                    $start = min($start + 1, count($units) - 1);
                }
            }
            $out .= $units[$start][0];
        } else {
            $out .= ' ' . $units[$start];
        }

        return $out . ($perSecond ? '/s' : '');
    }
}
