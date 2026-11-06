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
 * Phase 2 is capped per TERM value for the process lifetime: repeated calls
 * never re-shell (the old code ran a fresh unbounded shell_exec per call),
 * and {@see self::_reset()} clears the cap for tests.
 *
 * That cap bounds FREQUENCY, not the exec itself: a phase-2 shell-out is
 * un-timed and output-unbounded — the proc_open child blocks until infocmp
 * exits and its whole dump is slurped. It is a CLI/startup-context face;
 * inside a live event loop or a request path use {@see AsyncProbe}, whose
 * child carries a deadline ladder and capped pipes instead. What the child
 * does get, at both surfaces, is only the shared minimal environment
 * ({@see InfocmpBinary::childEnvironment()}) — parent TERMINFO,
 * TERMINFO_DIRS and HOME never reach the database lookup.
 *
 * @see https://github.com/charmbracelet/colorprofile
 */
final class Probe
{
    /**
     * Per-TERM phase-2 upgrade verdicts (true = infocmp declares Tc/RGB).
     *
     * @var array<string, bool>
     */
    private static array $terminfoUpgradeMemo = [];

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
     * CLICOLOR_FORCE=1 is set — forces TrueColor regardless of other settings.
     */
    public static function isForceColor(): bool
    {
        return self::getEnv('CLICOLOR_FORCE') === '1';
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
     */
    private static function terminfoHasTrueColor(string $term): bool
    {
        if ($term === '') {
            return false;
        }

        // Exec gate: a TERM infocmp would read as its own option (leading
        // dash survives quoting) never reaches the binary — the chain
        // answer stands. Shared predicate with AsyncProbe/TerminalProbe.
        if (!InfocmpBinary::isSafeTermName($term)) {
            return false;
        }

        $key = strtolower($term);
        if (array_key_exists($key, self::$terminfoUpgradeMemo)) {
            return self::$terminfoUpgradeMemo[$key];
        }

        $binary = InfocmpBinary::path();
        if ($binary === null) {
            return self::$terminfoUpgradeMemo[$key] = false;
        }

        // Minimal environment (shared builder): TERMINFO/TERMINFO_DIRS/HOME
        // cannot steer the database lookup from the parent process.
        $handle = @\proc_open(
            $binary . ' -1 ' . escapeshellarg($term) . ' 2>/dev/null',
            [1 => ['pipe', 'w']],
            $pipes,
            null,
            InfocmpBinary::childEnvironment($term),
        );

        if (!\is_resource($handle)) {
            return self::$terminfoUpgradeMemo[$key] = false;
        }

        $output = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        \proc_close($handle);

        if ($output === false) {
            return self::$terminfoUpgradeMemo[$key] = false;
        }

        // Tc (true-color) or RGB (direct color string cap) at NAME position —
        // the old /\bTc\b/ whole-output regex false-matched descriptions.
        return self::$terminfoUpgradeMemo[$key] =
            TerminfoParser::hasCapName($output, TerminfoParser::TRUECOLOR_NAMES);
    }

    /**
     * Reset internal static state (for testing only).
     *
     * @internal
     */
    public static function _reset(): void
    {
        InfocmpBinary::reset();
        self::$terminfoUpgradeMemo = [];
    }
}
