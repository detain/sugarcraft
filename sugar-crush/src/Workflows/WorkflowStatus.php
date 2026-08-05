<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Represents the execution status of a workflow within the sugar-crush system.
 *
 * Mirrors workflow lifecycle: draft -> pending -> running -> paused/resuming -> completed/failed/cancelled.
 */
enum WorkflowStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Resuming = 'resuming';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Returns true when the workflow has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Returns true when the workflow is actively executing or paused.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Running, self::Paused => true,
            default => false,
        };
    }
}
