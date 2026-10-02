<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Session;

/**
 * The single-writer lock on one stored session (audit SES-3(b)): an exclusive
 * `flock()` on `<configDir>/sessions/<id>.lock`, held for as long as a TUI has
 * that session open.
 *
 * WHY A LOCK AND NOT A ROW IN THE DATABASE. Two TUIs on one session (`-c` in
 * two terminals, `--resume X` twice) each rewrite the whole transcript, so the
 * last writer silently erases the other's conversation. A `sessions.owner_pid`
 * column would need a liveness check (pid reuse, a crashed owner, a pid from
 * another host sharing the home directory), and getting that wrong locks a
 * user out of their own session. The kernel already answers "is the holder
 * still alive" for a `flock()`: the lock dies with the last descriptor, so a
 * crashed or killed TUI frees its session with nothing to clean up.
 *
 * NON-BLOCKING, ALWAYS. {@see acquire()} never waits: a held session is an
 * answer ("open it read-only"), not something to queue behind.
 *
 * WHAT A FAILED LOCK MEANS. {@see acquire()} returns null only when ANOTHER
 * holder has the lock. When locking itself cannot work — the directory cannot
 * be created, the file cannot be opened, the filesystem refuses `flock()` — it
 * returns an UNENFORCED lock ({@see isEnforced()} false) and the session opens
 * writable, which is how every session opened before this class existed.
 * Refusing to write because the lock file could not be made would turn a
 * read-only home directory into a TUI that can save nothing.
 *
 * FORK AND EXEC. The descriptor is opened close-on-exec, so a `Bash` tool call,
 * a hook or an MCP server never inherits it and cannot keep the session locked
 * after the TUI exits. A `pcntl_fork()`ed child (a turn, a sub-agent) does
 * share it; {@see release()} and the destructor act only in the process that
 * took the lock, so a child that exits normally cannot unlock its parent's
 * session.
 */
final class SessionLock
{
    /** The subdirectory of the config directory the lock files live in. */
    public const DIRECTORY = 'sessions';

    /** @var resource|null */
    private $handle;

    /**
     * @param resource|null $handle
     */
    private function __construct(
        private readonly string $sessionId,
        private readonly ?string $path,
        $handle,
        private readonly int $ownerPid,
    ) {
        $this->handle = $handle;
    }

    /**
     * Take the lock on $sessionId, or null when another process (or another
     * open of the same file in this process) holds it.
     *
     * $directory null means the store has no directory to keep locks in (an
     * in-memory database), and the lock is unenforced.
     */
    public static function acquire(?string $directory, string $sessionId): ?self
    {
        if ($directory === null || $directory === '') {
            return self::unenforced($sessionId);
        }

        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return self::unenforced($sessionId);
        }

        $path = self::pathFor($directory, $sessionId);

        // A few attempts, not one: release() unlinks the file, so a process
        // that opened the path just before the unlink can win the lock on the
        // orphaned inode. The identity check below catches that and retries
        // against the file now at the path. Three is plenty — losing the race
        // needs another TUI to release in the microseconds between two calls.
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $handle = @fopen($path, 'c+e');
            if ($handle === false) {
                return self::unenforced($sessionId);
            }

            $wouldBlock = 0;
            if (!@flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                fclose($handle);

                return $wouldBlock === 1 ? null : self::unenforced($sessionId);
            }

            $held = fstat($handle);
            clearstatcache(true, $path);
            $atPath = @stat($path);
            if ($held === false || $atPath === false
                || $held['ino'] !== $atPath['ino'] || $held['dev'] !== $atPath['dev']
            ) {
                flock($handle, LOCK_UN);
                fclose($handle);

                continue;
            }

            // The holder's pid, so the second TUI can say WHICH process has
            // the session. Informational only: the lock is the flock, never
            // this number.
            ftruncate($handle, 0);
            fwrite($handle, getmypid() . "\n");
            fflush($handle);

            return new self($sessionId, $path, $handle, getmypid());
        }

        return self::unenforced($sessionId);
    }

    /**
     * The pid written into $sessionId's lock file by whoever holds it, or null
     * when the file is absent, empty or unreadable. For the read-only notice
     * only — it can be stale or mid-write, and nothing decides on it.
     */
    public static function holderPid(?string $directory, string $sessionId): ?int
    {
        if ($directory === null || $directory === '') {
            return null;
        }

        $raw = @file_get_contents(self::pathFor($directory, $sessionId), false, null, 0, 32);
        if (!is_string($raw) || preg_match('/^\s*(\d+)\s*$/', $raw, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * The lock file for $sessionId. Ids minted by this codebase are hex; any
     * other spelling (a store written by another tool, a hand-made row) is
     * hashed, so an id can never name a path outside $directory.
     */
    public static function pathFor(string $directory, string $sessionId): string
    {
        $name = preg_match('/^[A-Za-z0-9_-]{1,128}$/', $sessionId) === 1
            ? $sessionId
            : 'h-' . sha1($sessionId);

        return rtrim($directory, '/') . '/' . $name . '.lock';
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    /**
     * True when this object really holds an exclusive lock; false for the
     * fail-open answer {@see acquire()} gives when locking is unavailable.
     */
    public function isEnforced(): bool
    {
        return $this->handle !== null;
    }

    /**
     * Give the session up: delete the lock file, then drop the lock.
     *
     * In that order on purpose — unlinking while still holding the lock means
     * no other process can take a lock on the file and then lose it to the
     * unlink. Idempotent, and a no-op in any process other than the one that
     * took the lock (see the class doc-block on forks).
     */
    public function release(): void
    {
        if ($this->handle === null || getmypid() !== $this->ownerPid) {
            return;
        }

        if ($this->path !== null) {
            @unlink($this->path);
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }

    private static function unenforced(string $sessionId): self
    {
        return new self($sessionId, null, null, getmypid());
    }
}
