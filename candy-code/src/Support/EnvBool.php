<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

/**
 * The two documented boolean-environment vocabularies, in one place (W7
 * EnvBool unify — the repo previously kept private copies of each law).
 *
 * Both helpers take the RAW `getenv()` result rather than the variable name:
 * `EnvRosterDriftTest` and `ReadPathCensusTest` attribute environment reads to
 * the literal (or same-file constant) inside the `getenv(...)` call, so the
 * name MUST stay visible at the call site for the rosters to keep resolving.
 *
 * Two laws, deliberately not merged:
 *  - {@see isTrue()} is the NARROW house law — only `1`, `true`, `yes`
 *    (case-insensitive) turn a flag on. This is the vocabulary
 *    `BootstrapMcpDisableEnvTest` pins for `SUGARCRUSH_MCP_DISABLE` /
 *    `CANDY_CODE_DISABLE_MCP`; every other value keeps the default.
 *  - {@see isSet()} is the PRESENCE law documented in docs/ENVIRONMENT.md for
 *    the `CANDY_CODE_DISABLE_*` family: "any value other than empty or 0"
 *    disables. Adopting the narrow law at those sites would silently flip
 *    `=off` / `=false` users from disabled back to enabled, so the wide law
 *    stays — it is documented, pinned by the mouse/title tests, and older.
 */
final class EnvBool
{
    /** Values the narrow house law reads as true (case-insensitive). */
    private const TRUE_VALUES = ['1', 'true', 'yes'];

    /**
     * Narrow house law: is the raw value one of `1`, `true`, `yes`
     * (case-insensitive)? Unset reads false; `on`, `off`, `false`, `2`,
     * empty and every other text read false — opt-in only, never by typo.
     */
    public static function isTrue(string|false $raw): bool
    {
        if ($raw === false) {
            return false;
        }

        return \in_array(\strtolower($raw), self::TRUE_VALUES, true);
    }

    /**
     * Presence law of the documented `CANDY_CODE_DISABLE_*` family: an unset,
     * empty, or literal `0` value reads as "not set" so
     * `CANDY_CODE_DISABLE_MOUSE=0` reads as "leave the mouse on" rather than
     * as any-value-means-true; any other text (including `off` and `false`)
     * reads as set.
     */
    public static function isSet(string|false $raw): bool
    {
        return $raw !== false && $raw !== '' && $raw !== '0';
    }
}
