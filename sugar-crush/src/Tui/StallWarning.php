<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui;

/**
 * Value object representing a detected stall condition on an agent.
 *
 * A stall means the agent's token output rate has dropped below the
 * configured threshold for longer than the acceptable duration, indicating
 * it may be stuck or non-responsive.
 */
final class StallWarning
{
    public function __construct(
        /** Agent that triggered the stall warning. */
        public readonly string $agentId,
        /** When the stall was first detected. */
        public readonly \DateTimeImmutable $detectedAt,
        /** Token throughput in tokens/second at time of detection. */
        public readonly float $tokenRate,
        /** How many seconds the agent has been producing insufficient output. */
        public readonly int $durationSeconds,
    ) {}

    /**
     * Return true when the stall has persisted beyond the given timeout.
     * Once timed out the supervisor knows the agent is genuinely stuck
     * and requires user intervention.
     */
    public function isTimedOut(int $timeoutSeconds): bool
    {
        return $this->durationSeconds >= $timeoutSeconds;
    }
}
