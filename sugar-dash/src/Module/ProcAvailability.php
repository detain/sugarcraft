<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

/**
 * Probe-once availability of Linux /proc files — the E731 COMP-2 policy,
 * option (b): probe once, degrade quietly, show a visible 'n/a' sentinel.
 *
 * The /proc pseudo-filesystem exists only on Linux; the system/uptime readers
 * must not fabricate 0% measurements on macOS/Windows. Availability is memoized
 * once per process — the same mechanism readGpuLoad() already uses for its
 * absent-nvidia-smi precedent, generalized here to the /proc doors — so each
 * door costs at most one is_readable() per process lifetime.
 *
 * TEST SEAM RATIONALE (COMP-2 design): the readers' /proc paths are hardcoded,
 * so no test can relocate is_readable() to an unreadable stand-in; the design
 * sanctions injecting the memo directly (lane-cd reset-for-testing idiom —
 * a static property precisely because function-local statics cannot be reset
 * across tests).
 */
final class ProcAvailability
{
    /**
     * User-facing sentinel for a /proc-derived field this host cannot measure.
     * Rendered in place of a fake value (CPU/MEM/UPTIME rows).
     */
    public const UNAVAILABLE_SENTINEL = 'n/a';

    /**
     * In-model sentinel for an unmeasurable percentage. Negative lies outside
     * the legal [0, 100] range, mirroring readGpuLoad()'s -1.0 "no GPU" shape:
     * views gate on >= 0 and history refuses to accumulate the sentinel.
     */
    public const UNMEASURED = -1.0;

    /** @var array<string, bool> path => door answer, memoized at first probe */
    private static array $doorMemo = [];

    /**
     * Is $path readable on this host? Probed once per process; afterwards the
     * answer is stable even if the filesystem changes underneath (probe-once,
     * degrade-quietly).
     */
    public static function has(string $path): bool
    {
        // ??= caches a false answer too (false is not null) — one probe ever.
        return self::$doorMemo[$path] ??= is_readable($path);
    }

    /**
     * TEST SEAM ONLY — override the availability answer for $path without
     * touching the real filesystem; production code must never call this.
     */
    public static function markForTesting(string $path, bool $available): void
    {
        self::$doorMemo[$path] = $available;
    }

    /**
     * TEST SEAM ONLY — drop every memoized door so the next has() re-probes
     * the ambient host. Call from tearDown so a degraded pin can never leak
     * its false into a later readable-branch assertion.
     */
    public static function resetForTesting(): void
    {
        self::$doorMemo = [];
    }
}
