<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentStatus;

/**
 * Represents a single background session running an agent in a child process.
 *
 * Background sessions continue running after the main TUI closes and report
 * their health via heartbeats. The BackgroundSupervisor monitors these
 * heartbeats and flags a session as stalled if they stop arriving.
 *
 * Mirrors charmbracelet/charmcrush background session design.
 */
final class BackgroundSession
{
    /** @var list<string> User-defined labels for organization */
    public readonly array $tags;

    /** @var int Last heartbeat Unix timestamp */
    private int $lastHeartbeat;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly Agent $agent,
        public readonly string $task,
        public readonly string $workingDirectory,
        public readonly int $timeoutSeconds = 3600,
        ?array $tags = null,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public readonly BackgroundSessionStatus $status = BackgroundSessionStatus::Pending,
        public readonly string $output = '',
    ) {
        $this->tags = $tags ?? [];
        $this->lastHeartbeat = time();
    }

    // =========================================================================
    // Status
    // =========================================================================

    /** @var int Tokens consumed so far */
    public int $tokensUsed = 0;

    /** @var float Estimated cost in USD */
    public float $costUsd = 0.0;

    /** @var \DateTimeImmutable|null When the session finished */
    public ?\DateTimeImmutable $completedAt = null;

    /** @var string|null Error message if the session failed */
    public ?string $error = null;

    /**
     * Update the session status.
     */
    public function withStatus(BackgroundSessionStatus $status): self
    {
        return $this->mutate($status, $this->output);
    }

    /**
     * Update the accumulated output.
     */
    public function withOutput(string $output): self
    {
        return $this->mutate($this->status, $output);
    }

    /**
     * Build the successor instance for a with*() call.
     *
     * The constructor stamps $lastHeartbeat with time(), so a bare `new self`
     * forged a fresh heartbeat on every status change: the supervisor marked a
     * silent session Stalled, that very act reset its heartbeat clock, and the
     * next tick saw a "healthy" session and announced it Running again —
     * flapping stalled/running notices into the transcript forever. Carrying
     * the real heartbeat across means stalled → running only fires when a
     * heartbeat genuinely arrived.
     */
    private function mutate(BackgroundSessionStatus $status, string $output): self
    {
        $clone = new self(
            id: $this->id,
            name: $this->name,
            agent: $this->agent,
            task: $this->task,
            workingDirectory: $this->workingDirectory,
            timeoutSeconds: $this->timeoutSeconds,
            tags: $this->tags,
            createdAt: $this->createdAt,
            status: $status,
            output: $output,
        );
        $clone->lastHeartbeat = $this->lastHeartbeat;

        return $clone;
    }

    /**
     * Record a heartbeat from this session.
     */
    public function recordHeartbeat(): void
    {
        $this->lastHeartbeat = time();
    }

    /**
     * Return true when heartbeats have stopped arriving for longer than the
     * acceptable window. A stalled session is not immediately killed — background
     * sessions are allowed to run longer than foreground ones.
     *
     * Uses the same heartbeat timeout contract as the Phase 1 worker pool:
     * the session is marked stalled when the gap between the last heartbeat
     * and now exceeds BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS.
     */
    public function isStalled(int $heartbeatTimeoutSecs): bool
    {
        return (time() - $this->lastHeartbeat) > $heartbeatTimeoutSecs;
    }

    /**
     * Seconds since the last heartbeat was received.
     */
    public function secondsSinceLastHeartbeat(): int
    {
        return time() - $this->lastHeartbeat;
    }

    // =========================================================================
    // Queries
    // =========================================================================

    public function isRunning(): bool
    {
        return $this->status === BackgroundSessionStatus::Running
            || $this->status === BackgroundSessionStatus::Streaming;
    }

    public function isActive(): bool
    {
        return $this->status !== BackgroundSessionStatus::Completed
            && $this->status !== BackgroundSessionStatus::Failed
            && $this->status !== BackgroundSessionStatus::Stopped;
    }

    public function isComplete(): bool
    {
        return $this->status === BackgroundSessionStatus::Completed;
    }

    public function isFailure(): bool
    {
        return $this->error !== null
            || $this->status === BackgroundSessionStatus::Failed
            || $this->status === BackgroundSessionStatus::Stopped;
    }

    /**
     * Elapsed time in seconds since the session was created.
     */
    public function elapsedSeconds(): int
    {
        return time() - $this->createdAt->getTimestamp();
    }

    /**
     * Human-readable elapsed time string (e.g. "2m 30s").
     */
    public function elapsedDisplay(): string
    {
        $secs = $this->elapsedSeconds();
        if ($secs < 60) {
            return "{$secs}s";
        }
        $mins = intdiv($secs, 60);
        $remainderSecs = $secs % 60;
        if ($mins < 60) {
            return "{$mins}m " . ($remainderSecs > 0 ? "{$remainderSecs}s" : '');
        }
        $hours = intdiv($mins, 60);
        $remainderMins = $mins % 60;
        return "{$hours}h " . ($remainderMins > 0 ? "{$remainderMins}m" : '');
    }

    /**
     * Token usage display string.
     */
    public function usageDisplay(): string
    {
        if ($this->tokensUsed === 0) {
            return '';
        }
        return number_format($this->tokensUsed) . ' tokens';
    }

    /**
     * Build a complete AgentResult from the final session state.
     */
    public function toAgentResult(): AgentResult
    {
        $status = match ($this->status) {
            BackgroundSessionStatus::Completed => AgentStatus::Completed,
            BackgroundSessionStatus::Failed => AgentStatus::Failed,
            BackgroundSessionStatus::Stopped => AgentStatus::Stopped,
            BackgroundSessionStatus::TimedOut => AgentStatus::TimedOut,
            default => AgentStatus::Running,
        };

        return new AgentResult(
            agentId: $this->id,
            status: $status,
            output: $this->output ?: null,
            error: $this->error !== null ? new \RuntimeException($this->error) : null,
            tokensUsed: $this->tokensUsed,
            costUsd: $this->costUsd,
            startedAt: $this->createdAt,
            completedAt: $this->completedAt,
        );
    }

    /**
     * Convert to an array for serialization/persistence.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'tags' => $this->tags,
            'agent' => [
                'name' => $this->agent->name,
                'model' => $this->agent->model,
            ],
            'task' => $this->task,
            'working_directory' => $this->workingDirectory,
            'timeout_seconds' => $this->timeoutSeconds,
            'status' => $this->status->value,
            'output' => $this->output,
            'tokens_used' => $this->tokensUsed,
            'cost_usd' => $this->costUsd,
            'error' => $this->error,
            'created_at' => $this->createdAt->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
            'last_heartbeat' => $this->lastHeartbeat,
        ];
    }
}
