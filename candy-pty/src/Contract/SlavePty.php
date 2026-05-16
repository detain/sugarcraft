<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Contract;

/**
 * The slave end of a PTY pair — attached to the child process's
 * stdin/stdout/stderr so the child believes it is talking to a
 * terminal.
 *
 * @see creack/pty.Pty
 * @see portable-pty.SlavePty
 */
interface SlavePty
{
    /**
     * Return the kernel-assigned slave device path.
     *
     * Linux: /dev/pts/N  |  macOS: /dev/ttysNNN
     *
     * @see creack/pty.Pty.Name()
     */
    public function path(): string;

    /**
     * Spawn a child process with its stdio wired to the slave PTY.
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env                null inherits parent env
     * @param bool                      $controllingTerminal claim slave as child's ctty (Ctrl+C → SIGINT)
     * @see creack/pty.Start()
     * @see portable-pty.SlavePty.Start()
     */
    public function spawn(
        array $cmd,
        ?array $env = null,
        int $cols = 80,
        int $rows = 24,
        bool $controllingTerminal = false,
    ): Child;
}
