<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

/**
 * The lifecycle vocabulary of one sub-agent run (v4-F15).
 *
 * These six backing values are THE set {@see SubAgent::$status} takes —
 * the STATUS_* constants on {@see SubAgent} remain the public spelling for
 * every consumer that reads or assigns the raw string (they are stringly
 * across out-of-scope consumer files, so the property keeps its type here;
 * retyping it is a cross-file refactor, not a lane edit). A lockstep pin in
 * SubAgentTest asserts the constants and these cases stay one vocabulary, so
 * neither can drift from the other unnoticed.
 *
 * Distinct from {@see TaskStatus}, which is the team-task backlog's state,
 * and from the Live overlay's coarser queued/running/finished — this is the
 * run's own fate.
 */
enum SubAgentStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Streaming = 'streaming';
    case Complete = 'complete';
    case Stopped = 'stopped';
    case Failed = 'failed';
}
