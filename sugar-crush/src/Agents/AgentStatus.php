<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Represents the lifecycle status of an agent within the worker pool.
 *
 * Mirrors upstream agent state transitions: pending -> queued -> running -> streaming -> completed/failed/stopped/timed_out.
 */
enum AgentStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Running = 'running';
    case Streaming = 'streaming';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stopped = 'stopped';
    case TimedOut = 'timed_out';
}
