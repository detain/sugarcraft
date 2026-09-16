<?php

declare(strict_types=1);

namespace SugarCraft\Glow;

/**
 * File watching utility for auto-reload on change.
 *
 * Mirrors charmbracelet/glow's file watching behaviour.
 *
 * Poll contract (E714, round 82): watch() is a pump — the CALLER owns its
 * lifetime bound. The generator advances lazily: no stat sweep and no sleep
 * runs until the consumer pulls, so breaking out of the foreach (or simply
 * dropping the generator) halts the watcher at the next sweep boundary,
 * within one idle delay. There is deliberately no wall-clock kill inside the
 * loop (E646 law: an idle bound, never a request deadline) and no stop()
 * flag to forget — cancellation is inherent in not pulling.
 *
 * Idle cost is bounded the same way sugar-readline's input pump is
 * (E713 shape): sweeps back off exponentially from IDLE_POLL_MIN_MICROSECONDS
 * (1ms) doubling per consecutive no-change sweep, capped at the caller's
 * $intervalMs, and snap back to the floor whenever a change batch is seen.
 * A fully idle watcher therefore never polls more often than the requested
 * interval — the pre-E714 fixed-cadence behaviour — while bursts of edits
 * are tracked at millisecond latency.
 */
final class FileWatcher
{
    /** Floor of the idle backoff ladder: first sweep after start or after any change. */
    public const IDLE_POLL_MIN_MICROSECONDS = 1_000;

    /**
     * Clamp for the ladder's bit-shift: 1ms << 50 ≈ 1.1e18µs stays inside
     * PHP_INT_MAX, so absurd sweep counts saturate at the cap instead of
     * overflowing into garbage. Any realistic $intervalMs caps out first.
     */
    public const MAX_LADDER_SHIFT = 50;

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Check if the file has been modified since the given mtime.
     * Uses "!==" so that a file restored to its exact prior mtime is detected
     * as a change (covers git checkout restoring an older timestamp).
     */
    public function hasChangedSince(int $mtime): bool
    {
        if (is_file($this->path) === false) {
            return false;
        }

        clearstatcache();
        $currentMtime = @filemtime($this->path);

        return $currentMtime !== false && $currentMtime !== $mtime;
    }

    /**
     * Current (mtime, size) fingerprint of a file, or null when the path is
     * not a readable regular file. Baseline for {@see pollTuple()}.
     *
     * E735 step 10.25: the pager's auto-reload sweep takes one snapshot per
     * tick without sleeping — unlike {@see watch()}, this is a single-shot
     * probe, so the E714 pump contract has nothing to bound here.
     *
     * @return array{0:int, 1:int}|null
     */
    public static function snapshot(string $path): ?array
    {
        if (is_file($path) === false) {
            return null;
        }

        clearstatcache();
        $mtime = @filemtime($path);
        $size  = @filesize($path);

        return $mtime === false ? null : [$mtime, $size === false ? 0 : $size];
    }

    /**
     * One non-blocking change check against a (mtime, size) baseline.
     *
     * Returns the NEW fingerprint when the file moved off the baseline (or
     * appeared where it was absent — baseline [0, 0]), null when unchanged
     * or still missing. This is the same tuple law {@see watch()} runs on
     * every sweep, exposed for callers that poll from a tick pump instead
     * of driving a Generator (the pager's auto-reload, E735 step 10.25).
     *
     * @param int $lastMtime baseline mtime (0 = "file assumed absent")
     * @param int $lastSize  baseline size in bytes (0 pairs with mtime 0)
     * @return array{0:int, 1:int}|null
     */
    public static function pollTuple(string $path, int $lastMtime, int $lastSize): ?array
    {
        $current = self::snapshot($path);
        if ($current === null) {
            return null;
        }

        return ($current[0] !== $lastMtime || $current[1] !== $lastSize) ? $current : null;
    }

    /**
     * Sleep duration in microseconds for the Nth consecutive no-change sweep:
     * 1ms floor, doubling per sweep, capped at $capMicroseconds (E714 ladder).
     *
     * Pure and static so the ladder is pinned without wall-clock timing.
     * Counts below 1 coerce to the floor; absurd counts stay capped — the
     * shift is clamped at MAX_LADDER_SHIFT before it could overflow. A cap
     * below the floor (sub-millisecond intervals) pins every delay to the
     * cap, and a non-positive cap is coerced to 1µs — the pump never spins
     * with a zero sleep.
     *
     * @param int $consecutiveIdleSweeps 1 = first sweep since start or last change
     * @param int $capMicroseconds       Ceiling = the caller's polling interval
     */
    public static function idlePollDelayMicroseconds(int $consecutiveIdleSweeps, int $capMicroseconds): int
    {
        $cap = max(1, $capMicroseconds);
        $floor = min(self::IDLE_POLL_MIN_MICROSECONDS, $cap);

        if ($consecutiveIdleSweeps <= 1) {
            return $floor;
        }

        $shift = min($consecutiveIdleSweeps - 1, self::MAX_LADDER_SHIFT);
        $doubled = self::IDLE_POLL_MIN_MICROSECONDS << $shift;

        return min($doubled, $cap);
    }

    /**
     * Watch a file for changes, yielding true each time it is modified.
     *
     * Uses (mtime, size) tuple polling to catch same-second edits that
     * mtime alone would miss on filesystems with 1-second granularity.
     *
     * @blocking Each pull runs one sleep + stat sweep synchronously in the
     *           consumer's context, so a foreach that never breaks blocks
     *           the event loop forever. The loop itself carries no deadline
     *           by contract — the consumer bounds it by stopping the pull
     *           (see class poll contract, E714).
     * @see \SugarCraft\Glow\FileWatcher::watch() — CALIBER_LEARNINGS.md pattern:glow
     *
     * @param string        $path        Path to watch
     * @param int           $intervalMs  Polling-interval cap in milliseconds; idle
     *                                   sweeps back off up to this, never beyond
     * @param callable|null $idleSleeper Test seam invoked with each computed idle
     *                                   delay in microseconds; defaults to usleep()
     * @return \Generator<bool> Yields true on each change
     */
    public static function watch(string $path, int $intervalMs = 500, ?callable $idleSleeper = null): \Generator
    {
        $baseline = self::snapshot($path);
        if ($baseline === null) {
            return;
        }

        [$lastMtime, $lastSize] = $baseline;

        // The pump refuses a cap below the ladder floor (a zero/negative
        // interval would otherwise usleep(0)-spin); the pure ladder itself
        // still honours any explicit sub-floor cap.
        $capMicroseconds = $intervalMs > intdiv(PHP_INT_MAX, 1000)
            ? PHP_INT_MAX
            : max(self::IDLE_POLL_MIN_MICROSECONDS, $intervalMs * 1000);

        $consecutiveIdleSweeps = 0;

        while (true) {
            // No change yet this pass. Back off along the E714 ladder instead
            // of a fixed-cadence spin; the sleeper seam lets tests pin the
            // schedule without wall-clock time.
            $consecutiveIdleSweeps++;
            self::sleepIdlePoll(
                self::idlePollDelayMicroseconds($consecutiveIdleSweeps, $capMicroseconds),
                $idleSleeper
            );

            $changed = self::pollTuple($path, $lastMtime, $lastSize);
            if ($changed !== null) {
                [$lastMtime, $lastSize] = $changed;
                // Any change batch snaps the ladder back to the 1ms floor.
                $consecutiveIdleSweeps = 0;
                yield true;
            }
        }
    }

    /**
     * Perform one idle-sweep sleep: through the injected seam when present,
     * otherwise usleep(). Never called while changes are flowing (the sweep
     * that yields had its own backoff sleep first, exactly like an idle one).
     */
    private static function sleepIdlePoll(int $microseconds, ?callable $idleSleeper): void
    {
        if ($idleSleeper === null) {
            usleep($microseconds);
            return;
        }

        $idleSleeper($microseconds);
    }
}
