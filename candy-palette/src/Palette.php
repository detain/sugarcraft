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
     * Checks in order:
     * 1. FORCE_COLOR=0..255  → force a specific profile level
     * 2. NO_COLOR=          → NoTTY (disabled)
     * 3. COLORTERM=         → TrueColor if set to any value
     * 4. TERM_PROGRAM iTerm2 / Apple_Terminal / Hyper → TrueColor
     * 5. TERM=              → match against known capability strings
     * 6. TTY detection       → NoTTY if stdout is not a TTY
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
        // OSC sequences: \x1b]...(\x07|\x1b\\)
        // DCS sequences: \x1bP...(\x07|\x1b\\)
        // SS3 sequences: \x1bO{letter}
        // APC sequences: \x1b_...(\x07|\x1b\\)
        return \preg_replace(
            '/(?:\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|'
            . '\x1b\[[0-9;]*[A-Za-z]|'
            . '\x1b[PX^_][^\x07\x1b]*(?:\x07|\x1b\\\\)|'
            . '\x1b[OopeHMJKhCBDsu])/',
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
     * Core detection logic — mirrors the Go colorprofile algorithm.
     *
     * Priority:
     *  1. FORCE_COLOR  — override (any non-empty value, level = min(value, 3))
     *  2. NO_COLOR     — disable colors entirely
     *  3. COLORTERM    — "truecolor" or "24bit" → TrueColor
     *  4. TERM_PROGRAM — iTerm2 / Apple_Terminal / Hyper → TrueColor
     *  5. TERM         — pattern-match against capability database
     *  6. isatty()     — no TTY → NoTTY
     */
    private static function detectProfile($stream, array $env): Profile
    {
        $env = \array_merge($_ENV, $env);

        // 1. FORCE_COLOR: 0=Ascii, 1=ANSI, 2=ANSI256, 3+=TrueColor
        // Check getenv() directly since $_ENV may not be populated from the
        // process environment in some CI configurations (e.g. GitHub Actions
        // sets FORCE_COLOR but $_ENV is not always populated).
        $force = $env['FORCE_COLOR'] ?? \getenv('FORCE_COLOR');
        if ($force !== null && $force !== '' && $force !== false) {
            $level = \intval($force);
            return match (true) {
                $level >= 3 => Profile::TrueColor,
                $level === 2 => Profile::ANSI256,
                $level === 1 => Profile::ANSI,
                default => Profile::Ascii,
            };
        }

        // 2. NO_COLOR: presence (any value, including empty) disables colors.
        // Per https://no-color.org: "when present (regardless of its value)".
        if (\array_key_exists('NO_COLOR', $env)) {
            return Profile::NoTTY;
        }

        // 3. COLORTERM env var
        $ct = $env['COLORTERM'] ?? null;
        if ($ct !== null && \strtolower($ct) !== 'none') {
            // Any COLORTERM value other than "none" implies at least TrueColor
            return Profile::TrueColor;
        }

        // 4. TERM_PROGRAM hints
        $program = $env['TERM_PROGRAM'] ?? null;
        if ($program !== null) {
            $known = [
                'iTerm.app'       => Profile::TrueColor,
                'Apple_Terminal'  => Profile::TrueColor,
                'Hyper'           => Profile::TrueColor,
                'WezTerm'         => Profile::TrueColor,
                'vscode'          => Profile::TrueColor,
                'Ghostty'         => Profile::TrueColor,
            ];
            if (isset($known[$program])) {
                return $known[$program];
            }
        }

        // 5. TERM capability heuristics
        $term = $env['TERM'] ?? '';
        $termLower = \strtolower($term);

        // TrueColor terminals: only those that explicitly advertise 24-bit.
        // The "*-256color" terminfo entries advertise 256 colors, not TrueColor —
        // 24-bit must be opted into via COLORTERM or a -truecolor/-direct suffix.
        if (
            \preg_match('/-truecolor$/i', $term)
            || \str_contains($termLower, '24bit')
            || \str_contains($termLower, 'direct')
        ) {
            return Profile::TrueColor;
        }

        // ANSI256 terminals
        if (
            \preg_match('/-256color$/i', $term)
            || \in_array($termLower, [
                'xterm-16color', 'rxvt-unicode', 'eterm', 'ansi',
                'screen', 'tmux',
            ], true)
        ) {
            return Profile::ANSI256;
        }

        // ANSI / 8-color
        if (
            \str_contains($termLower, 'color')
            || \in_array($termLower, ['vt100', 'linux', 'cygwin'])
        ) {
            return Profile::ANSI;
        }

        // 6. TTY detection
        if ($stream !== null && \function_exists('stream_isatty')) {
            if (!@\stream_isatty($stream)) {
                return Profile::NoTTY;
            }
        } elseif (\function_exists('posix_isatty')) {
            if (!@posix_isatty(\STDOUT)) {
                return Profile::NoTTY;
            }
        }

        // Conservative default: assume ANSI256 (most modern terminals)
        return Profile::ANSI256;
    }

    /**
     * Rewrite ANSI sequences in a string to match $targetProfile.
     * Handles SGR (CSI 38;2;R;G;B and CSI 48;2;R;G;B) for TrueColor input.
     */
    private function rewriteAnsi(string $s, Profile $targetProfile): string
    {
        return \preg_replace_callback(
            '/(\x1b\[)(38|48);2;(\d+);(\d+);(\d+)(m)/',
            function (array $m) use ($targetProfile): string {
                $color = new Color(
                    (int) $m[3],
                    (int) $m[4],
                    (int) $m[5],
                );
                $converted = $color->convert($targetProfile);

                if ($targetProfile === Profile::ANSI256) {
                    $idx = $converted->toAnsi256Index();
                    return "\x1b[{$m[1]}{$m[2]};5;{$idx}{$m[6]}";
                }

                if ($targetProfile === Profile::ANSI) {
                    $idx = $converted->toAnsi16Index();
                    return "\x1b[{$m[1]}{$m[2]};5;{$idx}{$m[6]}";
                }

                // Ascii
                $ascii = $converted->toAscii();
                return "\x1b[{$m[1]}{$m[2]};5;" . $ascii->toAnsi16Index() . "{$m[6]}";
            },
            $s,
        ) ?? $s;
    }
}
