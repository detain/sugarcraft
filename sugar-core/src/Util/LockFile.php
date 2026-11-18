<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Lang;

/**
 * Cross-process advisory lock on a file, with a bounded wait — the one place
 * the "poll LOCK_NB against a deadline" loop and the stale-holder law live.
 * Generalises candy-code's `Support/TimedFileLock` (the loop) and adds the
 * pid-liveness sweep.
 *
 * ```php
 * $guard = LockFile::on($path)->tryAcquire(5.0);
 * if ($guard === null) { ... contended ... }
 * try { ... } finally { $guard->release(); }
 * ```
 *
 * WHY null AND NOT A THROW: the caller decides whether a contended lock fails
 * open or closed ({@see acquire()} is the throwing spelling for callers that
 * always fail closed).
 *
 * THE STALE LAW. The kernel drops an flock when its holder dies, so a plain
 * crash never strands this lock. What does strand one is a holder whose
 * descriptor was INHERITED by a child that outlived it (a forked or spawned
 * worker): the flock then belongs to a process that never meant to own it.
 * The exclusive holder therefore records its pid in the file; a contender that
 * times out reads it, and when that pid is provably dead (ext-posix says
 * ESRCH — EPERM means alive, an unreadable or absent ext means "cannot tell"
 * and nothing is swept) it unlinks the file and takes the lock on the fresh
 * inode, once, instead of failing.
 *
 * A successful flock() is only a lock on the path while the path still names
 * that inode. A waiter that polls an inode a sweep has since unlinked gives the
 * lock back and polls the path's current inode, so the superseded inode never
 * yields a second holder.
 *
 * The sweep is a best-effort recovery, never a first resort, and it has three
 * guards. A live holder is always waited out in full. A shared (LOCK_SH)
 * reader is never swept over: shared holders record no pid, so a dead pid in
 * the file says nothing about them, and the sweep proceeds only when an
 * exclusive lock is what blocks the waiter. The recorded pid must also read
 * the same after one poll interval, which covers the gap between a new
 * exclusive holder's flock() and its pid note. Sweeps are serialised on a
 * `<path>.sweep` sidecar lock, so two waiters can never unlink each other's
 * fresh inode; a waiter that cannot take the sidecar gives up as contended. The
 * residual race is a pid note delayed past that poll (a suspended holder), or a
 * third process still holding the old inode for the same reason.
 *
 * The file and its sidecar are created on demand with mode 0600 and never
 * deleted on release.
 * flock() is advisory and per open file description: a second lock on the
 * same path from the SAME process contends like another process would.
 */
final class LockFile
{
    private const POLL_MICROSECONDS = 10_000;

    /**
     * @param (\Closure(int): bool)|null $aliveProbe Test seam: is $pid alive?
     * @param (\Closure(): float)|null   $clock      Test seam: the clock in seconds.
     */
    private function __construct(
        private readonly string $path,
        private readonly bool $exclusive = true,
        private readonly ?\Closure $aliveProbe = null,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public static function on(string $path): self
    {
        return new self($path);
    }

    /** Derive a LOCK_SH variant (many readers, no writer). Shared holders record no pid. */
    public function shared(): self
    {
        return new self($this->path, false, $this->aliveProbe, $this->clock);
    }

    /**
     * Replace the "is this pid alive" check — for tests, or a platform with a
     * better answer than ext-posix. Must return true when unsure.
     *
     * @param \Closure(int): bool $probe
     */
    public function withAliveProbe(\Closure $probe): self
    {
        return new self($this->path, $this->exclusive, $probe, $this->clock);
    }

    /**
     * Replace the clock the wait deadline is read from — a test seam, as in
     * {@see \SugarCraft\Core\Util\LruMap::withTtl()}. Must be monotonic.
     *
     * @param \Closure(): float $clock
     */
    public function withClock(\Closure $clock): self
    {
        return new self($this->path, $this->exclusive, $this->aliveProbe, $clock);
    }

    /**
     * Monotonic seconds for the wait deadline. WHY: a wall clock stepped by NTP
     * would stretch or cut the wait well past or short of the caller's budget.
     */
    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : hrtime(true) / 1e9;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Take the lock, polling for up to $timeoutSeconds (0 = one attempt).
     * Null when still contended at the deadline, or when the lock file cannot
     * be opened at all (missing/unwritable directory).
     */
    public function tryAcquire(float $timeoutSeconds = 0.0): ?LockGuard
    {
        $handle = $this->open();
        if ($handle === null) {
            return null;
        }

        $flags = ($this->exclusive ? \LOCK_EX : \LOCK_SH) | \LOCK_NB;
        $deadline = $this->now() + max(0.0, $timeoutSeconds);
        $suspect = null;

        while (true) {
            if (@flock($handle, $flags)) {
                if ($this->pathNamesInode($handle)) {
                    return $this->guard($handle);
                }
                // WHY: a sweep unlinked this inode while we polled it. Its lock is
                // no lock on the path, so give it back and poll the path's inode.
                fclose($handle);
                $handle = $this->open();
                if ($handle === null) {
                    return null;
                }
                $suspect = null;
                continue;
            }

            if ($this->now() >= $deadline) {
                if ($suspect === null) {
                    $suspect = $this->deadRecord($handle);
                    if ($suspect !== null) {
                        // Confirm the dead record one poll later before any unlink.
                        usleep(self::POLL_MICROSECONDS);
                        continue;
                    }
                }
                $swept = $this->sweepIfStale($handle, $suspect);
                if ($swept === null) {
                    return null;
                }

                return $this->guard($swept);
            }
            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * The event-loop spelling of {@see tryAcquire()}: the same attempt, the same
     * deadline and the same stale sweep, but the wait is a loop timer instead of
     * a blocking sleep, so a ReactPHP program keeps its ticks running while it
     * waits for another process.
     *
     * The promise resolves to the guard, or to null when still contended at the
     * deadline or when the lock file cannot be opened. The first attempt runs
     * synchronously, so an uncontended lock resolves before this returns.
     *
     * Cancelling the promise (react/promise-timer's timeout() does this when the
     * wait runs over) stops the poll timer and closes the descriptor, then
     * resolves null. WHY: a caller that abandons the wait must not leave a timer
     * re-arming until the original deadline, which would keep the loop alive
     * and could take a lock nobody is waiting for any more.
     *
     * @return PromiseInterface<LockGuard|null>
     */
    public function tryAcquireAsync(LoopInterface $loop, float $timeoutSeconds = 0.0): PromiseInterface
    {
        $handle = $this->open();
        if ($handle === null) {
            $unopened = new Deferred();
            $unopened->resolve(null);

            return $unopened->promise();
        }

        $flags = ($this->exclusive ? \LOCK_EX : \LOCK_SH) | \LOCK_NB;
        $deadline = $this->now() + max(0.0, $timeoutSeconds);
        $timer = null;
        $deferred = new Deferred(static function () use ($loop, &$timer, &$handle, &$deferred): void {
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            if (\is_resource($handle)) {
                fclose($handle);
            }
            $deferred->resolve(null);
        });
        $this->attemptAsync($loop, $handle, $flags, $deadline, $deferred, $timer, null);

        return $deferred->promise();
    }

    /**
     * One poll of {@see tryAcquireAsync()}: settle the deferred, or re-arm a
     * timer for the next poll. Shares its deadline, confirmation and sweep rules
     * with {@see tryAcquire()}. $timer is the poll timer currently armed, so the
     * canceller can stop it. $suspect is the dead record seen at the deadline on
     * an earlier poll, awaiting confirmation. $handle is by reference because a
     * lock won on a superseded inode is swapped for a reopened handle, and the
     * canceller and the next poll must both see the swap.
     *
     * @param resource|null $handle
     */
    private function attemptAsync(
        LoopInterface $loop,
        &$handle,
        int $flags,
        float $deadline,
        Deferred $deferred,
        ?TimerInterface &$timer,
        ?string $suspect,
    ): void {
        if (@flock($handle, $flags)) {
            if ($this->pathNamesInode($handle)) {
                $deferred->resolve($this->guard($handle));

                return;
            }
            // WHY: same as tryAcquire(): the lock sits on an inode a sweep has
            // superseded, so release it and poll the inode the path names now.
            // The retry is immediate, before the deadline check, so a free inode
            // is won even when the deadline has already passed.
            fclose($handle);
            $handle = $this->open();
            if ($handle === null) {
                $deferred->resolve(null);

                return;
            }
            $this->attemptAsync($loop, $handle, $flags, $deadline, $deferred, $timer, null);

            return;
        }

        $confirming = false;
        if ($this->now() >= $deadline) {
            if ($suspect === null) {
                $suspect = $this->deadRecord($handle);
                $confirming = $suspect !== null;
            }
            if (!$confirming) {
                $swept = $this->sweepIfStale($handle, $suspect);
                $deferred->resolve($swept === null ? null : $this->guard($swept));

                return;
            }
        }

        $timer = $loop->addTimer(
            self::POLL_MICROSECONDS / 1_000_000,
            function () use ($loop, &$handle, $flags, $deadline, $deferred, &$timer, $suspect): void {
                $this->attemptAsync($loop, $handle, $flags, $deadline, $deferred, $timer, $suspect);
            },
        );
    }

    /**
     * {@see tryAcquire()} that throws instead of returning null — the
     * "acquired, or RuntimeException" shape of TimedFileLock.
     *
     * @throws \RuntimeException Naming the wait, the flavour and the path.
     */
    public function acquire(float $timeoutSeconds = 5.0): LockGuard
    {
        $guard = $this->tryAcquire($timeoutSeconds);
        if ($guard === null) {
            throw new \RuntimeException(Lang::t('lock_file.acquire_timeout', [
                'seconds' => \sprintf('%.1f', $timeoutSeconds),
                'kind'    => $this->exclusive ? 'exclusive' : 'shared',
                'path'    => $this->path,
            ]));
        }

        return $guard;
    }

    /** @return resource|null */
    private function open(): mixed
    {
        $handle = @fopen($this->path, 'cb+');
        if ($handle === false) {
            return null;
        }
        @chmod($this->path, 0600);

        return $handle;
    }

    /**
     * @param resource $handle holding the flock
     */
    private function guard($handle): LockGuard
    {
        if ($this->exclusive) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, (string) getmypid());
            fflush($handle);
        }

        return new LockGuard($handle, $this->path, $this->exclusive);
    }

    /**
     * On a timed-out exclusive attempt: if the recorded pid is provably dead,
     * drop the file and lock the fresh inode. Returns the locked handle, or
     * null when nothing was swept or the retry lost the race.
     *
     * $suspect is the dead record {@see deadRecord()} returned at an earlier
     * deadline. The sweep proceeds only when the record still reads the same
     * text, so a holder that wrote its own pid during the confirmation poll
     * is left alone.
     *
     * This method owns $contended: it is closed exactly once on every path, so
     * callers must not close it again after a null (that double close is a
     * TypeError).
     *
     * @param resource $contended
     *
     * @return resource|null
     */
    private function sweepIfStale($contended, ?string $suspect): mixed
    {
        if ($suspect === null || $this->recordText($contended) !== $suspect) {
            fclose($contended);

            return null;
        }

        // WHY the sidecar: the identity check and the unlink are two path
        // operations, and a second waiter can run its whole sweep between them.
        // Its fresh inode would then be the one unlinked here, and both waiters
        // would hold exclusion. Every sweep therefore runs under this lock, and
        // the identity check runs again inside it. Non-blocking, so a loser gives
        // up exactly as a contended lock does instead of waiting on the sweeper.
        $sidecar = @fopen($this->path . '.sweep', 'cb+');
        if ($sidecar === false) {
            fclose($contended);

            return null;
        }
        @chmod($this->path . '.sweep', 0600);
        if (!@flock($sidecar, \LOCK_EX | \LOCK_NB)) {
            fclose($sidecar);
            fclose($contended);

            return null;
        }

        try {
            return $this->sweepUnderSidecar($contended);
        } finally {
            fclose($sidecar);
        }
    }

    /**
     * The body of {@see sweepIfStale()}, run while the sidecar lock is held.
     *
     * @param resource $contended
     *
     * @return resource|null
     */
    private function sweepUnderSidecar($contended): mixed
    {
        // WHY the identity check: another waiter whose deadline has also passed
        // may have swept this same stale inode already and locked its fresh one.
        // Unlinking by path then would delete that live holder's file and let
        // both processes hold the lock. Only the inode we inspected is ours to drop.
        if (!$this->pathNamesInode($contended)) {
            fclose($contended);

            return null;
        }

        // WHY the shared probe: the dead pid describes the last EXCLUSIVE holder.
        // When the blocker is a shared reader, unlinking would split the inode
        // out from under that reader and let this waiter take exclusion beside it.
        if ($this->blockedOnlyBySharedReaders($contended)) {
            fclose($contended);

            return null;
        }

        @unlink($this->path);
        fclose($contended);

        $fresh = $this->open();
        if ($fresh === null) {
            return null;
        }
        if (!@flock($fresh, \LOCK_EX | \LOCK_NB)) {
            fclose($fresh);

            return null;
        }

        return $fresh;
    }

    /**
     * Whether the path still names the same inode as the handle $contended.
     * False when the path is gone or was replaced, so the caller must not unlink it.
     *
     * @param resource $contended
     */
    private function pathNamesInode($contended): bool
    {
        $held = fstat($contended);
        $named = @stat($this->path);
        if ($held === false || $named === false) {
            return false;
        }

        return $held['dev'] === $named['dev'] && $held['ino'] === $named['ino'];
    }

    /**
     * Whether the inode can take a LOCK_SH right now, which holds exactly when
     * no exclusive lock is held on it. True therefore means the waiter's
     * contention came from shared readers (or from nothing at all).
     *
     * @param resource $contended
     */
    private function blockedOnlyBySharedReaders($contended): bool
    {
        if (!@flock($contended, \LOCK_SH | \LOCK_NB)) {
            return false;
        }
        @flock($contended, \LOCK_UN);

        return true;
    }

    /**
     * The recorded pid text when the exclusive holder recorded there is provably
     * dead, else null. Read-only: it neither unlinks nor closes anything, and it
     * probes liveness at most once per call.
     *
     * @param resource $contended
     */
    private function deadRecord($contended): ?string
    {
        if (!$this->exclusive) {
            return null;
        }

        $recorded = $this->recordText($contended);
        if ($recorded === '' || !ctype_digit($recorded)) {
            return null;
        }
        $pid = (int) $recorded;

        if ($pid <= 1 || $pid === getmypid() || $this->alive($pid)) {
            return null;
        }

        return $recorded;
    }

    /**
     * The pid note currently in the file, trimmed. Empty while a new holder has
     * truncated the file and not yet written its pid.
     *
     * @param resource $contended
     */
    private function recordText($contended): string
    {
        rewind($contended);

        return trim((string) stream_get_contents($contended, 32));
    }

    private function alive(int $pid): bool
    {
        if ($this->aliveProbe !== null) {
            return ($this->aliveProbe)($pid);
        }
        if (!\function_exists('posix_kill') || !\function_exists('posix_get_last_error')) {
            return true;
        }
        if (@posix_kill($pid, 0)) {
            return true;
        }

        // EPERM (1): the process exists under another uid. Only ESRCH (3) is dead.
        return posix_get_last_error() !== 3;
    }
}
