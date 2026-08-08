<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents the result of an agent execution within the worker pool.
 *
 * Mirrors upstream result structure: captures output, error, metrics, and timing
 * for each agent run in the parallel execution engine.
 */
final class AgentResult
{
    public function __construct(
        public readonly string $agentId,
        public readonly AgentStatus $status,
        public readonly ?string $output = null,
        public readonly ?\Throwable $error = null,
        public readonly int $tokensUsed = 0,
        public readonly float $costUsd = 0.0,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
    ) {}

    /**
     * Whether the agent completed successfully (no error, status is Completed).
     */
    public function isSuccess(): bool
    {
        return $this->status === AgentStatus::Completed && $this->error === null;
    }

    /**
     * Whether the agent failed (error present or status is Failed/Stopped/TimedOut).
     */
    public function isFailure(): bool
    {
        return $this->error !== null
            || $this->status === AgentStatus::Failed
            || $this->status === AgentStatus::Stopped
            || $this->status === AgentStatus::TimedOut;
    }

    /**
     * Elapsed time in milliseconds between startedAt and completedAt.
     * Returns 0 if either timestamp is null (result is incomplete).
     */
    public function durationMs(): int
    {
        if ($this->startedAt === null || $this->completedAt === null) {
            return 0;
        }

        $startMs = (float) $this->startedAt->format('U.u');
        $finishMs = (float) $this->completedAt->format('U.u');

        return (int) (($finishMs - $startMs) * 1000);
    }
}
