<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

/**
 * Polls SHOW GLOBAL STATUS on a 3-second cadence.
 *
 * Throttles via AsyncOps::throttle so that even if triggered more
 * frequently, the actual poll only runs at most once every 3 seconds.
 *
 * @see Mirrors charmbracelet/lazysql StatusPoller
 */
final class StatusPoller implements StatusSnapshotProviderInterface
{
    private float $lastPollAt = 0.0;
    private float $lastPollTs = 0.0;
    private bool $pollInFlight = false;
    private bool $hasPolled = false;

    /** @var array<string, string>|null */
    private ?array $lastSnapshot = null;

    /** @var array<string, string>|null */
    private ?array $currentSnapshot = null;

    /** @var Sampler|null */
    private ?Sampler $sampler = null;

    private ?float $lastUptime = null;

    public function __construct(
        private readonly ServerContextInterface $context,
        private readonly float $cadenceSeconds = 3.0,
    ) {}

    /**
     * Set the sampler for uptime tracking and restart detection.
     */
    public function setSampler(Sampler $sampler): void
    {
        $this->sampler = $sampler;
    }

    /**
     * Poll if enough time has elapsed since the last poll.
     * First poll establishes baseline and returns null.
     *
     * @return array<string, string>|null The new snapshot, or null if not polled
     */
    public function poll(): ?array
    {
        $now = $this->context->statusVariablesTs();
        $elapsed = $now - $this->lastPollAt;

        if ($this->lastPollAt > 0 && $elapsed < $this->cadenceSeconds) {
            return null;
        }

        if ($this->pollInFlight) {
            return null;
        }

        $firstPoll = !$this->hasPolled;

        $this->pollInFlight = true;

        try {
            $this->lastSnapshot = $this->currentSnapshot;
            $this->currentSnapshot = $this->context->statusVariables();
            $this->lastPollTs = $this->context->statusVariablesTs();
            $this->pollInFlight = false;
            $this->hasPolled = true;

            $this->trackUptimeFromSnapshot();

            if ($firstPoll) {
                return null;
            }
            $this->lastPollAt = $now;
            return $this->currentSnapshot;
        } catch (\Throwable) {
            $this->pollInFlight = false;
            return null;
        }
    }

    /**
     * Extract Uptime from the current snapshot and register it with the sampler.
     */
    private function trackUptimeFromSnapshot(): void
    {
        if ($this->sampler === null) {
            return;
        }

        $uptimeStr = $this->currentSnapshot['Uptime'] ?? null;
        if ($uptimeStr === null || !is_numeric($uptimeStr)) {
            return;
        }

        $uptime = (float) $uptimeStr;

        if ($this->lastUptime !== null && $uptime < $this->lastUptime) {
            $this->sampler->resetAll();
        }

        $this->lastUptime = $uptime;
        $this->sampler->registerUptime($uptime);
    }

    /**
     * Check if the server was restarted since the last poll.
     */
    public function wasReset(): bool
    {
        return $this->context->wasReset();
    }

    /**
     * Get the timestamp of the most recent status variables snapshot.
     */
    public function statusVariablesTs(): float
    {
        return $this->lastPollTs;
    }

    /**
     * Get the most recent snapshot, or null if no poll has completed.
     *
     * @return array<string, string>|null
     */
    public function currentSnapshot(): ?array
    {
        return $this->currentSnapshot;
    }

    /**
     * Get the snapshot from the previous poll, or null if only one poll has completed.
     *
     * @return array<string, string>|null
     */
    public function previousSnapshot(): ?array
    {
        return $this->lastSnapshot;
    }
}