<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile;

use SugarCraft\ColorProfile\Probe\InfocmpBinary;
use SugarCraft\ColorProfile\Probe\TerminfoParser;

/**
 * Static probe for terminal color profile and environment-based color detection.
 *
 * The environment answer comes from the canonical 13-step ladder in
 * {@see DetectionChain} (steps 1-13: CLICOLOR_FORCE, FORCE_COLOR, NO_COLOR,
 * CLICOLOR=0, TERM=dumb, COLORTERM, TERM_PROGRAM, WT_SESSION,
 * GOOGLE_CLOUD_SHELL, TMUX/STY, TERM depth rungs, default) — Probe adds the
 * optional terminfo phase on top:
 *
 *  Phase 2: infocmp declares `Tc`/`RGB` at capability-name position
 *           → upgrade Ansi or Ansi256 to TrueColor.
 *
 * Phase 2 answers from ONE shared table and ONE bounded exec face
 * ({@see InfocmpBinary::runBounded()}): repeated calls never re-spawn (the
 * old code ran a fresh unbounded shell per call, and each class kept its
 * own memo — now the per-TERM dump table lives beside the binary it caches
 * answers about), and {@see self::_reset()} drains it for tests.
 *
 * The bound is a contract, not a hint: 5 s wall-clock deadline (SIGTERM →
 * 1 s grace → SIGKILL), 8 KiB keep-a-prefix stdout, array-form argv with no
 * shell in the path, stderr drained and closed, and fail-open semantics —
 * anything short of a clean dump leaves the chain answer standing. A wedged
 * infocmp can therefore never block the caller: the sync face carries its
 * own bound and no longer defers the problem to {@see AsyncProbe}, whose
 * child rides the same ladder via the event loop instead. What the child
 * does get, at both surfaces, is only the shared minimal environment
 * ({@see InfocmpBinary::childEnvironment()}) — parent TERMINFO,
 * TERMINFO_DIRS and HOME never reach the database lookup.
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
final class Probe
{
    private function __construct()
    {
    }

    /**
     * Negotiated color profile after walking every environment variable in
     * the canonical precedence order, then the optional infocmp phase-2
     * upgrade.
     */
    public static function colorProfile(): ColorProfile
    {
        $chain = DetectionChain::detect();
        $profile = $chain->toColorProfile();

        // Phase 2: infocmp Tc/RGB upgrade (Probe-specific). Gated on ANY
        // color rung below TrueColor — the old gate ran it only from Ansi,
        // so an xterm-kitty/ghostty/256color TERM could never reach
        // TrueColor through Probe although TerminalProbe could.
        if ($profile === ColorProfile::Ansi || $profile === ColorProfile::Ansi256) {
            if (self::terminfoHasTrueColor($chain->term())) {
                return ColorProfile::TrueColor;
            }
        }

        return $profile;
    }

    /**
     * NO_COLOR is set to a non-empty value. An empty string means UNSET per
     * the no-color.org convention the whole ecosystem follows.
     */
    public static function isNoColor(): bool
    {
        $value = self::getEnv('NO_COLOR');

        return $value !== null && $value !== '';
    }

    /**
     * CLICOLOR_FORCE carries a force value — set, non-empty, and not exactly
     * '0' (the termenv/chafa grammar owned by {@see Env::isForceValue()},
     * shared with DetectionChain step 1 and TerminalProbe) — forcing
     * TrueColor regardless of other settings.
     */
    public static function isForceColor(): bool
    {
        return Env::isForceValue(self::getEnv('CLICOLOR_FORCE'));
    }

    /**
     * Reduced motion preference — checks REDUCE_MOTION then
     * PREFERS_REDUCED_MOTION. Explicit negative spellings (0/false/no/off,
     * case-insensitive) mean "not reduced"; any other non-empty value means
     * reduced. The old polarity treated REDUCE_MOTION=false as TRUE,
     * inverting the operator's declared preference.
     */
    public static function reducedMotion(): bool
    {
        foreach (['REDUCE_MOTION', 'PREFERS_REDUCED_MOTION'] as $name) {
            $value = self::getEnv($name);
            if ($value === null || $value === '') {
                continue;
            }
            if (in_array(strtolower($value), ['0', 'false', 'no', 'off'], true)) {
                continue;
            }

            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Ambient env read routed through the one {@see Env} resolution rule
     * (shared with Palette/DetectionChain/TerminalProbe). A var set to the
     * empty string returns ''; only a genuinely unset var returns null.
     */
    private static function getEnv(string $name): ?string
    {
        return Env::value(Env::resolve([]), $name);
    }

    /**
     * Optional Phase 2: does infocmp declare Tc/RGB for this TERM?
     *
     * The verdict answers from the ONE bounded, memoized exec face
     * ({@see InfocmpBinary::runBounded()}) — deadline, output cap, and the
     * per-TERM dump table all live there, shared with TerminalProbe.
     */
    private static function terminfoHasTrueColor(string $term): bool
    {
        if ($term === '') {
            return false;
        }

        // Exec gate: a TERM infocmp would read as its own option (leading
        // dash survives quoting) never reaches the binary — the chain
        // answer stands. The door re-checks this; the caller-side check
        // keeps the memo untouched by names that must never exec.
        if (!InfocmpBinary::isSafeTermName($term)) {
            return false;
        }

        $output = InfocmpBinary::runBounded($term);
        if ($output === null || $output === '') {
            return false;
        }

        // Tc (true-color) or RGB (direct color string cap) at NAME position —
        // the old /\bTc\b/ whole-output regex false-matched descriptions.
        return TerminfoParser::hasCapName($output, TerminfoParser::TRUECOLOR_NAMES);
    }

    /**
     * Reset internal static state (for testing only).
     *
     * The exec door owns both statics now (binary-path sniff + per-TERM
     * dump memo); this drains them together so one seam clears the table.
     *
     * @internal
     */
    public static function _reset(): void
    {
        InfocmpBinary::reset();
    }
}
