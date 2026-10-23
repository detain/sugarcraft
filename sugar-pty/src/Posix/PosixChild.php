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
 * This class is `final` (house law: public classes close unless a
 * contract requires extension); consumers needing polymorphism type
 * against {@see \SugarCraft\Pty\Contract\Child}.
 *
 * Migration note: it extends the deprecated {@see \SugarCraft\Pty\Child}
 * alias only to inherit the shared constructor, the promoted `$pid`
 * property and the {@see ChildPollTrait} plumbing — the alias stays
 * alive as long as this class needs it. The v2.0 plan folds that
 * plumbing into this class and deletes the alias; the `public readonly
 * int $pid` property survives the fold, but contract-typed code should
 * call `pid()` (the {@see \SugarCraft\Pty\Contract\Child} interface
 * declares the method only, never the property).
 *
 * @see creack/pty.Cmd
 * @see portable-pty.Process
 */
final class PosixChild extends ChildProcess implements Child
{
    /**
     * Send a signal to the child process.
     *
     * @param int $signal one of SIGTERM, SIGKILL, SIGINT
     * @see creack/pty.Cmd.Signal()
     */
    public function kill(int $signal): void
    {
        // Libc::kill(), not a bare posix_kill(): ext-posix is optional.
        \SugarCraft\Pty\Libc::kill($this->pid, $signal);
    }
}
