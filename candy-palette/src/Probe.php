<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * Static probe for terminal color profile and environment-based color detection.
 *
 * Precedence order (implement exactly as specified):
 *  1. CLICOLOR_FORCE=1                    → TrueColor  (overrides everything)
 *  2. NO_COLOR (any value)                → NoTTY
 *  3. CLICOLOR=0                          → NoTTY
 *  4. TERM=dumb                           → NoTTY
 *  5. COLORTERM=24bit|truecolor|yes        → TrueColor
 *  6. WT_SESSION (set)                    → TrueColor  (Windows Terminal)
 *  7. GOOGLE_CLOUD_SHELL=true             → TrueColor
 *  8. TMUX || STY set + base TERM checks tmux/screen first
 *  9. TERM=xterm-kitty|xterm-ghostty|*-256color → Ansi256
 * 10. TERM=xterm*|screen*|tmux*            → Ansi
 * 11. Default                             → Ansi
 * 12. Optional Phase 2: infocmp Tc/RGB    → upgrade Ansi → TrueColor
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
final class Probe
{
    /**
     * Negotiated color profile after walking every environment variable in precedence order.
     *
     * Uses DetectionChain for core env-based detection, then applies
     * Probe-specific infocmp upgrade (phase 2).
     */
    public static function colorProfile(): ColorProfile
    {
        // Use DetectionChain for the core detection (steps 1-11)
        $chain = DetectionChain::detect();

        // Handle CLICOLOR_FORCE=1 separately since it's checked first
        // and DetectionChain doesn't differentiate force color from env-based detection
        if (self::isForceColor()) {
            return ColorProfile::TrueColor;
        }

        $profile = $chain->toColorProfile();

        // Phase 2: infocmp Tc/RGB upgrade (Probe-specific)
        if ($profile === ColorProfile::Ansi) {
            $upgraded = self::infocmpUpgrade(ColorProfile::Ansi);
            if ($upgraded === ColorProfile::TrueColor) {
                return ColorProfile::TrueColor;
            }
        }

        return $profile;
    }

    /**
     * NO_COLOR is set (any value, including empty string).
     */
    public static function isNoColor(): bool
    {
        return self::getEnv('NO_COLOR') !== null;
    }

    /**
     * CLICOLOR_FORCE=1 is set — forces TrueColor regardless of other settings.
     */
    public static function isForceColor(): bool
    {
        return self::getEnv('CLICOLOR_FORCE') === '1';
    }

    /**
     * Reduced motion preference — checks REDUCE_MOTION then PREFERS_REDUCED_MOTION.
     */
    public static function reducedMotion(): bool
    {
        $reduceMotion = self::getEnv('REDUCE_MOTION');
        if ($reduceMotion !== null && $reduceMotion !== '0' && $reduceMotion !== '') {
            return true;
        }

        $prefersReduced = self::getEnv('PREFERS_REDUCED_MOTION');
        if ($prefersReduced !== null && $prefersReduced !== '0' && $prefersReduced !== '') {
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Private helpers — env read (protected for testability)
    // -------------------------------------------------------------------------

    private static function getEnv(string $name): ?string
    {
        $value = $_ENV[$name] ?? (getenv($name) ?: null);
        return $value === false ? null : $value;
    }

    private static function term(): ?string
    {
        return self::getEnv('TERM');
    }

    private static function clicolor(): ?string
    {
        return self::getEnv('CLICOLOR');
    }

    private static function colorterm(): ?string
    {
        return self::getEnv('COLORTERM');
    }

    private static function wtSession(): ?string
    {
        return self::getEnv('WT_SESSION');
    }

    private static function googleCloudShell(): ?string
    {
        return self::getEnv('GOOGLE_CLOUD_SHELL');
    }

    private static function tmux(): ?string
    {
        return self::getEnv('TMUX');
    }

    private static function sty(): ?string
    {
        return self::getEnv('STY');
    }

    private static function termIsXterm(string $term): bool
    {
        return str_starts_with($term, 'xterm');
    }

    private static function termIsScreen(string $term): bool
    {
        return str_starts_with($term, 'screen');
    }

    private static function termIsTmux(string $term): bool
    {
        return str_starts_with($term, 'tmux');
    }

    private static function termIs256Color(string $term): bool
    {
        return str_contains($term, '-256color')
            || $term === 'xterm-kitty'
            || $term === 'xterm-ghostty';
    }

    /**
     * Optional Phase 2: parse infocmp for Tc/RGB capabilities; upgrade Ansi → TrueColor.
     */
    private static function infocmpUpgrade(ColorProfile $profile): ColorProfile
    {
        if ($profile !== ColorProfile::Ansi) {
            return $profile;
        }

        $term = self::term();
        if ($term === null || $term === '') {
            return $profile;
        }

        // Check if infocmp binary exists
        if (!self::infocmpAvailable()) {
            return $profile;
        }

        $output = @shell_exec(self::$infocmpPath . ' -1 ' . escapeshellarg($term) . ' 2>/dev/null');
        if ($output === null) {
            return $profile;
        }

        // Tc (True-color) or RGB (direct color) capability present
        if (preg_match('/\bTc\b/', $output) || preg_match('/\bRGB\b/', $output)) {
            return ColorProfile::TrueColor;
        }

        return $profile;
    }

    private static ?string $infocmpPath = null;

    private static function infocmpAvailable(): bool
    {
        if (self::$infocmpPath !== null) {
            return self::$infocmpPath !== '';
        }
        self::$infocmpPath = is_file('/usr/bin/infocmp') ? '/usr/bin/infocmp'
            : (is_file('/bin/infocmp') ? '/bin/infocmp' : '');
        return self::$infocmpPath !== '';
    }

    /**
     * Reset internal static state (for testing only).
     *
     * @internal
     */
    public static function _reset(array $overrides = []): void
    {
        self::$infocmpPath = null;
    }
}
