<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin\ServerStatus;

/**
 * Liveness of the admin data feed backing the Server Status page.
 *
 * Mirrors the green play-arrow / grey stop-square header MySQL Workbench
 * shows above Management :: Server Status (query_dashboard.md line 45):
 * the triangle means the monitor thread is still landing samples, the
 * square means it is not.
 */
enum RunState: string
{
    case Running = 'running';
    case Stopped = 'stopped';
    case Unreachable = 'unreachable';
}
