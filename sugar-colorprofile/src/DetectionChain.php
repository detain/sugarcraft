<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

/**
 * Shared terminal color detection chain — the single source of truth for
 * environment-based color capability detection.
 *
 * This class has NO dependencies on other SugarCraft\ColorProfile classes,
 * making it safe to use from Probe, Palette, and TerminalProbe without
 * creating circular dependencies. Probe, Palette and TerminalProbe all take
 * their env-ladder rung answer from here, so the same environment never
 * answers differently at that rung. Final surface answers may still diverge:
 * Probe layers the optional terminfo phase (Phase 2) on top and TerminalProbe
 * the terminfo + TERM_PROGRAM upgrade phases (2-3), so this chain's answer is
 * the floor those phases can raise — never the ceiling.
 *
 * Mirrors charmbracelet/colorprofile detection logic.
 *
 * Canonical detection precedence (the one numbering every surface follows):
 *  1. CLICOLOR_FORCE (set, non-empty, non-'0') → truecolor (overrides every env leg)
 *  2. FORCE_COLOR (non-empty)     → level: true|yes|on|>=3 truecolor, 2 ansi256,
 *                                   1 ansi; any other spelling (0/false/off/…)
 *                                   → ascii (disable color, keep decoration)
 *  3. NO_COLOR (set and NON-empty; "" = unset per no-color.org) → ascii
 *     (colorless: color codes dropped, decoration kept)
 *  4. CLICOLOR=0                  → ascii (same colorless routing)
 *  5. TERM=dumb (case-insensitive) → ascii (same colorless routing)
 *  6. COLORTERM=24bit|truecolor|yes → truecolor
 *  7. TERM_PROGRAM=iTerm.app      → truecolor (inline image + 24-bit history)
 *  8. WT_SESSION (set, non-empty) → truecolor  (Windows Terminal)
 *  9. GOOGLE_CLOUD_SHELL (set, non-empty) → truecolor
 * 10. TMUX or STY set + base TERM begins screen/tmux → ansi256
 * 11. TERM contains -256color, or equals xterm-kitty/xterm-ghostty
 *     (case-insensitive) → ansi256
 * 12. TERM begins xterm/screen/tmux (case-insensitive) → ansi
 * 13. Default → ansi
 *
 * Steps 1-2 outrank step 3 deliberately: FORCE_COLOR/CLICOLOR_FORCE are
 * active "give me color anyway" overrides (chalk/support-colors convention),
 * while NO_COLOR is a passive preference. The asymmetry is documented in
 * README and pinned by tests.
 *
 * Interpolated env values that ride in source strings are sanitized via
 * {@see Env::sanitize()} — TERM and friends are attacker-influenced over SSH
 * and a source string is printed by downstream diagnostics.
 */
final class DetectionChain
{
    public const LEVEL_NONE = 'none';
    public const LEVEL_ASCII = 'ascii';
    public const LEVEL_ANSI = 'ansi';
    public const LEVEL_ANSI256 = 'ansi256';
    public const LEVEL_TRUECOLOR = 'truecolor';

    /** FORCE_COLOR spellings that mean "level 3" (compared lowercased). */
    private const FORCE_COLOR_TRUTHY_WORDS = ['true', 'yes', 'on'];

    /**
     * Byte ceiling for the TERM value exposed through {@see self::term()}.
     * Detection itself compares the ambient TERM by equality, raw; the
     * accessor is where the value leaves the chain, so it is where an
     * absurdly long value gets a stated bound instead of streaming out
     * unbounded (env interpolation elsewhere is separately capped by
     * {@see Env::SOURCE_MAX_BYTES} — that cap never applied here).
     */
    private const TERM_MAX_BYTES = 256;

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
     * Resolution follows the single {@see Env} rule: a non-empty $env is a
     * COMPLETE hermetic replacement (no ambient leakage); an empty $env reads
     * the ambient snapshot.
     *
     * @param array<string, string|null> $env
     */
    public static function detect(array $env = []): self
    {
        $resolved = Env::resolve($env);

        $term = Env::value($resolved, 'TERM') ?? '';
        $termLower = strtolower($term);

        // 1. CLICOLOR_FORCE carries a force value → truecolor (overrides
        //    everything). The grammar is the termenv/chafa rule, owned by
        //    Env::isForceValue so all three readers answer alike: set,
        //    non-empty, not exactly '0'.
        if (Env::isForceValue(Env::value($resolved, 'CLICOLOR_FORCE'))) {
            return new self(self::LEVEL_TRUECOLOR, 'env:CLICOLOR_FORCE', $term);
        }

        // 2. FORCE_COLOR → level floor (absent or "" falls through)
        $forceColor = Env::value($resolved, 'FORCE_COLOR');
        if ($forceColor !== null && $forceColor !== '') {
            $level = strtolower($forceColor);
            if (in_array($level, self::FORCE_COLOR_TRUTHY_WORDS, true)) {
                return new self(self::LEVEL_TRUECOLOR, 'env:FORCE_COLOR', $term);
            }
            return new self(self::forceColorLevel((int) $level), 'env:FORCE_COLOR', $term);
        }

        // 3. NO_COLOR set and non-empty → the colorless rung (CP-05 Shape B):
        //    "no color" means COLOR off, not decoration off — the same answer
        //    no-color.org asks for and what upstream colorprofile's Ascii
        //    profile renders. "" means UNSET per no-color.org (the convention
        //    every other tool in the ecosystem honours).
        $noColor = Env::value($resolved, 'NO_COLOR');
        if ($noColor !== null && $noColor !== '') {
            return new self(self::LEVEL_ASCII, 'env:NO_COLOR', $term);
        }

        // 4. CLICOLOR=0 → colorless rung (same routing as NO_COLOR: a declared
        //    color-off preference, decoration intact).
        if (Env::value($resolved, 'CLICOLOR') === '0') {
            return new self(self::LEVEL_ASCII, 'env:CLICOLOR=0', $term);
        }

        // 5. TERM=dumb (case-insensitive — 'DUMB' must not smuggle a COLORTERM
        //    past the fleet's no-vomit guarantee) → colorless rung. Checked
        //    BEFORE COLORTERM: a dumb terminal is a promise of minimal
        //    capabilities and a deliberate declaration by the environment
        //    owner, so it outranks an advertised truecolor COLORTERM. This
        //    matches the documented ladder in README/Probe/TerminalProbe and
        //    the upstream rule ("TERM=dumb is always treated as a colorless
        //    profile unless CLICOLOR_FORCE=1") that sugar-core's
        //    Util\ColorProfile honours.
        if ($termLower === 'dumb') {
            return new self(self::LEVEL_ASCII, 'env:TERM=dumb', $term);
        }

        // 6. COLORTERM=24bit|truecolor|yes → truecolor
        $colorterm = Env::value($resolved, 'COLORTERM');
        if ($colorterm !== null) {
            $ctLower = strtolower($colorterm);
            if ($ctLower === '24bit' || $ctLower === 'truecolor' || $ctLower === 'yes') {
                return new self(self::LEVEL_TRUECOLOR, Env::sanitize('env:COLORTERM=' . $colorterm), $term);
            }
        }

        // 7. TERM_PROGRAM=iTerm.app → truecolor (iTerm2 renders 24-bit since
        //    forever; the legacy rung Palette used to special-case pre-TTY).
        if (Env::value($resolved, 'TERM_PROGRAM') === 'iTerm.app') {
            return new self(self::LEVEL_TRUECOLOR, 'env:TERM_PROGRAM', $term);
        }

        // 8. WT_SESSION (set, non-empty) → truecolor (Windows Terminal)
        $wtSession = Env::value($resolved, 'WT_SESSION');
        if ($wtSession !== null && $wtSession !== '') {
            return new self(self::LEVEL_TRUECOLOR, 'env:WT_SESSION', $term);
        }

        // 9. GOOGLE_CLOUD_SHELL set and non-empty → truecolor. Any non-empty
        //    value declares the environment; the Cloud Shell image itself sets
        //    "1"/"true" historically, and a boolean-style "false" is a
        //    deliberate unsetting only by UNSETTING the variable (the variable
        //    existing as unsettable false is not a Google contract).
        $gcs = Env::value($resolved, 'GOOGLE_CLOUD_SHELL');
        if ($gcs !== null && $gcs !== '') {
            return new self(self::LEVEL_TRUECOLOR, 'env:GOOGLE_CLOUD_SHELL', $term);
        }

        // 10. TMUX||STY + base TERM screen/tmux → ansi256
        $tmux = Env::value($resolved, 'TMUX');
        $sty = Env::value($resolved, 'STY');
        $inTmux = $tmux !== null && $tmux !== '';
        $inScreen = $sty !== null && $sty !== '';
        if ($inTmux || $inScreen) {
            if (str_starts_with($termLower, 'screen') || str_starts_with($termLower, 'tmux')) {
                return new self(
                    self::LEVEL_ANSI256,
                    'env:TMUX|STY+' . ($inTmux ? 'TMUX' : 'STY'),
                    $term,
                );
            }
        }

        // 11. TERM=xterm-kitty|xterm-ghostty|*-256color → ansi256
        if (str_contains($termLower, '-256color') || $termLower === 'xterm-kitty' || $termLower === 'xterm-ghostty') {
            return new self(self::LEVEL_ANSI256, Env::sanitize('env:TERM=' . $term), $term);
        }

        // 12. TERM=xterm*|screen*|tmux* → ansi
        if (
            str_starts_with($termLower, 'xterm')
            || str_starts_with($termLower, 'screen')
            || str_starts_with($termLower, 'tmux')
        ) {
            return new self(self::LEVEL_ANSI, Env::sanitize('env:TERM=' . $term), $term);
        }

        // 13. Default → ansi
        return new self(self::LEVEL_ANSI, 'fallback:default', $term);
    }

    /**
     * Map a numeric FORCE_COLOR level onto the ladder.
     *
     * @return self::LEVEL_*
     */
    private static function forceColorLevel(int $level): string
    {
        return match (true) {
            $level >= 3 => self::LEVEL_TRUECOLOR,
            $level === 2 => self::LEVEL_ANSI256,
            $level === 1 => self::LEVEL_ANSI,
            // Chalk's convention is level 0 = "no color support", and the
            // colorless rung here is exactly that answer since CP-05: color
            // codes dropped, decoration kept — what "disable color, keep the
            // rest" renders as (color is not dropped outright).
            default => self::LEVEL_ASCII,
        };
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
     * Get the TERM value used in detection. Values at or below
     * {@see self::TERM_MAX_BYTES} come back byte-identical; a longer
     * value is clamped to the ceiling here rather than exported raw —
     * the accessor is where TERM leaves the chain, and detection itself
     * already matched it by equality before any consumer could see it.
     *
     * The clamp is boundary-safe (mb_strcut, not substr) because the
     * clamped value is exported as the child's TERM and a raw substr cut
     * through a multibyte sequence would emit invalid UTF-8. Env owns the
     * repo's single sanitize rule, but its helper also strips controls and
     * caps at SOURCE_MAX_BYTES (128, a different ceiling), so calling it
     * here would change the contract — the same boundary-safe primitive it
     * uses is applied directly. The exec path itself never sees a split
     * name: isSafeTermName rejects before command build (verified).
     */
    public function term(): string
    {
        return \strlen($this->term) > self::TERM_MAX_BYTES
            ? \mb_strcut($this->term, 0, self::TERM_MAX_BYTES, 'UTF-8')
            : $this->term;
    }

    /**
     * Whether the ladder answered on a rung that renders color at all.
     *
     * Since CP-05 the suppression legs (NO_COLOR, CLICOLOR=0, TERM=dumb)
     * answer the colorless Ascii rung, not NONE — NONE is reserved as the
     * hardware-absence answer (the stream/TTY gate surfaces map that one
     * straight onto NoTTY); it is no longer derived from the env ladder.
     */
    public function allowsColor(): bool
    {
        return $this->level !== self::LEVEL_NONE && $this->level !== self::LEVEL_ASCII;
    }

    /**
     * Convert the detection level to the profile enum.
     *
     * @deprecated — {@see Profile} is a class_alias façade over
     *             {@see ColorProfile}; this method and
     *             {@see self::toColorProfile()} return the same thing.
     *             Call toColorProfile() in new code.
     */
    public function toProfile(): ColorProfile
    {
        return $this->toColorProfile();
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
