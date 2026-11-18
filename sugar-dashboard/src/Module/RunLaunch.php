<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Module;

/**
 * When one run's child was actually launched, as opposed to when its tick asked
 * for it. A host may hold a Cmd for a while before running it, and the settle
 * ceiling a module latches against must count from the launch, or a run that
 * queued for longer than the ceiling is treated as stale while its child is alive.
 *
 * One instance belongs to one run. The module keeps it in its latch and the run's
 * Cmd marks it as the child starts, so the latch reads the launch time the moment
 * the host runs the Cmd.
 */
final class RunLaunch
{
    /** Monotonic second the child was launched; null while the Cmd is still queued. */
    private ?float $launchedAt = null;

    /** Called by the run's Cmd at the moment its child is launched. */
    public function markLaunched(): void
    {
        $this->launchedAt = hrtime(true) / 1_000_000_000;
    }

    /**
     * The second the latch counts from: the launch once it happened, otherwise
     * the tick that asked for the run, so a Cmd the host never runs still goes stale.
     */
    public function latchedSince(float $tickedAt): float
    {
        return $this->launchedAt ?? $tickedAt;
    }
}
