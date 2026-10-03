<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Util;

/**
 * A token count in the compact form every display surface shares: exact
 * below a thousand, then `12.4K`, then `2.1M`.
 *
 * One spelling, because the status bar's context readout, the agent rows and
 * the background-session rows all show token figures side by side, and exact
 * comma-grouped counts (`1,234,567 tok`) spent columns on precision nobody
 * reads at a glance. A round value drops its `.0` (`100K`, not `100.0K`).
 */
final class TokenCount
{
    public static function compact(int $tokens): string
    {
        $tokens = max(0, $tokens);
        if ($tokens < 1000) {
            return (string) $tokens;
        }

        // Rounded at the unit it will be printed in, so 999,950 is "1M"
        // rather than the "1000K" a K-first rounding would produce.
        if (round($tokens / 1000, 1) < 1000) {
            return self::trimmed($tokens / 1000) . 'K';
        }

        return self::trimmed($tokens / 1_000_000) . 'M';
    }

    private static function trimmed(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
