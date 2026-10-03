<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Providers\CacheBreakpoints;
use SugarCraft\Crush\Usage;

/**
 * The turn loop's consumer of {@see CacheBreakpoints::observeCacheHealth()}:
 * every step's {@see Usage} goes in, and the first time the diagnostic names
 * three consecutive zero-cache reports the sentence goes out ONCE through
 * {@see RuntimeNoticeSink}.
 *
 * ONCE, NOT ON EVERY REPORT AFTER THE THIRD. observeCacheHealth() keeps
 * returning its sentence for as long as the zeros continue, and leaves the
 * suppression to its consumer. A broken cache does not heal mid-session, so
 * repeating the row on every later step would bury the transcript in one
 * fact; said once, it stays readable.
 *
 * MUTABLE AND SHARED, ON PURPOSE. {@see EngineBackend} is immutable and every
 * wither rebuilds it, but the streak is session state: a backend clone must
 * add to the same count, and a notice one clone raised must not be raised
 * again by another. So the backend holds one instance and every clone carries
 * the same reference forward.
 *
 * ACROSS THE FORK. On the TUI path the turn runs in a `pcntl_fork()`ed child,
 * whose copy of this object dies with it. {@see state()} and {@see adopt()}
 * are the plain-array carry for the turn's result frame, the same rule
 * {@see Usage::toArray()} follows (the parent unserializes with
 * `allowed_classes => false`).
 */
final class CacheHealthWatch
{
    private readonly CacheBreakpoints $breakpoints;

    private bool $noticed = false;

    public function __construct()
    {
        // Used for its diagnostic only — apply() is never called on it, so
        // whether it would add marks is irrelevant.
        $this->breakpoints = new CacheBreakpoints();
    }

    /**
     * Feed one provider response's usage. Returns the notice raised by THIS
     * observation, or null — including every observation after the one-time
     * notice has gone out.
     */
    public function observe(?Usage $usage): ?string
    {
        $warning = $this->breakpoints->observeCacheHealth($usage);

        if ($warning === null || $this->noticed) {
            return null;
        }

        $this->noticed = true;
        RuntimeNoticeSink::warn($warning);

        return $warning;
    }

    /** Whether the one-time notice has been raised this session. */
    public function noticed(): bool
    {
        return $this->noticed;
    }

    /**
     * The carry for the fork's result frame.
     *
     * @return array{zeroReports: int, noticed: bool}
     */
    public function state(): array
    {
        return [
            'zeroReports' => $this->breakpoints->zeroReportStreak(),
            'noticed' => $this->noticed,
        ];
    }

    /**
     * Take over the state a forked child's copy reached. Anything but the
     * shape {@see state()} writes — a frame from a child that predates the
     * key, or garbage — leaves this watch as it was: the streak then restarts
     * at worst, and a notice can only be withheld, never fabricated.
     */
    public function adopt(mixed $state): void
    {
        if (!is_array($state)
            || !is_int($state['zeroReports'] ?? null)
            || !is_bool($state['noticed'] ?? null)) {
            return;
        }

        $this->breakpoints->resumeZeroReportStreak($state['zeroReports']);
        // A notice the child raised has crossed on the notice transport; it
        // must not be raised again here. One the parent already raised stays
        // raised whatever the child believed.
        $this->noticed = $this->noticed || $state['noticed'];
    }
}
