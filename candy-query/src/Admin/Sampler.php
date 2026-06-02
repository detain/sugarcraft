<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Computes rate-per-second deltas from successive status snapshots.
 *
 * First sample returns null for all rates. Deltas are computed as
 * (newValue - oldValue) / elapsedSeconds. If a restart is detected
 * (uptime decreased), resetAll() is called.
 *
 * @see Mirrors charmbracelet/lazysql Sampler
 */
final class Sampler
{
    private ?array $previous = null;
    private float $previousTs = 0.0;
    private bool $firstSample = true;
    private ?float $lastUptime = null;
    private bool $wasReset = false;

    public function __construct(
        private readonly StatusSnapshotProviderInterface $provider,
    ) {}

    /**
     * Compute rates from the latest polled snapshot.
     *
     * @return array<string, float>|null  Key is variable name, value is rate per second.
     *                                  Returns null if no new data or first sample.
     */
    public function sample(): ?array
    {
        $current = $this->provider->currentSnapshot();
        if ($current === null) {
            return null;
        }

        $currentTs = $this->provider->statusVariablesTs();

        if ($this->firstSample) {
            $this->previous = $current;
            $this->previousTs = $currentTs;
            $this->firstSample = false;
            return null;
        }

        if ($currentTs <= 0) {
            return null;
        }

        if ($this->provider->wasReset()) {
            $this->resetAll();
            return null;
        }

        $elapsed = $currentTs - $this->previousTs;
        if ($elapsed <= 0) {
            return null;
        }

        $rates = [];
        foreach ($current as $key => $value) {
            $oldValue = $this->previous[$key] ?? null;
            if ($oldValue === null) {
                continue;
            }

            $newNum = is_numeric($value) ? (float) $value : null;
            $oldNum = is_numeric($oldValue) ? (float) $oldValue : null;

            if ($newNum !== null && $oldNum !== null) {
                $delta = $newNum - $oldNum;
                if ($delta < 0) {
                    $delta = 0;
                }
                $rates[$key] = $delta / $elapsed;
            }
        }

        $this->previous = $current;
        $this->previousTs = $currentTs;

        return $rates;
    }

    /**
     * Reset state on detected server restart.
     */
    public function resetAll(): void
    {
        $this->previous = null;
        $this->previousTs = 0.0;
        $this->firstSample = true;
        $this->wasReset = true;
    }

    /**
     * Register the current server uptime and detect if the server restarted.
     *
     * If the new uptime is less than the last known uptime, the server
     * has restarted and resetAll() is called.
     */
    public function registerUptime(float $uptime): void
    {
        if ($this->lastUptime !== null && $uptime < $this->lastUptime) {
            $this->resetAll();
            return;
        }

        $this->lastUptime = $uptime;
    }

    /**
     * Check if a server restart was detected since last check.
     *
     * Returns true once after a restart is detected, then clears the flag.
     */
    public function wasReset(): bool
    {
        if ($this->wasReset) {
            $this->wasReset = false;
            return true;
        }

        return false;
    }

    /**
     * Check if this is the first sample (no prior data).
     */
    public function isFirstSample(): bool
    {
        return $this->firstSample;
    }
}