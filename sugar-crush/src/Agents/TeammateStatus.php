<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents the operational state of a teammate agent within a team.
 *
 * Mirrors upstream teammate lifecycle: idle -> active -> waiting -> completed/failed/interrupted.
 */
enum TeammateStatus: string
{
    case Idle = 'idle';
    case Active = 'active';
    case Waiting = 'waiting';
    case Completed = 'completed';
    case Failed = 'failed';
    case Interrupted = 'interrupted';
}
