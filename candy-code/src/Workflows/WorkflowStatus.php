<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

/**
 * Represents the execution status of a workflow within the candy-code system.
 *
 * Mirrors workflow lifecycle: draft -> pending -> running -> paused/resuming -> completed/failed/cancelled.
 * {@see WorkflowStatus::Orphaned} is the crash-class sibling of paused: the
 * run's process died without a cooperative stop, its on-disk record still
 * holds the progress, and a resume can pick it up.
 * {@see WorkflowStatus::Skipped} is the one case that never describes a
 * run: it is a stage-row fact — a `when:` clause decided the stage would
 * not run, so it was recorded settled without ever dispatching.
 */
enum WorkflowStatus: string
{
    case Draft = 'draft';
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Orphaned = 'orphaned';
    case Resuming = 'resuming';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Skipped = 'skipped';

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
     * Returns true when the workflow is actively executing, paused, or
     * orphaned — the in-flight class a resume can still act on, as opposed to
     * a terminal state.
     */
    public function isActive(): bool
    {
        return match ($this) {
            self::Running, self::Paused, self::Orphaned => true,
            default => false,
        };
    }
}
