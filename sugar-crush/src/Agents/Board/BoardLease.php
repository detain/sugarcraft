<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Board;

/**
 * The one owner of a {@see Board}'s file: the board is removed when the last
 * view of it in the process that created it goes away.
 *
 * WHY A DESTRUCTOR. A board lives exactly as long as its parallel batch, and
 * {@see \SugarCraft\Crush\Runtime::executeConcurrently()} holds the batch's
 * views (its own, and each member's copy of the `Task` tool) in the generator
 * frame that drives the batch. When that frame is gone — every member
 * released, or the consumer walked away — nothing can read the board again,
 * and this object, shared by every view {@see Board::forMember()} makes, is
 * released with it. The same scoping {@see \SugarCraft\Crush\Agents\DelegationSlots}
 * gives a seat: a local that lives exactly as long as what it guards, so a
 * throw cannot strand it.
 *
 * THE PID IS THE POINT. Each member runs in a forked child holding a copy of
 * this object. A child leaves through {@see \SugarCraft\Crush\Support\ForkedChild::exitNow()},
 * which runs no destructors; a child that ever did must still not delete the
 * board its siblings are writing, so only the creating process removes it.
 * A process killed outright leaves the file to
 * {@see \SugarCraft\Crush\Support\ToolIpcFiles::sweep()}, whose prefix it
 * carries.
 */
final class BoardLease
{
    private readonly int $owner;

    private bool $released = false;

    public function __construct(
        private readonly string $path,
    ) {
        $this->owner = getmypid() ?: 0;
    }

    public function __destruct()
    {
        $this->release();
    }

    /** Remove the board's file, once, and only from the process that made it. */
    public function release(): void
    {
        if ($this->released || (getmypid() ?: 0) !== $this->owner) {
            return;
        }

        $this->released = true;
        @unlink($this->path);
    }
}
