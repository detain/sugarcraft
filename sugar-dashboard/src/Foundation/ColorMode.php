<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Foundation;

use SugarCraft\Core\Util\ColorProfile;

/**
 * Process-wide color capability for every renderer in this library.
 *
 * Charts, gauges and chrome used to emit `ColorProfile::TrueColor`
 * unconditionally: a NO_COLOR user or a TERM=dumb terminal still received
 * `38;2;r;g;b` escapes (finding A3/M-17). Every egress now asks this class.
 *
 * Resolution order:
 *   1. An explicit override — hosts that know their real destination should
 *      seed the full chain with `ColorMode::use(ColorProfile::detect(null, $stdout))`.
 *   2. `NO_COLOR` set to ANY non-empty value → `Ascii` (no-color.org: colour
 *      off, decoration kept; an empty value does NOT disable).
 *   3. `TERM=dumb` → `Ascii`.
 *   4. Otherwise `TrueColor` — the historical default, so piped and
 *      unconfigured output stays byte-identical to every pre-fix capture.
 *
 * The answer is memoised: an environment snapshot is taken once per process
 * (or per resetForTesting()/use() call) because flipping capability
 * mid-frame would desync a running dashboard's repaints.
 */
final class ColorMode
{
    private static ?ColorProfile $override = null;

    private static ?ColorProfile $resolved = null;

    /**
     * Pin the capability explicitly; pass null to fall back to the chain.
     */
    public static function use(?ColorProfile $profile): void
    {
        self::$override = $profile;
        self::$resolved = null;
    }

    public static function profile(): ColorProfile
    {
        return self::$resolved ??= self::$override ?? self::chain();
    }

    /**
     * Drop the memo and the override so the next read re-snapshots the
     * environment. Test-only seam.
     */
    public static function resetForTesting(): void
    {
        self::$override = null;
        self::$resolved = null;
    }

    private static function chain(): ColorProfile
    {
        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return ColorProfile::Ascii;
        }

        if (getenv('TERM') === 'dumb') {
            return ColorProfile::Ascii;
        }

        return ColorProfile::TrueColor;
    }
}
