<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Board;

/**
 * The binding {@see ActiveBoard::enter()} replaced, put back when this seat
 * goes — at the end of the run that holds it, however that run ends.
 *
 * Restored only in the process that entered: a forked child that inherited a
 * copy must not rewind its parent's binding (it leaves through
 * {@see \SugarCraft\Crush\Support\ForkedChild::exitNow()}, which runs no
 * destructors, but the check keeps that from being load-bearing).
 */
final class ActiveBoardSeat
{
    private readonly int $owner;

    private bool $left = false;

    public function __construct(
        private readonly ?Board $previous,
        private readonly int $previousNoticed,
    ) {
        $this->owner = getmypid() ?: 0;
    }

    public function __destruct()
    {
        $this->leave();
    }

    /** Restore the binding this seat replaced, once. */
    public function leave(): void
    {
        if ($this->left || (getmypid() ?: 0) !== $this->owner) {
            return;
        }

        $this->left = true;
        ActiveBoard::restore($this->previous, $this->previousNoticed);
    }
}
