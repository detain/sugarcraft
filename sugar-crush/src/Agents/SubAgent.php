<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

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
    /** When the task completed. */
    public ?\DateTimeImmutable $completedAt = null;
    /** Error message if the task failed. */
    public ?string $error = null;

    public function __construct(
        public readonly string $id,
        public readonly Agent $agent,
        public readonly string $task,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
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

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'agent' => $this->agent->name,
            'task' => $this->task,
            'status' => $this->status,
            'output' => $this->output,
            'created_at' => $this->createdAt->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
            'error' => $this->error,
        ];
    }
}
