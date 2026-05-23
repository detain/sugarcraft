<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use SugarCraft\Pty\Posix\ChildPollTrait;

/**
 * Bare stub exercising {@see ChildPollTrait} without the PTY syscall surface.
 *
 * The trait reads `$this->process` and `$this->pid`, both wired up by this
 * stub's constructor — the same shape {@see \SugarCraft\Pty\Child} uses.
 *
 * Lives in its own file so PSR-4 autoload can resolve it when a single test
 * file referencing it is run in isolation (e.g. ChildPollWaitpidTest).
 */
final class ChildPollTraitStub
{
    use ChildPollTrait;

    /**
     * @param resource $process
     */
    public function __construct(
        public readonly int $pid,
        $process,
    ) {
        if (!\is_resource($process)) {
            throw new \InvalidArgumentException('stub requires live proc_open() resource');
        }
        $this->process = $process;
    }

    public function __destruct()
    {
        $this->pollDestruct();
    }

    /**
     * Test-only escape hatch — true while the underlying resource is alive.
     */
    public function hasLiveProcess(): bool
    {
        return \is_resource($this->process);
    }
}
