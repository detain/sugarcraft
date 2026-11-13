<?php

declare(strict_types=1);

namespace SugarCraft\Code\Support;

use SugarCraft\Core\Util\LockFile;
use SugarCraft\Core\Util\LockGuard;

/**
 * The package's ONE bounded-lock implementation (E679).
 *
 * TaskList and WorktreeManager each grew a private `flockTimed()` over the
 * same E137 doctrine — poll `LOCK_NB` against a deadline, then THROW with a
 * message naming the wait, the flavour and the contended path — and the two
 * bodies had already drifted on one detail (one suppressed flock()'s
 * warnings, the other did not). A duplicated lock loop is how one site ends
 * up waiting forever on a Tuesday; the loop lives here now, byte-comparable.
 *
 * WHY THROW AND NOT FALSE: failing OPEN on a lock timeout turns a lost
 * update into a silent one — WorktreeManager's read side would answer an
 * empty registry that the next save then persists OVER the live contender's
 * entries. Every caller in this package makes the same judgement, so the
 * helper has one outcome shape: acquired, or RuntimeException.
 */
final class TimedFileLock
{
    /**
     * Default wait, matching the SQLite busyTimeout in ms (§ E137); the two
     * families that had a copy of 5.0 now share this one.
     */
    public const DEFAULT_WAIT_SECONDS = 5.0;

    /**
     * How often to ask for the non-blocking lock: short against a
     * human-visible wait, cheap against a five-second budget.
     */
    private const POLL_MICROSECONDS = 10_000;

    /**
     * Acquire $flags (LOCK_SH or LOCK_EX) within $waitSeconds or throw.
     *
     * @param mixed $fp a live flock()-able stream handle
     *
     * @throws \RuntimeException when the deadline passes with the lock held
     *         by someone else — the message names the wait, the flavour and
     *         the contended path, the three things an operator needs at 2am.
     */
    public static function acquire($fp, int $flags, string $what, float $waitSeconds = self::DEFAULT_WAIT_SECONDS): void
    {
        $deadline = \microtime(true) + $waitSeconds;

        while (!@\flock($fp, $flags | \LOCK_NB)) {
            if (\microtime(true) >= $deadline) {
                throw new \RuntimeException(sprintf(
                    'Timed out after %.1fs waiting for the %s lock on %s — another process holds it.',
                    $waitSeconds,
                    ($flags & \LOCK_EX) === \LOCK_EX ? 'exclusive' : 'shared',
                    $what,
                ));
            }

            \usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * The path-level door: take an exclusive lock on the sidecar $lockPath
     * through sugar-core's LockFile (rider R6) — the poll loop, the 0600
     * create, the pid note and the stale-holder sweep stated once, in the
     * library. For callers whose lock file is a SIDECAR (holds no payload).
     * Callers that lock a DATA file's own handle, or probe a lock they must
     * not write into (StateDir, gcLockIsHeld, SiblingSpendLedger), keep the
     * handle-based {@see acquire()}: LockFile records its pid INSIDE the file.
     *
     * @throws \RuntimeException when the sidecar cannot be opened, or is still
     *         held by a live process after the wait (same wording as
     *         {@see acquire()})
     */
    public static function guard(string $lockPath, float $waitSeconds = self::DEFAULT_WAIT_SECONDS): LockGuard
    {
        $guard = LockFile::on($lockPath)->tryAcquire($waitSeconds);
        if ($guard !== null) {
            return $guard;
        }
        $openable = is_file($lockPath) ? is_writable($lockPath) : is_writable(\dirname($lockPath));
        if (!$openable) {
            throw new \RuntimeException("{$lockPath} could not be opened for locking");
        }

        throw new \RuntimeException(sprintf(
            'Timed out after %.1fs waiting for the exclusive lock on %s — another process holds it.',
            $waitSeconds,
            $lockPath,
        ));
    }

    /** Release an acquired lock — the one-liner, named so releases read alike everywhere. */
    public static function release($fp): void
    {
        \flock($fp, \LOCK_UN);
    }
}
