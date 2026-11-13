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
 *  4. CLICOLOR=0                  → ascii (same colorless routing; the positive
 *     CLICOLOR=1 leg upstream honors is a deliberate omission — the floor answer
 *     is already ansi)
 *  5. TERM=dumb (case-insensitive) → ascii (same colorless routing)
 *  6. TMUX/STY set + base TERM begins "screen" → ansi256 — the
 *     multiplexer cap BEFORE COLORTERM (screen never forwards 24-bit and
 *     COLORTERM survives multiplexer inheritance)
 *  7. COLORTERM=truecolor|24bit → truecolor; COLORTERM=yes → ansi256 (the
 *     community-verified-terminal list reads "yes" as a 256-color promise —
 *     legacy VTE lied with it; the rung stays inside phase-2's promotion reach)
 *  8. TERM_PROGRAM=iTerm.app      → truecolor (inline image + 24-bit history)
 *  9. WT_SESSION (set, non-empty) → truecolor  (Windows Terminal)
 * 10. GOOGLE_CLOUD_SHELL="true" (exact) → truecolor (Cloud Shell's image sets
 *     "true"; any other value, "false" or "1" included, carries no promise)
 * 11. TMUX/STY set + base TERM begins "tmux" → ansi256 (behind COLORTERM —
 *     the tmux leg may honestly report truecolor)
 * 12. TERM contains -256color, or equals xterm-kitty/xterm-ghostty
 *     (case-insensitive) → ansi256 (termenv promotes kitty/ghostty to
 *     truecolor by NAME; this fork keeps the 256 promise and defers name-
 *     based promotion to phase-2 terminfo — WezTerm has no TERM-only twin)
 * 13. TERM begins xterm/screen/tmux/vt/linux (case-insensitive) → ansi
 *     (the KNOWN-family set keeps color — CP-10)
 * 14. Default — unknown or empty TERM → ascii (the colorless floor: this
 *     fork no longer over-detects 16-color support for a family it has
 *     never heard of; CP-05 made the colorless-but-decorated rung a
 *     first-class answer to floor onto)
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
        //    color-off preference, decoration intact). The positive CLICOLOR=1
        //    leg upstream honors is a DELIBERATE omission here (CP-09): this
        //    ladder's floor answer is already ANSI, so reading the leg would
        //    change behavior on no host that needs it.
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

        // 6. Multiplexer cap, screen leg (CP-09): TMUX/STY set and base TERM
        //    begins "screen" → ANSI256 BEFORE COLORTERM. GNU screen never
        //    forwards the 24-bit set, and COLORTERM survives screen/tmux
        //    inheritance — evaluated after it, the cap could be inherited
        //    away and screen users would receive 24-bit escape garbage. The
        //    tmux leg stays behind COLORTERM (step 11): modern tmux forwards
        //    truecolor when its overrides advertise RGB, so tmux+COLORTERM
        //    may still pass — the documented split pinned by tests.
        $tmux = Env::value($resolved, 'TMUX');
        $sty = Env::value($resolved, 'STY');
        $inTmux = $tmux !== null && $tmux !== '';
        $inScreen = $sty !== null && $sty !== '';
        if (($inTmux || $inScreen) && str_starts_with($termLower, 'screen')) {
            return new self(
                self::LEVEL_ANSI256,
                'env:TMUX|STY+' . ($inTmux ? 'TMUX' : 'STY'),
                $term,
            );
        }

        // 7. COLORTERM: truecolor|24bit → truecolor; "yes" → ansi256 (CP-09).
        //    termenv parity: legacy VTE builds advertised 24-bit support with
        //    the lie COLORTERM=yes, and the community-verified-terminal list
        //    treats "yes" as a 256-color promise at most. The demotion keeps
        //    the rung "some color" AND leaves it inside the Probe phase-2
        //    promotion gate (which runs only on Ansi/Ansi256), so a genuine
        //    RGB terminfo can still raise it back.
        $colorterm = Env::value($resolved, 'COLORTERM');
        if ($colorterm !== null) {
            $ctLower = strtolower($colorterm);
            if ($ctLower === '24bit' || $ctLower === 'truecolor') {
                return new self(self::LEVEL_TRUECOLOR, Env::sanitize('env:COLORTERM=' . $colorterm), $term);
            }
            if ($ctLower === 'yes') {
                return new self(self::LEVEL_ANSI256, Env::sanitize('env:COLORTERM=' . $colorterm), $term);
            }
        }

        // 8. TERM_PROGRAM=iTerm.app → truecolor (iTerm2 renders 24-bit since
        //    forever; the legacy rung Palette used to special-case pre-TTY).
        if (Env::value($resolved, 'TERM_PROGRAM') === 'iTerm.app') {
            return new self(self::LEVEL_TRUECOLOR, 'env:TERM_PROGRAM', $term);
        }

        // 9. WT_SESSION (set, non-empty) → truecolor (Windows Terminal)
        $wtSession = Env::value($resolved, 'WT_SESSION');
        if ($wtSession !== null && $wtSession !== '') {
            return new self(self::LEVEL_TRUECOLOR, 'env:WT_SESSION', $term);
        }

        // 10. GOOGLE_CLOUD_SHELL === "true" → truecolor (CP-09). Upstream's
        //    grant is the exact string: the Cloud Shell image sets "true",
        //    and any other value ("false", "1", a path, anything) carries no
        //    24-bit promise. One-way behavior change from the previous
        //    set-and-non-empty test: "false" and "1" no longer force.
        if (Env::value($resolved, 'GOOGLE_CLOUD_SHELL') === 'true') {
            return new self(self::LEVEL_TRUECOLOR, 'env:GOOGLE_CLOUD_SHELL', $term);
        }

        // 11. Multiplexer cap, tmux leg: TMUX/STY set and base TERM begins
        //     "tmux" → ansi256 (behind COLORTERM — the screen/tmux split is
        //     the CP-09 documented answer: modern tmux may forward truecolor,
        //     GNU screen never does). The screen prefix was capped at step 6.
        if (($inTmux || $inScreen) && str_starts_with($termLower, 'tmux')) {
            return new self(
                self::LEVEL_ANSI256,
                'env:TMUX|STY+' . ($inTmux ? 'TMUX' : 'STY'),
                $term,
            );
        }

        // 12. TERM=xterm-kitty|xterm-ghostty|*-256color → ansi256.
        //     Name divergence (CP-09): termenv classifies kitty/ghostty as
        //     TrueColor by NAME, this fork keeps the 256 promise the TERM
        //     value itself carries and defers name-based promotion to the
        //     Probe phase-2 terminfo leg — the DetectionChainTest pins for
        //     these two are intentional, and WezTerm has no TERM-only twin
        //     to pin at all.
        if (str_contains($termLower, '-256color') || $termLower === 'xterm-kitty' || $termLower === 'xterm-ghostty') {
            return new self(self::LEVEL_ANSI256, Env::sanitize('env:TERM=' . $term), $term);
        }

        // 13. TERM=xterm*|screen*|tmux*|vt*|linux* → ansi. The KNOWN-family
        //     set keeps color (CP-10): vt100/vt220 and the Linux console are
        //     genuine ANSI terminals and the old code answered them here via
        //     the default — widening this leg is what makes step 14 safe.
        if (
            str_starts_with($termLower, 'xterm')
            || str_starts_with($termLower, 'screen')
            || str_starts_with($termLower, 'tmux')
            || str_starts_with($termLower, 'vt')
            || str_starts_with($termLower, 'linux')
        ) {
            return new self(self::LEVEL_ANSI, Env::sanitize('env:TERM=' . $term), $term);
        }

        // 14. Default → ascii (colorless floor — CP-10, sugar-colorprofile-101):
        //     termenv/chafa families floor unrecognized TERM below ANSI; this
        //     fork over-detected 16 colors there until CP-05 made the
        //     colorless-but-decorated rung a first-class answer to floor onto.
        return new self(self::LEVEL_ASCII, 'fallback:default', $term);
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
     * The two explicit force legs (ladder steps 1-2), identified by their
     * tagged source — the answer's DEPTH never matters here: a falsy
     * FORCE_COLOR spelling is still an operator-pinned force declaration
     * (it just pins a colorless answer, so no pipe ever sees color vomit
     * through this seam).
     */
    private const FORCE_SOURCES = ['env:CLICOLOR_FORCE', 'env:FORCE_COLOR'];

    /**
     * True when the answer came from an explicit force leg rather than a
     * capability read or a suppression. Callers gating on TTY presence use
     * this to yield: upstream parity is `Detect() = isatty() || forced()`
     * (CP-07), so an operator who PINNED color through the force machinery
     * keeps that rung even on a piped stream. Suppression legs are NOT
     * force — NO_COLOR/CLICOLOR=0/TERM=dumb ask for less output, never
     * for escape sequences past a pipe, so they keep the gate.
     */
    public function isForced(): bool
    {
        return \in_array($this->source, self::FORCE_SOURCES, true);
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
