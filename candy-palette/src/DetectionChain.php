<?php

declare(strict_types=1);

namespace SugarCraft\Palette;

/**
 * Shared terminal color detection chain — the single source of truth for
 * environment-based color capability detection.
 *
 * This class has NO dependencies on other SugarCraft\Palette classes,
 * making it safe to use from Probe, Palette, and TerminalProbe without
 * creating circular dependencies.
 *
 * Mirrors charmbracelet/colorprofile detection logic.
 *
 * Detection precedence:
 *  1. CLICOLOR_FORCE=1       → truecolor  (overrides everything)
 *  2. NO_COLOR (any value)   → none
 *  3. CLICOLOR=0             → none
 *  4. TERM=dumb              → none
 *  5. COLORTERM=24bit|truecolor|yes → truecolor
 *  6. WT_SESSION (set)       → truecolor  (Windows Terminal)
 *  7. GOOGLE_CLOUD_SHELL=true → truecolor
 *  8. TMUX||STY + base TERM screen/tmux → ansi256
 *  9. TERM=xterm-kitty|xterm-ghostty|*-256color → ansi256
 * 10. TERM=xterm*|screen*|tmux* → ansi
 * 11. Default → ansi
 */
final class DetectionChain
{
    public const LEVEL_NONE = 'none';
    public const LEVEL_ASCII = 'ascii';
    public const LEVEL_ANSI = 'ansi';
    public const LEVEL_ANSI256 = 'ansi256';
    public const LEVEL_TRUECOLOR = 'truecolor';

    private string $level;
    private string $source;
    private string $term;

    private function __construct(string $level, string $source, string $term)
    {
        $this->level = $level;
        $this->source = $source;
        $this->term = $term;
    }

    /**
     * Run the detection chain on an environment array.
     *
     * @param array<string, string|null> $env
     */
    public static function detect(array $env = []): self
    {
        // Merge with actual environment if empty
        if ($env === []) {
            $env = array_merge($_ENV, getenv() ?: []);
        }

        $term = $env['TERM'] ?? '';
        $termLower = strtolower($term);

        // 1. CLICOLOR_FORCE=1 → truecolor (overrides everything)
        $cliclorForce = $env['CLICOLOR_FORCE'] ?? null;
        if ($cliclorForce === '1') {
            return new self(self::LEVEL_TRUECOLOR, 'env:CLICOLOR_FORCE', $term);
        }

        // 2. NO_COLOR (any value) → none
        if (array_key_exists('NO_COLOR', $env) && $env['NO_COLOR'] !== null && $env['NO_COLOR'] !== false) {
            return new self(self::LEVEL_NONE, 'env:NO_COLOR', $term);
        }

        // 3. CLICOLOR=0 → none
        $clicolor = $env['CLICOLOR'] ?? null;
        if ($clicolor === '0') {
            return new self(self::LEVEL_NONE, 'env:CLICOLOR=0', $term);
        }

        // 4. TERM=dumb → none
        if ($term === 'dumb') {
            return new self(self::LEVEL_NONE, 'env:TERM=dumb', $term);
        }

        // 5. COLORTERM=24bit|truecolor|yes → truecolor
        $colorterm = $env['COLORTERM'] ?? null;
        if ($colorterm !== null) {
            $ctLower = strtolower($colorterm);
            if ($ctLower === '24bit' || $ctLower === 'truecolor' || $ctLower === 'yes') {
                return new self(self::LEVEL_TRUECOLOR, 'env:COLORTERM=' . $colorterm, $term);
            }
        }

        // 6. WT_SESSION (set) → truecolor (Windows Terminal)
        $wtSession = $env['WT_SESSION'] ?? null;
        if ($wtSession !== null && $wtSession !== '') {
            return new self(self::LEVEL_TRUECOLOR, 'env:WT_SESSION', $term);
        }

        // 7. GOOGLE_CLOUD_SHELL=true → truecolor
        $gcs = $env['GOOGLE_CLOUD_SHELL'] ?? null;
        if ($gcs === 'true') {
            return new self(self::LEVEL_TRUECOLOR, 'env:GOOGLE_CLOUD_SHELL', $term);
        }

        // 8. TMUX||STY + base TERM screen/tmux → ansi256
        $tmux = $env['TMUX'] ?? null;
        $sty = $env['STY'] ?? null;
        if (($tmux !== null && $tmux !== '') || ($sty !== null && $sty !== '')) {
            if (str_starts_with($termLower, 'screen') || str_starts_with($termLower, 'tmux')) {
                return new self(self::LEVEL_ANSI256, 'env:TMUX|STY+' . ($tmux !== null ? 'TMUX' : 'STY'), $term);
            }
        }

        // 9. TERM=xterm-kitty|xterm-ghostty|*-256color → ansi256
        if (str_contains($termLower, '-256color') || $termLower === 'xterm-kitty' || $termLower === 'xterm-ghostty') {
            return new self(self::LEVEL_ANSI256, 'env:TERM=' . $term, $term);
        }

        // 10. TERM=xterm*|screen*|tmux* → ansi
        if (str_starts_with($termLower, 'xterm') || str_starts_with($termLower, 'screen') || str_starts_with($termLower, 'tmux')) {
            return new self(self::LEVEL_ANSI, 'env:TERM=' . $term, $term);
        }

        // 11. Default → ansi
        return new self(self::LEVEL_ANSI, 'fallback:default', $term);
    }

    /**
     * Get the detected level as a string.
     *
     * @return self::LEVEL_*
     */
    public function level(): string
    {
        return $this->level;
    }

    /**
     * Get the source of the detection result.
     */
    public function source(): string
    {
        return $this->source;
    }

    /**
     * Get the TERM value used in detection.
     */
    public function term(): string
    {
        return $this->term;
    }

    /**
     * Whether color is allowed (not disabled via NO_COLOR, CLICOLOR=0, TERM=dumb).
     */
    public function allowsColor(): bool
    {
        return $this->level !== self::LEVEL_NONE;
    }

    /**
     * Convert the detection level to a Profile enum.
     */
    public function toProfile(): Profile
    {
        return match ($this->level) {
            self::LEVEL_TRUECOLOR => Profile::TrueColor,
            self::LEVEL_ANSI256 => Profile::ANSI256,
            self::LEVEL_ANSI => Profile::ANSI,
            self::LEVEL_ASCII => Profile::Ascii,
            self::LEVEL_NONE => Profile::NoTTY,
            default => Profile::ANSI,
        };
    }

    /**
     * Convert the detection level to a ColorProfile enum.
     */
    public function toColorProfile(): ColorProfile
    {
        return match ($this->level) {
            self::LEVEL_TRUECOLOR => ColorProfile::TrueColor,
            self::LEVEL_ANSI256 => ColorProfile::Ansi256,
            self::LEVEL_ANSI => ColorProfile::Ansi,
            self::LEVEL_ASCII => ColorProfile::Ascii,
            self::LEVEL_NONE => ColorProfile::NoTTY,
            default => ColorProfile::Ansi,
        };
    }
}
