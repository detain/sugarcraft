<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Child as ChildProcess;
use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\TerminationPolicy;

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
 * Kill on drop is opt-in ({@see withKillOnDrop()}). By default, destroying
 * the handle reaps a child that has already exited and leaves a running one
 * alone, as it always has. withKillOnDrop() returns a new handle that must
 * be kept: discarding its result kills the child at the end of the statement.
 *
 * @see creack/pty.Cmd
 * @see portable-pty.Process
 */
final class PosixChild extends ChildProcess implements Child
{
    /** The teardown this handle runs when dropped; null keeps the default drop. */
    private ?TerminationPolicy $dropPolicy = null;

    /** This handle's claim on the drop duty; compared against $dropOwner. */
    private ?object $dropToken = null;

    /**
     * The claim that carries the drop duty, shared by reference between every
     * handle on this child, so only the newest kill-on-drop handle tears the
     * child down and a dropped intermediate handle never does.
     */
    private ?object $dropOwner = null;

    /**
     * A handle on the same child that tears it down when the handle is
     * destroyed while the child still runs.
     *
     * KEEP THE RESULT. This does not set a flag on the receiver: it returns a
     * new handle, and that handle carries the kill duty. Calling it as a bare
     * statement, `$child->withKillOnDrop();`, drops the new handle at the end
     * of the statement, which tears the still-running child down at once
     * while `$child` lives on. Assign it: `$child = $child->withKillOnDrop();`.
     *
     * The teardown is $policy's {@see TerminationPolicy::apply()} (the default
     * policy when null: SIGHUP, the core grace budgets, group reach), which
     * runs sugar-core's BoundedShutdown ladder, so a drop blocks for at most
     * the policy's two budgets. The child is then reaped if it is gone.
     *
     * The receiver is not changed: it keeps the default drop. Both handles
     * share the process handle and the exit-code cache, so a reap through
     * either is seen by both. The duty moves to the newest kill-on-drop
     * handle, so in `$child->withKillOnDrop()->withKillOnDrop($other)` the
     * dropped middle handle does not kill the child.
     *
     * Destructors run when the last reference goes, which includes a pending
     * {@see onExit()} or waitAsync() on this handle: their listeners hold it
     * until the child exits.
     *
     * Mirrors tokio's `Command::kill_on_drop(true)` (no charmbracelet
     * upstream: Go has no destructors).
     */
    public function withKillOnDrop(?TerminationPolicy $policy = null): self
    {
        return $this->mutate(['dropPolicy' => $policy ?? TerminationPolicy::new()]);
    }

    /** Whether destroying this handle tears a still-running child down. */
    public function killOnDrop(): bool
    {
        return $this->dropPolicy !== null && $this->dropOwner === $this->dropToken;
    }

    public function __destruct()
    {
        try {
            if ($this->killOnDrop() && !$this->exited()) {
                $this->dropPolicy?->apply($this);
            }
        } catch (\Throwable) {
            // Destructors must not throw; a child the policy could not stop
            // stays running, and the reap below leaves it alone.
        }
        parent::__destruct();
    }

    /** @param array{dropPolicy: TerminationPolicy} $changes */
    private function mutate(array $changes): self
    {
        $view = $this->shareHandle();
        $view->dropOwner = &$this->dropOwner;
        $view->dropPolicy = $changes['dropPolicy'];
        $view->dropToken = new \stdClass();
        $view->dropOwner = $view->dropToken;

        return $view;
    }

    /**
     * Send a signal to the child process.
     *
     * @param int $signal one of SIGTERM, SIGKILL, SIGINT
     * @see creack/pty.Cmd.Signal()
     */
    public function kill(int $signal): void
    {
        // Post-reap pid-reuse guard: once we have observed the child
        // exit, its pid may belong to another process — never signal it.
        if ($this->exited()) {
            return;
        }
        // Libc::kill(), not a bare posix_kill(): ext-posix is optional.
        if (!\SugarCraft\Pty\Libc::kill($this->pid, $signal) && !$this->exited()) {
            throw new \SugarCraft\Pty\PtyException(
                "kill(signal {$signal}) failed for pid {$this->pid}",
            );
        }
    }
}
