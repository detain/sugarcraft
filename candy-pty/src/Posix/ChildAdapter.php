<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Posix;

use SugarCraft\Pty\Child as ChildProcess;

/**
 * @internal
 * @see creack/pty.Cmd
 */
final class ChildAdapter implements \SugarCraft\Pty\Contract\Child
{
    public function __construct(
        private readonly ChildProcess $inner,
    ) {}

    public function pid(): int
    {
        return $this->inner->pid;
    }

    public function exited(): bool
    {
        return $this->inner->exited();
    }

    public function wait(): int
    {
        return $this->inner->wait();
    }

    public function exitCode(): ?int
    {
        return $this->inner->exitCode();
    }

    public function kill(int $signal): void
    {
        if ($signal === self::SIGKILL) {
            \posix_kill($this->inner->pid, \SIGKILL);
        } elseif ($signal === self::SIGTERM) {
            \posix_kill($this->inner->pid, \SIGTERM);
        } elseif ($signal === self::SIGINT) {
            \posix_kill($this->inner->pid, \SIGINT);
        }
    }
}
