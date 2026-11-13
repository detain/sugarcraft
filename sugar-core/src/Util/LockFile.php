<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

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
                    fclose($handle);

                    return null;
                }

                return $this->guard($swept);
            }
            usleep(self::POLL_MICROSECONDS);
        }

        return $this->guard($handle);
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
            throw new \RuntimeException(\sprintf(
                'Timed out after %.1fs waiting for the %s lock on %s — another process holds it.',
                $timeoutSeconds,
                $this->exclusive ? 'exclusive' : 'shared',
                $this->path,
            ));
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
     * On a timed-out exclusive attempt: read the recorded pid; if provably
     * dead, drop the file and lock the fresh inode. Returns the locked handle,
     * or null when nothing was swept or the retry lost the race. Closes the
     * contended handle when it sweeps.
     *
     * @param resource $contended
     *
     * @return resource|null
     */
    private function sweepIfStale($contended): mixed
    {
        if (!$this->exclusive) {
            return null;
        }

        rewind($contended);
        $recorded = trim((string) stream_get_contents($contended, 32));
        if ($recorded === '' || !ctype_digit($recorded)) {
            return null;
        }
        $pid = (int) $recorded;
        if ($pid <= 1 || $pid === getmypid() || $this->alive($pid)) {
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
