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
     * Window size belongs to the pty (TIOCSWINSZ on the master), not to the
     * session relationship. An explicit geometry must reach the child on
     * every spawn that asks for one (creack/pty.StartWithSize()); omitting
     * $cols/$rows KEEPS the pair's current size (creack/pty.Start(),
     * portable-pty spawn_command()) — a spawn must never restamp a default
     * 80x24 over a geometry the caller already chose.
     *
     * @param list<string>              $cmd
     * @param array<string,string>|null $env                null inherits parent env
     * @param ?int                      $cols               requested window width; null keeps current
     * @param ?int                      $rows               requested window height; null keeps current
     * @param bool                      $controllingTerminal claim slave as child's ctty (Ctrl+C → SIGINT)
     *
     * @see creack/pty.Start()
     * @see portable-pty.SlavePty.Start()
     */
    public function spawn(
        array $cmd,
        ?array $env = null,
        ?int $cols = null,
        ?int $rows = null,
        bool $controllingTerminal = false,
    ): Child;
}
