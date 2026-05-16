<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Posix\ChildPollTrait;

/**
 * Handle to a child process spawned through {@see Pty::spawn()}.
 *
 * @deprecated since v0.x; use SugarCraft\Pty\Posix\PosixChild directly.
 *             Will be removed in v2.0. This class is a backward-
 *             compatible alias for PosixChild.
 *
 * Mirrors charmbracelet/x/xpty.Cmd's lifecycle hooks (`Wait`,
 * `ProcessState.Exited`).
 */
class Child
{
    use ChildPollTrait;

    /**
     * @param resource $process the proc_open() handle
     */
    public function __construct(
        public readonly int $pid,
        $process,
    ) {
        if (!\is_resource($process)) {
            throw new \InvalidArgumentException('Child requires a live proc_open() resource');
        }
        $this->process = $process;
    }

    public function __destruct()
    {
        $this->pollDestruct();
    }
}
