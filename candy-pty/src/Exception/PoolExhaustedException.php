<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Exception;

use SugarCraft\Pty\PtyException;

/**
 * Raised when {@see \SugarCraft\Pty\PtyPool::acquire()} is called while
 * the pool is at capacity. Extends {@see PtyException} so callers
 * already catching the generic candy-pty type don't miss the
 * back-pressure signal.
 */
final class PoolExhaustedException extends PtyException
{
    public static function atCapacity(int $maxSize): self
    {
        return new self(\sprintf(
            'PtyPool exhausted: %d sessions already in flight, release one before acquiring again',
            $maxSize,
        ));
    }
}
