<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Tracks per-agent token output rates and flags agents as stalled when
 * throughput drops below the configured minimum for longer than the
 * configured threshold duration.
 *
 * The detector is a service (mutable) because it maintains state across
 * time — each call to track() updates the agent's token count and
 * timestamp, and isStalled()/getStallWarnings() reflect the accumulated
 * slow-period history.
 *
 * Usage:
 *   $detector = new StallDetector(thresholdSeconds: 30, minTokensPerSecond: 0.5);
 *   $detector->track('agent-1', 1500);
 *   $detector->track('agent-1', 1510);  // 10 tokens in ~5s → fine
 *   // ... after 30s of silence:
 *   $detector->isStalled('agent-1'); // true
 */
final class StallDetector
{
    /** @var array<string, AgentSnapshot> */
    private array $agents = [];

    /**
     * @param int   $thresholdSeconds    Seconds of low throughput before flagging stalled (default 30).
     * @param float $minTokensPerSecond  Minimum token rate considered healthy (default 0.5).
     */
    public function __construct(
        private int $thresholdSeconds = 30,
        private float $minTokensPerSecond = 0.5,
    ) {}

    /**
     * Update the stall threshold at runtime.
     *
     * Allows the TUI to adjust sensitivity without recreating the detector.
     */
    public function setThreshold(int $thresholdSeconds, float $minTokensPerSecond): void
    {
        $this->thresholdSeconds = $thresholdSeconds;
        $this->minTokensPerSecond = $minTokensPerSecond;
    }

    /**
     * Record a token update for an agent and update its rate statistics.
     *
     * Calling this method resets the slow-period accumulator when tokens
     * are arriving at an acceptable rate. When tokens are sparse or absent
     * the accumulator grows, and once it exceeds thresholdSeconds the agent
     * is flagged as stalled.
     */
    public function track(string $agentId, int $tokenCount): void
    {
        $now = microtime(true);

        if (isset($this->agents[$agentId])) {
            $snap = $this->agents[$agentId];
            $elapsed = $now - $snap->lastTimestamp;

            if ($elapsed <= 0) {
                // Clock skew — preserve previous snapshot, only token count advances.
                $this->agents[$agentId] = new AgentSnapshot(
                    lastTokenCount: $tokenCount,
                    lastTimestamp: $snap->lastTimestamp,
                    slowPeriodSeconds: $snap->slowPeriodSeconds,
                    previousTokenCount: $snap->previousTokenCount,
                    previousTimestamp: $snap->previousTimestamp,
                );
                return;
            }

            $tokensDelta = $tokenCount - $snap->lastTokenCount;
            $rate = $tokensDelta / $elapsed;

            if ($rate >= $this->minTokensPerSecond) {
                // Healthy throughput — reset slow period.
                $this->agents[$agentId] = $snap->withPreviousSnapshot(
                    lastTokenCount: $tokenCount,
                    lastTimestamp: $now,
                    slowPeriodSeconds: 0.0,
                );
            } else {
                // Insufficient throughput — accumulate slow time.
                $this->agents[$agentId] = $snap->withPreviousSnapshot(
                    lastTokenCount: $tokenCount,
                    lastTimestamp: $now,
                    slowPeriodSeconds: $snap->slowPeriodSeconds + $elapsed,
                );
            }
        } else {
            // First observation for this agent — just record baseline.
            $this->agents[$agentId] = new AgentSnapshot(
                lastTokenCount: $tokenCount,
                lastTimestamp: $now,
                slowPeriodSeconds: 0.0,
            );
        }
    }

    /**
     * Return true when the given agent has been producing insufficient
     * output for longer than the configured threshold.
     */
    public function isStalled(string $agentId): bool
    {
        if (!isset($this->agents[$agentId])) {
            return false;
        }

        return $this->agents[$agentId]->slowPeriodSeconds >= $this->thresholdSeconds;
    }

    /**
     * Return all agents currently flagged as stalled.
     *
     * @return array<string, StallWarning>
     */
    public function getStallWarnings(): array
    {
        $warnings = [];
        $now = new \DateTimeImmutable();

        foreach ($this->agents as $agentId => $snap) {
            if ($snap->slowPeriodSeconds >= $this->thresholdSeconds) {
                $warnings[$agentId] = new StallWarning(
                    agentId: $agentId,
                    detectedAt: $now,
                    tokenRate: $this->calculateCurrentRate($agentId),
                    durationSeconds: (int) floor($snap->slowPeriodSeconds),
                );
            }
        }

        return $warnings;
    }

    /**
     * Calculate the current approximate token rate for an agent in tokens/second.
     * Uses the delta between the last two track() observations, not the cumulative
     * token count — giving an accurate picture of recent throughput.
     * Returns 0.0 when the agent has no prior observation.
     */
    private function calculateCurrentRate(string $agentId): float
    {
        if (!isset($this->agents[$agentId])) {
            return 0.0;
        }

        $snap = $this->agents[$agentId];

        // Cannot compute rate without a prior observation.
        if ($snap->previousTimestamp === 0.0) {
            return 0.0;
        }

        $timeDelta = $snap->lastTimestamp - $snap->previousTimestamp;
        if ($timeDelta <= 0) {
            return 0.0;
        }

        $tokensDelta = $snap->lastTokenCount - $snap->previousTokenCount;

        return max(0.0, $tokensDelta / $timeDelta);
    }
}

/**
 * Internal snapshot for a single agent's token-tracking state.
 *
 * @internal
 */
final class AgentSnapshot
{
    /**
     * @param int    $lastTokenCount     Most recent token count observed.
     * @param float  $lastTimestamp      Wall-clock (microtime) of most recent observation.
     * @param float  $slowPeriodSeconds Accumulated seconds with throughput below threshold.
     * @param int    $previousTokenCount Token count from the prior observation (for rate delta).
     * @param float  $previousTimestamp  Wall-clock of the prior observation (for rate delta).
     */
    public function __construct(
        public int $lastTokenCount,
        public float $lastTimestamp,
        public float $slowPeriodSeconds,
        public int $previousTokenCount = 0,
        public float $previousTimestamp = 0.0,
    ) {}

    /**
     * Shift current last* values into previous* fields and apply new last* values.
     * Used by track() to maintain the two-point history needed for rate calculation.
     */
    public function withPreviousSnapshot(
        int $lastTokenCount,
        float $lastTimestamp,
        float $slowPeriodSeconds,
    ): self {
        return new self(
            lastTokenCount: $lastTokenCount,
            lastTimestamp: $lastTimestamp,
            slowPeriodSeconds: $slowPeriodSeconds,
            previousTokenCount: $this->lastTokenCount,
            previousTimestamp: $this->lastTimestamp,
        );
    }
}
