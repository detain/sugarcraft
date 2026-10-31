<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Windows;

use SugarCraft\Pty\Contract\Child;
use SugarCraft\Pty\PtyException;

/**
 * A process attached to a ConPTY pseudo console, reaped through wait
 * handles instead of waitpid.
 *
 * ## The signal story, honestly
 *
 * Windows has no per-signal delivery to a console child, and ConPTY does
 * not fake one. {@see kill()} maps EVERY signal to one
 * TerminateJobObject — the exit code it stamps follows the POSIX `128 + N`
 * convention on purpose, so a caller's existing
 * `$exit === 128 + $signal ? 'killed' : 'exited'` mapping keeps its
 * meaning across backends even though SIGTERM and SIGKILL are
 * indistinguishable on the wire. That is why
 * {@see ConPtySystem::capabilities()} reports signals as false.
 *
 * ## Why a job object instead of TerminateProcess
 *
 * A child of a terminal is rarely one process (npm, cargo, wrappers
 * re-exec). Terminating only the root would leave grandchildren attached
 * to the console; terminating the job tears the whole tree down, and the
 * kill-on-close flag the job was created with means even a dropped handle
 * cannot orphan it.
 *
 * @see ConPtyApi::terminateJob()
 * @see portable-pty.conpty::ConPtyProc::kill — same job-terminate choice
 */
final class ConPtyChild implements Child
{
    private ?int $exitCode = null;

    private bool $released = false;

    /**
     * @param int $process primary process handle (the thread handle is
     *     closed by ConPtyApi::createProcess() itself)
     * @param int $job     the kill-on-close job the process was assigned to
     */
    public function __construct(
        private readonly ConPtyApi $api,
        private readonly int $process,
        private readonly int $pid,
        private readonly int $job,
    ) {
    }

    public function pid(): int
    {
        return $this->pid;
    }

    /**
     * Non-blocking poll. WAIT_TIMEOUT is the only "not yet" answer; a
     * WAIT_FAILED here means the handle is gone (released or foreign), and
     * guessing would hide a lifecycle bug.
     */
    public function exited(): bool
    {
        if ($this->exitCode !== null) {
            return true;
        }

        $result = $this->api->waitForSingleObject($this->process, 0);

        if ($result === ConPtyApi::WAIT_TIMEOUT) {
            return false;
        }

        if ($result !== ConPtyApi::WAIT_OBJECT_0) {
            throw new PtyException(\sprintf(
                'waiting on child process handle %d failed (WAIT_FAILED)',
                $this->process,
            ));
        }

        $this->exitCode = $this->api->getExitCodeProcess($this->process);

        return true;
    }

    /**
     * Block until the child exits, then release both handles.
     *
     * The INFINITE wait is per-operation (it ends when this one child
     * ends), not a request budget — the ceiling law the POSIX side obeys
     * through pcntl_alarm equivalents.
     */
    public function wait(): int
    {
        if ($this->exitCode !== null) {
            $this->release();

            return $this->exitCode;
        }

        $result = $this->api->waitForSingleObject($this->process, ConPtyApi::INFINITE);

        if ($result === ConPtyApi::WAIT_TIMEOUT) {
            // An INFINITE wait cannot time out on a real host; a binding that answers
            // otherwise is lying about the wait, and reading STILL_ACTIVE as an exit
            // code would forge success. Fail loud.
            throw new PtyException(\sprintf(
                'the INFINITE wait on child process handle %d unexpectedly returned WAIT_TIMEOUT',
                $this->process,
            ));
        }

        if ($result === ConPtyApi::WAIT_FAILED) {
            throw new PtyException(\sprintf(
                'waiting on child process handle %d failed (WAIT_FAILED)',
                $this->process,
            ));
        }

        // Order matters: the code is read BEFORE closeJob, whose
        // kill-on-close leg would otherwise race the last GetExitCodeProcess
        // and (in the fake, visibly) restamp the code as 1.
        $this->exitCode = $this->api->getExitCodeProcess($this->process);
        $this->release();

        return $this->exitCode;
    }

    /**
     * null while the child is still running — never a fabricated 0.
     */
    public function exitCode(): ?int
    {
        if ($this->exitCode !== null) {
            return $this->exitCode;
        }

        return $this->exited() ? $this->exitCode : null;
    }

    /**
     * Terminate the whole job. Idempotent after exit (a signal to a reaped
     * child is a no-op, the way signalling our own reaped POSIX child is).
     */
    public function kill(int $signal): void
    {
        if ($this->exitCode !== null) {
            return;
        }

        $this->api->terminateJob($this->job, 128 + $signal);
    }

    /**
     * Containment at teardown, matching the POSIX side: a child nobody
     * waited for is still not allowed to outlive the session. Closing the
     * job handle fires its kill-on-close flag, which is exactly what
     * dropping a proc_open resource does on POSIX. Failures are swallowed
     * HERE and only here — engine shutdown is not a place to throw from,
     * and a leaked job handle dies with the process anyway.
     */
    public function __destruct()
    {
        if ($this->released || $this->exitCode !== null) {
            return;
        }

        try {
            $this->release();
        } catch (\Throwable) {
            // Teardown must not mask a successful test or a clean exit.
        }
    }

    private function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        $this->api->closeHandle($this->process);
        $this->api->closeJob($this->job);
    }
}
