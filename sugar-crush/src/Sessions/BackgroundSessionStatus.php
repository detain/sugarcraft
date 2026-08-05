<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Sessions;

/**
 * Lifecycle status of a background session.
 *
 * Mirrors upstream status progression for background sessions.
 */
enum BackgroundSessionStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Streaming = 'streaming';
    case Stalled = 'stalled';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stopped = 'stopped';
    case TimedOut = 'timed_out';
}
