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
 * inode, once, instead of failing. The sweep is a best-effort recovery with a
 * small race (a third process may hold the old inode for the same reason),
 * never a first resort: a live holder is always waited out in full.
 *
 * The file is created on demand with mode 0600 and never deleted on release.
 * flock() is advisory and per open file description: a second lock on the
 * same path from the SAME process contends like another process would.
 */
final class LockFile
{
    private const POLL_MICROSECONDS = 10_000;

    /**
     * @param (\Closure(int): bool)|null $aliveProbe Test seam: is $pid alive?
     */
    private function __construct(
        private readonly string $path,
        private readonly bool $exclusive = true,
        private readonly ?\Closure $aliveProbe = null,
    ) {
    }

    public static function on(string $path): self
    {
        return new self($path);
    }

    /** Derive a LOCK_SH variant (many readers, no writer). Shared holders record no pid. */
    public function shared(): self
    {
        return new self($this->path, false, $this->aliveProbe);
    }

    /**
     * Replace the "is this pid alive" check — for tests, or a platform with a
     * better answer than ext-posix. Must return true when unsure.
     *
     * @param \Closure(int): bool $probe
     */
    public function withAliveProbe(\Closure $probe): self
    {
        return new self($this->path, $this->exclusive, $probe);
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
        $deadline = microtime(true) + max(0.0, $timeoutSeconds);

        while (!@flock($handle, $flags)) {
            if (microtime(true) >= $deadline) {
                $swept = $this->sweepIfStale($handle);
                if ($swept === null) {
                    return null;
                }

                return $this->guard($swept);
            }
            usleep(self::POLL_MICROSECONDS);
        }

        return $this->guard($handle);
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
        $deadline = microtime(true) + max(0.0, $timeoutSeconds);
        $timer = null;
        $deferred = new Deferred(static function () use ($loop, &$timer, $handle, &$deferred): void {
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            fclose($handle);
            $deferred->resolve(null);
        });
        $this->attemptAsync($loop, $handle, $flags, $deadline, $deferred, $timer);

        return $deferred->promise();
    }

    /**
     * One poll of {@see tryAcquireAsync()}: settle the deferred, or re-arm a
     * timer for the next poll. Shares its deadline and sweep rule with
     * {@see tryAcquire()}. $timer is the poll timer currently armed, so the
     * canceller can stop it.
     *
     * @param resource $handle
     */
    private function attemptAsync(
        LoopInterface $loop,
        $handle,
        int $flags,
        float $deadline,
        Deferred $deferred,
        ?TimerInterface &$timer,
    ): void {
        if (@flock($handle, $flags)) {
            $deferred->resolve($this->guard($handle));

            return;
        }

        if (microtime(true) >= $deadline) {
            $swept = $this->sweepIfStale($handle);
            if ($swept === null) {
                $deferred->resolve(null);

                return;
            }
            $deferred->resolve($this->guard($swept));

            return;
        }

        $timer = $loop->addTimer(
            self::POLL_MICROSECONDS / 1_000_000,
            function () use ($loop, $handle, $flags, $deadline, $deferred, &$timer): void {
                $this->attemptAsync($loop, $handle, $flags, $deadline, $deferred, $timer);
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
     * This method owns $contended: it is closed exactly once on every path, so
     * callers must not close it again after a null (that double close is a
     * TypeError).
     *
     * @param resource $contended
     *
     * @return resource|null
     */
    private function sweepIfStale($contended): mixed
    {
        if (!$this->isStale($contended)) {
            fclose($contended);

            return null;
        }

        // WHY the identity check: another waiter whose deadline has also passed
        // may have swept this same stale inode already and locked its fresh one.
        // Unlinking by path then would delete that live holder's file and let
        // both processes hold the lock. Only the inode we inspected is ours to drop.
        if (!$this->pathNamesInode($contended)) {
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
     * Whether the holder recorded in $contended is provably dead. Read-only:
     * it neither unlinks nor closes anything.
     *
     * @param resource $contended
     */
    private function isStale($contended): bool
    {
        if (!$this->exclusive) {
            return false;
        }

        rewind($contended);
        $recorded = trim((string) stream_get_contents($contended, 32));
        if ($recorded === '' || !ctype_digit($recorded)) {
            return false;
        }
        $pid = (int) $recorded;

        return !($pid <= 1 || $pid === getmypid() || $this->alive($pid));
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
