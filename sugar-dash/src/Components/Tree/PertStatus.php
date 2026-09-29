<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * Status of a PERT task.
 */
enum PertStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Delayed = 'delayed';
}
