<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents\Live;

use SugarCraft\Code\Config\Settings\UiSettings;

/**
 * The pure stall detector over {@see AgentLiveRegistry} rows (RS-AGENT-BACKLOG
 * A1): given a run id, answer how long its last accepted activity frame has
 * been in the past, and whether that gap passes the configured threshold.
 *
 * DETECTION AT OBSERVATION TIME, NEVER ON A TIMER (E646). Nothing here arms a
 * timer, spawns a watcher, or loops against the clock: the registry already
 * stores its clock ({@see AgentLiveRegistry::now()} advances once per update
 * tick, per the "the clock is state too" law), and {@see apply()} stamps a
 * beat only where it writes an accepted fold. A question asked here is plain
 * arithmetic over two stored floats, so a renderer may ask it every frame —
 * and a test answers it deterministically by driving the injected clock.
 *
 * DETECTOR ONLY. This file flags; it never intervenes. Acting on a stall —
 * restarting the run, cancelling it, escalating — is the follow-up (B5) and
 * will consume {@see stallSeconds()} from wherever it lives; the today-only
 * consumer is the agent strip's marker in
 * {@see \SugarCraft\Code\Tui\AgentStatusBar}, a presentation change with zero
 * effect on scheduling.
 *
 * The threshold rides the `agentStallSeconds` setting: `0` (the default) is
 * OFF — every answer is null and every render is byte-identical to before
 * this mechanism existed — and a set value under {@see FLOOR_SECONDS} is
 * clamped UP to it at read time (a stall flag ticking every 5 s would be a
 * flicker, not a signal). Clamping is deliberately the reader's job, not the
 * validator's: a nonsense stored value falls through {@see UiSettings::int()}
 * to the schema default (OFF) rather than refusing the save.
 */
final class RunLiveness
{
    /** The settings key carrying the threshold, seconds. */
    public const SETTING_KEY = 'agentStallSeconds';

    /** The threshold value that disables the detector entirely. */
    public const OFF = 0;

    /** Smallest effective threshold: a set value under this rises to it. */
    public const FLOOR_SECONDS = 30;

    /**
     * The effective threshold for this render, seconds: the stored setting
     * pushed through {@see clampThreshold()}. Also the settings schema's
     * reader symbol for `agentStallSeconds`.
     */
    public static function thresholdSeconds(): int
    {
        return self::clampThreshold(UiSettings::int(self::SETTING_KEY));
    }

    /**
     * The threshold law, pure: zero or below is OFF (never a negative
     * countdown), a set value below {@see FLOOR_SECONDS} rises to the floor,
     * anything else rides unchanged.
     */
    public static function clampThreshold(int $raw): int
    {
        if ($raw <= self::OFF) {
            return self::OFF;
        }

        return max($raw, self::FLOOR_SECONDS);
    }

    /**
     * Whole seconds since the run's last accepted frame, or null when there
     * is nothing to measure: an unknown id, a run that never folded a beat,
     * or a finished run (its row stands as history; time since its last word
     * is not a stall). The answer reads only stored state — the registry's
     * clock as of the last {@see AgentLiveRegistry::advance()}.
     */
    public static function idleSeconds(AgentLiveRegistry $registry, ?string $runId): ?int
    {
        if ($runId === null) {
            return null;
        }

        $state = $registry->get($runId);
        if ($state === null || $state->isFinished()) {
            return null;
        }

        $beat = $registry->lastBeatAt($runId);
        if ($beat === null) {
            return null;
        }

        return max(0, (int) floor($registry->now() - $beat));
    }

    /**
     * The stall verdict: idle seconds when the run is silent for STRICTLY
     * more than the threshold, null for anything else — detector OFF, no row
     * to measure, or a gap still within budget. Strictly-over, not
     * at-or-over, because a threshold of N seconds promises N quiet seconds
     * before it flags, not N minus one.
     *
     * @param int|null $threshold override for {@see thresholdSeconds()} —
     *                            tests pass their own to stay off the user's
     *                            config file; null reads the setting.
     */
    public static function stallSeconds(AgentLiveRegistry $registry, ?string $runId, ?int $threshold = null): ?int
    {
        $threshold ??= self::thresholdSeconds();
        if ($threshold === self::OFF) {
            return null;
        }

        $idle = self::idleSeconds($registry, $runId);
        if ($idle === null) {
            return null;
        }

        return $idle > $threshold ? $idle : null;
    }
}
