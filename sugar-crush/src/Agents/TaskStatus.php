<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents the lifecycle state of a task within a team.
 *
 * Mirrors upstream task states: pending work, in-progress, completed, failed, or blocked by dependencies.
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Blocked = 'blocked';
}
