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

    public function __construct(
        private readonly ServerContextInterface $context,
        private readonly float $cadenceSeconds = 3.0,
    ) {}

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