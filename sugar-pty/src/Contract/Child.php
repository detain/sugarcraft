<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * Handle to a child process spawned through a PTY.
 *
 * @see creack/pty.Cmd
 * @see portable-pty.Process
 */
interface Child
{
    public const SIGTERM = 15;
    public const SIGKILL = 9;
    public const SIGINT = 2;

    /**
     * @see creack/pty.Cmd.Pid()
     * @see portable-pty.Process.Pid()
     */
    public function pid(): int;

    /**
     * Non-blocking exit probe.
     *
     * @see creack/pty.Cmd.Running()
     */
    public function exited(): bool;

    /**
     * Block until the child exits and return its exit code.
     *
     * @see creack/pty.Cmd.Wait()
     */
    public function wait(): int;

    /**
     * Cached exit code, or null if the child is still running.
     *
     * @see creack/pty.Cmd.ExitCode()
     */
    public function exitCode(): ?int;

    /**
     * Send a signal to the child process.
     *
     * @param int $signal one of SIGTERM, SIGKILL, SIGINT
     * @see creack/pty.Cmd.Signal()
     */
    public function kill(int $signal): void;
}
