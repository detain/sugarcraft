<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Permissions\PermissionGate;

final class SubAgent
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STREAMING = 'streaming';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';

    /** Status of the subagent task. */
    public string $status;
    /** Output accumulated during execution. */
    public string $output;
    /**
     * When execution actually began, as distinct from {@see $createdAt}.
     * A sub-agent can sit `pending` in the pool for a long time, so elapsed
     * *work* time has to be measured from the moment
     * {@see AgentManager::executeSubAgent()} started it — measuring from
     * creation would report queue time as work time (crush_feat.md §5 E6).
     */
    public ?\DateTimeImmutable $startedAt = null;
    /**
     * When the task reached a terminal state — success, failure or stop.
     * Every terminal transition stamps it (see {@see AgentManager}), because
     * {@see elapsedSeconds()} uses it to freeze the work span; a null here on
     * a dead sub-agent would report an ever-growing elapsed time.
     */
    public ?\DateTimeImmutable $completedAt = null;
    /**
     * Tokens consumed so far, accumulated per streaming chunk by
     * {@see AgentManager::executeSubAgent()} rather than only at completion,
     * so a still-running sub-agent reports real usage instead of 0.
     */
    public int $tokensUsed = 0;
    /** Dollar cost accumulated alongside {@see $tokensUsed}. */
    public float $costUsd = 0.0;
    /** Error message if the task failed. */
    public ?string $error = null;

    public function __construct(
        public readonly string $id,
        public readonly Agent $agent,
        public readonly string $task,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public readonly int $timeout = 300,
        public readonly int $maxRetries = 0,
        public readonly Isolation $isolation = Isolation::None,
        public readonly ?PermissionGate $permissionGate = null,
        public readonly ?string $teamId = null,
        public readonly ?string $teammateId = null,
    ) {
        $this->status = self::STATUS_PENDING;
        $this->output = '';
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING
            || $this->status === self::STATUS_STREAMING;
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED
            || $this->status === self::STATUS_FAILED;
    }

    public function durationMs(): ?int
    {
        if ($this->completedAt === null) {
            return null;
        }

        return (int) (($this->completedAt->getTimestamp() - $this->createdAt->getTimestamp()) * 1000);
    }

    /**
     * Wall-clock seconds this sub-agent has been (or was) executing.
     *
     * Returns 0 while the sub-agent is still `pending`: it has not started,
     * so there is no elapsed work time to report — 0 there is the honest
     * value, not a placeholder. Once complete/stopped the span freezes at
     * startedAt -> completedAt; while running it keeps counting against the
     * current time so a long-running agent's status line ticks up.
     */
    public function elapsedSeconds(): int
    {
        if ($this->startedAt === null) {
            return 0;
        }

        $end = $this->completedAt?->getTimestamp() ?? time();

        return max(0, $end - $this->startedAt->getTimestamp());
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'agent' => $this->agent->name,
            'task' => $this->task,
            'status' => $this->status,
            'output' => $this->output,
            'created_at' => $this->createdAt->format('c'),
            'started_at' => $this->startedAt?->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
            'elapsed_seconds' => $this->elapsedSeconds(),
            'tokens_used' => $this->tokensUsed,
            'cost_usd' => $this->costUsd,
            'error' => $this->error,
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'isolation' => $this->isolation->value,
            'has_permission_gate' => $this->permissionGate !== null,
            'team_id' => $this->teamId,
            'teammate_id' => $this->teammateId,
        ];
    }
}
