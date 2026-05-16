<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Child as ChildProcess;
use SugarCraft\Pty\Contract\Child;

/**
 * Handle to a child process spawned through a PTY on POSIX systems.
 *
 * Wraps the proc_open() resource so exited() can poll non-blockingly
 * via proc_get_status() and wait() can block until termination. The
 * exit code is captured the first time it becomes available and cached
 * — subsequent calls are idempotent.
 *
 * @see creack/pty.Cmd
 * @see portable-pty.Process
 */
class PosixChild extends ChildProcess implements Child
{
    /**
     * Send a signal to the child process.
     *
     * @param int $signal one of SIGTERM, SIGKILL, SIGINT
     * @see creack/pty.Cmd.Signal()
     */
    public function kill(int $signal): void
    {
        \posix_kill($this->pid, $signal);
    }
}
