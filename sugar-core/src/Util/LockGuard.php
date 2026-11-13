<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

/**
 * Proof of a held {@see LockFile} lock. Released by {@see release()} or, as a
 * safety net, when the guard is garbage-collected; releasing twice is a no-op.
 *
 * The lock file itself is never deleted on release — unlinking a lock races
 * a waiter blocked on the old inode into a fresh one.
 */
final class LockGuard
{
    /** @var resource|null */
    private $handle;

    /**
     * @param resource $handle An open stream already holding the flock.
     *
     * @internal Minted by {@see LockFile}; construct no other way.
     */
    public function __construct($handle, private readonly string $path, private readonly bool $exclusive)
    {
        $this->handle = $handle;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exclusive(): bool
    {
        return $this->exclusive;
    }

    public function held(): bool
    {
        return \is_resource($this->handle);
    }

    public function release(): void
    {
        if (!\is_resource($this->handle)) {
            $this->handle = null;

            return;
        }

        // The exclusive holder's pid note goes with the lock, so a later
        // contender never reads a stale pid off a lock that was released cleanly.
        if ($this->exclusive) {
            @ftruncate($this->handle, 0);
        }
        @flock($this->handle, \LOCK_UN);
        @fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
