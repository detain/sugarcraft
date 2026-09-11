<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

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

    /** Release an acquired lock — the one-liner, named so releases read alike everywhere. */
    public static function release($fp): void
    {
        \flock($fp, \LOCK_UN);
    }
}
