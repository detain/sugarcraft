<?php

declare(strict_types=1);

/**
 * CandyPalette — terminal color profile detection and color degradation.
 *
 * Port of charmbracelet/colorprofile providing:
 * - Detection of terminal color capability via environment + TTY inspection
 * - Color conversion (TrueColor → ANSI256 → ANSI16 → ASCII)
 * - ProfileWriter for automatic ANSI degradation on write
 * - NO_COLOR / FORCE_COLOR / COLORTERM standard env var support
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
namespace SugarCraft\Palette;

use SugarCraft\Palette\Color;
use SugarCraft\Palette\Profile;

/**
 * Detect and query the terminal color profile.
 *
 * Mirrors the top-level functions in charmbracelet/colorprofile.
 */
final class Palette
{
    private Profile $profile;

    /**
     * Detect the terminal color profile from the environment.
     *
     * Priority (aligned with Probe::colorProfile SSOT):
     *  1. CLICOLOR_FORCE=1   → TrueColor (SugarCraft extension)
     *  2. FORCE_COLOR=0..3   → profile by level (SugarCraft extension)
     *  3. NO_COLOR=          → NoTTY
     *  4. CLICOLOR=0         → NoTTY
     *  5. COLORTERM=24bit|truecolor|yes → TrueColor
     *  6. TERM_PROGRAM=iTerm.app → TrueColor
     *  7. TERM=dumb          → NoTTY
     *  8. WT_SESSION set     → TrueColor
     *  9. GOOGLE_CLOUD_SHELL=true → TrueColor
     * 10. TMUX||STY + TERM screen/tmux → ANSI256
     * 11. TERM=*-256color|xterm-kitty|xterm-ghostty → ANSI256
     * 12. TERM=xterm*|screen*|tmux* → ANSI
     * 13. TTY detection      → NoTTY if not a TTY
     * 14. Default            → ANSI
     *
     * @param resource|null $stream  Stream to check for TTY (default: STDOUT)
     * @param array<string,string|null> $env     Environment map (default: $_ENV)
     */
    public function __construct(
        $stream = null,
        array $env = [],
    ) {
        $this->profile = self::detectProfile($stream, $env);
    }

    /**
     * Get the detected profile.
     */
    public function profile(): Profile
    {
        return $this->profile;
    }

    /**
     * Override the detected profile (e.g. manually downgrade for testing).
     */
    public function withProfile(Profile $profile): self
    {
        $clone = clone $this;
        $clone->profile = $profile;
        return $clone;
    }

    /**
     * Shortcut: detect and return the profile enum.
     *
     * @param resource|null $stream
     * @param array<string,string|null> $env
     */
    public static function detect($stream = null, array $env = []): Profile
    {
        return (new self($stream, $env))->profile();
    }

    /**
     * Convert a color to the detected (or manually set) profile.
     */
    public function convert(Color $color): Color
    {
        return $color->convert($this->profile);
    }

    /**
     * Static shortcut for one-off color conversion.
     */
    public static function toProfile(Color $color, Profile $profile): Color
    {
        return $color->convert($profile);
    }

    /**
     * Convert any TrueColor/ANSI256/ANSI sequence in a string to
     * match the current profile, and strip if NoTTY.
     *
     * @param string $ansi  A string potentially containing SGR/CSI/OSC sequences
     * @return string       The string with color codes degraded/stripped
     */
    public function degrade(string $ansi): string
    {
        if ($this->profile === Profile::NoTTY) {
            return $this->stripAnsi($ansi);
        }

        if ($this->profile === Profile::TrueColor) {
            return $ansi; // No conversion needed
        }

        return $this->rewriteAnsi($ansi, $this->profile);
    }

    /**
     * Strip all ANSI escape sequences from a string.
     * Used when NoTTY is active.
     */
    public static function stripAnsi(string $s): string
    {
        // CSI sequences: \x1b[...{letter}
        // Extended CSI: \x1b[?... (private mode), \x1b[>... (private mode), \x1b[=... (private mode)
        // OSC sequences: \x1b]...(\x07|\x1b\\)
        // DCS sequences: \x1bP...(\x07|\x1b\\)
        // SS3 sequences: \x1bO{letter}
        // APC sequences: \x1b_...(\x07|\x1b\\)
        // Charset selectors: \x1b(B, \x1b(U, etc.
        return \preg_replace(
            '/(?:\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|'
            . '\x1b\[[0-9;:<>=?]*[A-Za-z]|'
            . '\x1b[PX^_][^\x07\x1b]*(?:\x07|\x1b\\\\)|'
            . '\x1b[OopeHMJKhCBDsu]|'
            . '\x1b[()*+][@-~])/',
            '',
            $s,
        ) ?? $s;
    }

    /**
     * Comment on the detected profile in a human-readable way.
     */
    public function comment(): string
    {
        return match ($this->profile) {
            Profile::TrueColor => 'fancy',
            Profile::ANSI256   => '1990s fancy',
            Profile::ANSI      => 'normcore',
            Profile::Ascii     => 'ancient',
            Profile::NoTTY     => 'naughty!',
        };
    }

    /**
     * Full descriptive sentence about the terminal's color capabilities.
     */
    public function describe(): string
    {
        return "Your terminal supports {$this->profile->label()} ({$this->profile->description()}).";
    }

    // -------------------------------------------------------------------------
    // Private detection logic
    // -------------------------------------------------------------------------

    /**
     * Core detection logic — aligned with Probe::colorProfile() SSOT.
     *
     * Priority (mirrors Probe::colorProfile):
     *  1. CLICOLOR_FORCE=1      → TrueColor (SugarCraft extension; supersedes all)
     *  2. FORCE_COLOR           → SugarCraft extension level override (0=Ascii, 1=ANSI, 2=ANSI256, 3=TC)
     *  3. NO_COLOR (any value) → NoTTY
     *  4. CLICOLOR=0            → NoTTY
     *  5. COLORTERM=24bit|truecolor|yes → TrueColor (before TERM=dumb, per Probe)
     *  6. TERM_PROGRAM=iTerm.app → TrueColor
     *  7. TERM=dumb             → NoTTY
     *  8. WT_SESSION set        → TrueColor (Windows Terminal)
     *  9. GOOGLE_CLOUD_SHELL=true → TrueColor
     * 10. TMUX||STY + base TERM screen/tmux → ANSI256
     * 11. TERM=*-256color|xterm-kitty|xterm-ghostty → ANSI256
     * 12. TERM=xterm*|screen*|tmux* → ANSI
     * 13. isatty()              → NoTTY if not a TTY
     * 14. Default               → ANSI
     */
    private static function detectProfile($stream, array $env): Profile
    {
        // 1. CLICOLOR_FORCE=1 → TrueColor (overrides everything below)
        $cliclorForce = $env['CLICOLOR_FORCE'] ?? \getenv('CLICOLOR_FORCE');
        if ($cliclorForce === '1') {
            return Profile::TrueColor;
        }

        // 2. FORCE_COLOR: SugarCraft extension (level-based)
        // Use isset() so explicitly-passed '0' is honored and parent state can't leak.
        $force = isset($env['FORCE_COLOR']) ? $env['FORCE_COLOR'] : (isset($_ENV['FORCE_COLOR']) ? $_ENV['FORCE_COLOR'] : \getenv('FORCE_COLOR'));
        if ($force !== null && $force !== '' && $force !== false) {
            $level = \intval($force);
            return match (true) {
                $level >= 3 => Profile::TrueColor,
                $level === 2 => Profile::ANSI256,
                $level === 1 => Profile::ANSI,
                default => Profile::Ascii,
            };
        }

        // 3. NO_COLOR: presence (any value) disables colors.
        if (\array_key_exists('NO_COLOR', $env)) {
            return Profile::NoTTY;
        }

        // 4. CLICOLOR=0 → NoTTY
        if (isset($env['CLICOLOR']) && $env['CLICOLOR'] === '0') {
            return Profile::NoTTY;
        }

        // 5. COLORTERM=24bit|truecolor|yes → TrueColor
        $ct = $env['COLORTERM'] ?? $_ENV['COLORTERM'] ?? \getenv('COLORTERM') ?: null;
        if (\is_string($ct) && \in_array(\strtolower($ct), ['24bit', 'truecolor', 'yes'], true)) {
            return Profile::TrueColor;
        }

        // 6. TERM_PROGRAM=iTerm.app → TrueColor (iTerm2 supports TrueColor)
        $termProgram = $env['TERM_PROGRAM'] ?? $_ENV['TERM_PROGRAM'] ?? \getenv('TERM_PROGRAM') ?: null;
        if ($termProgram === 'iTerm.app') {
            return Profile::TrueColor;
        }

        // 7. TERM=dumb → NoTTY
        $term = $env['TERM'] ?? \getenv('TERM') ?: '';
        if ($term === 'dumb') {
            return Profile::NoTTY;
        }

        // 8. WT_SESSION set → TrueColor (Windows Terminal)
        $wtSession = $env['WT_SESSION'] ?? \getenv('WT_SESSION') ?: null;
        if ($wtSession !== null && $wtSession !== '') {
            return Profile::TrueColor;
        }

        // 9. GOOGLE_CLOUD_SHELL=true → TrueColor
        $gcs = $env['GOOGLE_CLOUD_SHELL'] ?? \getenv('GOOGLE_CLOUD_SHELL') ?: null;
        if ($gcs === 'true') {
            return Profile::TrueColor;
        }

        // 10. TMUX||STY + base TERM screen/tmux → ANSI256
        $tmux = $env['TMUX'] ?? \getenv('TMUX') ?: null;
        $sty = $env['STY'] ?? \getenv('STY') ?: null;
        $termLower = \strtolower($term);
        if (($tmux !== null && $tmux !== '') || ($sty !== null && $sty !== '')) {
            if (\str_starts_with($termLower, 'screen') || \str_starts_with($termLower, 'tmux')) {
                return Profile::ANSI256;
            }
        }

        // 10. TERM=*-256color|xterm-kitty|xterm-ghostty → ANSI256
        if (
            \str_contains($termLower, '-256color')
            || $termLower === 'xterm-kitty'
            || $termLower === 'xterm-ghostty'
        ) {
            return Profile::ANSI256;
        }

        // 11. TERM=xterm*|screen*|tmux* → ANSI
        if (
            \str_starts_with($termLower, 'xterm')
            || \str_starts_with($termLower, 'screen')
            || \str_starts_with($termLower, 'tmux')
        ) {
            return Profile::ANSI;
        }

        // 12. TTY detection
        if ($stream !== null && \function_exists('stream_isatty')) {
            if (!@\stream_isatty($stream)) {
                return Profile::NoTTY;
            }
        } elseif (\function_exists('posix_isatty')) {
            if (!@posix_isatty(\STDOUT)) {
                return Profile::NoTTY;
            }
        }

        // 13. Default → ANSI (not ANSI256, matching Probe)
        return Profile::ANSI;
    }

    /**
     * Rewrite ANSI sequences in a string to match $targetProfile.
     * Handles:
     *   - SGR CSI 38;2;R;G;B / 48;2;R;G;B (TrueColor)
     *   - SGR CSI 38;5;N / 48;5;N (256-color)
     *
     * Targets: ANSI256 passes 256-color through, ANSI/Ascii degrades to 4-bit SGR.
     */
    private function rewriteAnsi(string $s, Profile $targetProfile): string
    {
        return \preg_replace_callback(
            '/(\x1b\[)(38|48);(\d);(\d+)(;(\d+);(\d+))?(m)/',
            function (array $m) use ($targetProfile): string {
                $sgrBase = (int) $m[2];
                $fmt = (int) $m[3];
                $terminator = $m[8];

                if ($fmt === 2) {
                    $color = new Color((int) $m[4], (int) $m[5], (int) $m[6]);
                } else {
                    $color = Color::fromAnsi256Index((int) $m[4]);
                }

                $converted = $color->convert($targetProfile);

                if ($targetProfile === Profile::ANSI256) {
                    $idx = $converted->toAnsi256Index();
                    return "\x1b[{$sgrBase};5;{$idx}{$terminator}";
                }

                if ($targetProfile === Profile::ANSI || $targetProfile === Profile::Ascii) {
                    $idx = $converted->toAnsi16Index();
                    $isBackground = $sgrBase === 48;
                    return "\x1b[" . Color::ansi16Sgr($idx, $isBackground) . "{$terminator}";
                }

                return $m[0];
            },
            $s,
        ) ?? $s;
    }
}
