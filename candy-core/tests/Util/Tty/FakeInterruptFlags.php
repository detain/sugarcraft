<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util\Tty;

/**
 * Test double for {@see \SugarCraft\Core\Util\Tty\InterruptFlags}.
 *
 * Stores the interrupt flag as a plain private boolean, enabling
 * synchronous test assertions without shared memory or FFI.
 *
 * @internal test-only
 */
final class FakeInterruptFlags
{
    private bool $pending = false;

    public function set(): bool
    {
        $this->pending = true;

        return true;
    }

    /**
     * Read and clear the interrupt-pending flag.
     *
     * Returns `true` the first time it is called while `$pending` is
     * `true`; subsequent calls return `false` until {@see set()} is
     * called again.
     */
    public function consume(): bool
    {
        if (!$this->pending) {
            return false;
        }

        $this->pending = false;

        return true;
    }

    /**
     * Directly set the raw pending state (test injection).
     */
    public function setPending(bool $value): void
    {
        $this->pending = $value;
    }

    /**
     * Return whether the flag is currently pending.
     */
    public function isPending(): bool
    {
        return $this->pending;
    }
}
