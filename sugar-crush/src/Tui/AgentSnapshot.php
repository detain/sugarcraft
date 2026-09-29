<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

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
