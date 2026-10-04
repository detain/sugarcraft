<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * One held seat of a session's concurrent-sub-agent cap (roadmap 4.7-3) —
 * see {@see DelegationSlots}. Held for exactly as long as this object lives:
 * {@see release()}, or the destructor when the run that took it returns or
 * throws, gives the seat back.
 *
 * RELEASED BY CLOSING, NEVER BY UNLOCKING. A delegated run forks: a parallel
 * batch's members, and the turn's own children, inherit this descriptor. An
 * flock() belongs to the open file description, not to a process, so a forked
 * copy that called `flock(LOCK_UN)` on its way out would free the seat while
 * the run that holds it is still working. Closing only drops that process's
 * reference; the kernel frees the lock when the LAST one closes — when the
 * holder and every child it forked are done, or dead, which is also why a
 * crashed run can never leak a seat.
 */
final class DelegationSlot
{
    /** @var resource|null */
    private mixed $handle;

    /**
     * @param resource $handle the slot file, exclusively flock()ed
     */
    public function __construct(mixed $handle, public readonly int $index)
    {
        $this->handle = $handle;
    }

    /** Whether this object still holds its seat. */
    public function held(): bool
    {
        return \is_resource($this->handle);
    }

    /** Give the seat back (idempotent). */
    public function release(): void
    {
        if (\is_resource($this->handle)) {
            \fclose($this->handle);
        }
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
