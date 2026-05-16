<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * Handle to a non-PTY child process (for candy-shell migration).
 *
 * @see creack/pty.Cmd
 * @see portable-pty.Process
 */
interface Process
{
    public const SIGTERM = 15;
    public const SIGKILL = 9;
    public const SIGINT = 2;

    /**
     * @see creack/pty.Cmd.Pid()
     */
    public function pid(): int;

    /**
     * Non-blocking exit probe.
     *
     * @see creack/pty.Cmd.Running()
     */
    public function exited(): bool;

    /**
     * Block until the process exits and return its exit code.
     *
     * @see creack/pty.Cmd.Wait()
     */
    public function wait(): int;

    /**
     * Cached exit code, or null if the process is still running.
     *
     * @see creack/pty.Cmd.ExitCode()
     */
    public function exitCode(): ?int;

    /**
     * Send a signal to the process.
     *
     * @param int $signal one of SIGTERM, SIGKILL, SIGINT
     * @see creack/pty.Cmd.Signal()
     */
    public function kill(int $signal): void;

    /**
     * Bytes captured from stdout.
     */
    public function stdoutBytes(): string;

    /**
     * Bytes captured from stderr.
     */
    public function stderrBytes(): string;
}
