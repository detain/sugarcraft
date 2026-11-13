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
 *   2. `CLICOLOR_FORCE`/`FORCE_COLOR` truthy → skips 3-5 (the forcing
 *      convention shared with `ColorProfile::detect()`).
 *   3. `TERM=dumb` → `NoTty` — the SAME answer `ColorProfile::detect()`
 *      gives, checked before `NO_COLOR` exactly as detect orders them, so the
 *      two chains can never split-brain a dumb terminal (finding M-7:
 *      renderers used to ask `detect()` directly and collapse to plain text
 *      here while this chain kept emitting TrueColor bytes).
 *   4. `NO_COLOR` set to ANY non-empty value → `Ascii` (no-color.org: color
 *      off, decoration kept; an empty value does NOT disable). Deliberately
 *      presence-based: unlike `ColorProfile::detect()`'s truthiness test this
 *      honours `NO_COLOR=0`, matching the spec's "present, regardless of
 *      value" wording.
 *   5. `CLICOLOR=0` (the BSD opt-out) → `Ascii`.
 *   6. Otherwise `TrueColor` — the historical default, so piped and
 *      unconfigured output stays byte-identical to every pre-fix capture.
 *      `COLORTERM`, `TERM=*256color*` and CI hosts all land on this floor:
 *      the library funnels capability, it does not sub-tier negotiate it.
 *
 * The answer is memoised: an environment snapshot is taken once per process
 * (or per resetForTesting()/use() call) because flipping capability
 * mid-frame would desync a running dashboard's repaints. Every emission
 * site in src/ reads this class — none may call `ColorProfile::detect()`
 * on its own (pinned by tests/Foundation/ColorModeFunnelTest.php).
 *
 * Named divergences from `ColorProfile::detect()` (the README's Color
 * Environment paragraph documents them for readers; this list and
 * tests/Foundation/ColorModeDetectDivergenceTest.php are their source of
 * truth): NO_COLOR=0 disables color here while detect's truthiness test
 * ignores it; a force variable outranks NO_COLOR here while detect lets
 * NO_COLOR win; and the chain never negotiates sub-tiers — a
 * TERM=xterm-256color host answers TrueColor here versus detect's Ansi256
 * ladder (detect also consults COLORTERM, TERM_PROGRAM, CI, and a non-tty
 * stdout, where this chain stays byte-identical under a pipe by design).
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
        $force = self::envTruthy('CLICOLOR_FORCE') || self::envTruthy('FORCE_COLOR');

        if (!$force) {
            // Lowercased compare mirrors detect's strtolower — 'DUMB' too.
            if (strtolower((string) (getenv('TERM') ?: '')) === 'dumb') {
                return ColorProfile::NoTty;
            }

            $noColor = getenv('NO_COLOR');
            if ($noColor !== false && $noColor !== '') {
                return ColorProfile::Ascii;
            }

            if (getenv('CLICOLOR') === '0') {
                return ColorProfile::Ascii;
            }
        }

        return ColorProfile::TrueColor;
    }

    /**
     * The forcing convention shared with ColorProfile::detect(): anything
     * set except '', '0' or 'false' counts.
     */
    private static function envTruthy(string $key): bool
    {
        $value = getenv($key);
        return $value !== false && $value !== '' && $value !== '0' && strtolower($value) !== 'false';
    }
}
