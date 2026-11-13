<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Contract\MasterPty;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Exception\PoolExhaustedException;
use SugarCraft\Pty\Posix\PosixMasterPty;

/**
 * Bounded-concurrency wrapper around {@see PtySystem} for high-churn
 * scenarios (SSH servers, supervisors, test harnesses) that want a hard
 * ceiling on simultaneously-open PTY pairs.
 *
 * The FFI libc handle is already cached as a per-process static in
 * {@see Libc::lib()}, so there is no per-open cdef cost to amortize.
 * What `PtyPool` adds is back-pressure: callers cannot exceed
 * {@see $maxSize} concurrent pairs, surfacing pressure as a typed
 * {@see PoolExhaustedException} instead of an unbounded /dev/ptmx
 * exhaustion at the kernel level.
 *
 * `acquire()` opens a fresh pair through the underlying {@see PtySystem}
 * (PTY pairs are 1:1 with their slave path — they cannot be reused once
 * a child has been spawned against the slave). `release()` returns the
 * slot and closes the master, so the next `acquire()` is free to open.
 *
 * A profile of `PosixPtySystem::open()` measured 0.05 ms per call with
 * the cached libc handle — cheap enough that handle reuse is not worth
 * the complexity. This class is therefore a back-pressure ceiling and
 * session bookkeeper, not a syscall cache. The profile figure holds only
 * for that cached-handle path (`Libc::lib()` warm); a cold first open in
 * a process pays the cdef setup on top.
 *
 * Bookkeeping holds WEAK references to the pairs (m3, round
 * lib-fixes-1103): a pair the caller drops on the floor without
 * `release()` stops occupying a slot once the object is collected.
 * PTY-38 closed the fd half of that story for fully abandoned
 * pool-owned POSIX pairs: when BOTH the pair and its master have been
 * collected, the sweep closes the captured fd best-effort (a readlink
 * guard refuses to reap a recycled number — see sweepDead()). The
 * ownership law: only pairs the pool created are ever eligible, and a
 * caller who still holds the master (having dropped only the pair)
 * inherited the close obligation — the sweep leaves that fd alone.
 * Non-POSIX backends keep handles that are not bare ints, so there the
 * `release()` (or `drain()`) contract stands unchanged. The pool also
 * sweeps orphaned slots on destruct. Dead slots are swept at the head
 * of every bookkeeping call.
 *
 * A null `$system` resolves through {@see PtySystemFactory::new()}, so
 * `SUGARCRAFT_PTY_BACKEND` and the platform default (ConPTY on Windows)
 * are honoured exactly as everywhere else (m13, round docs-reviews-1104
 * — before this the constructor hard-wired `PosixPtySystem`, dead on
 * arrival on Windows and deaf to the env). An explicit `$system` always
 * wins and never consults the factory.
 */
final class PtyPool
{
    public const DEFAULT_MAX_SIZE = 50;

    public readonly int $maxSize;
    private readonly PtySystem $system;

    /**
     * Map of in-flight pairs keyed by {@see spl_object_id()} so the
     * pool can recognise the exact instance handed out by `acquire()`
     * without relying on the (non-public) underlying master fd.
     *
     * Values are WEAK: an unreleased pair the caller abandons stops
     * pinning capacity once collected, and a POSIX pair whose pair AND
     * master are both collected has its captured fd closed by the
     * sweep (see class docblock for the ownership law). Key ids
     * recycle after collection; `sweepDead()` runs before every
     * count/insert so a recycled id can only ever collide with an
     * already-dead entry being overwritten.
     *
     * @var array<int, array{pair: \WeakReference<PtyPair>, master: ?\WeakReference<MasterPty>, fd: ?int}>
     */
    private array $inFlight = [];

    private int $totalAcquired = 0;

    public function __construct(
        ?PtySystem $system = null,
        int $maxSize = self::DEFAULT_MAX_SIZE,
    ) {
        if ($maxSize < 1) {
            throw new \InvalidArgumentException(
                "PtyPool maxSize must be >= 1, got {$maxSize}",
            );
        }
        $this->system = $system ?? PtySystemFactory::new();
        $this->maxSize = $maxSize;
    }

    /**
     * Open a fresh PTY pair and reserve a slot in the pool.
     *
     * @throws PoolExhaustedException when {@see $maxSize} sessions are
     *                                already in flight.
     * @throws PtyException           when the underlying system rejects
     *                                the open.
     */
    public function acquire(int $cols = 80, int $rows = 24): PtyPair
    {
        $this->sweepDead();
        if (\count($this->inFlight) >= $this->maxSize) {
            throw PoolExhaustedException::atCapacity($this->maxSize);
        }
        $pair = $this->system->open($cols, $rows);
        $master = $pair->master();
        $record = ['pair' => \WeakReference::create($pair), 'master' => null, 'fd' => null];
        // Ownership law (PTY-38): the pool created this pair, so a
        // POSIX master left fully unreachable may be closed by the
        // sweep. The fd is captured NOW — a dead object carries no
        // handle. Other backends keep non-int handles and stay on the
        // explicit release()/drain() contract.
        if ($master instanceof PosixMasterPty) {
            $record['master'] = \WeakReference::create($master);
            $record['fd'] = $master->fd();
        }
        $this->inFlight[\spl_object_id($pair)] = $record;
        $this->totalAcquired++;
        return $pair;
    }

    /**
     * Forget slots whose pair has been collected without `release()`,
     * closing the master fd when the pair is FULLY abandoned (PTY-38):
     * pair and master both unreachable means no code left in the
     * process can close it, and the pool — the creator — is the only
     * one who knows the number. A surviving master object means the
     * caller kept the handle and with it the close obligation: leave
     * the fd alone. Best-effort by construction: the readlink guard
     * refuses numbers that no longer point at /dev/ptmx (a master
     * closed without release recycles its slot silently), and the whole
     * leg never throws.
     */
    private function sweepDead(): void
    {
        foreach ($this->inFlight as $key => $record) {
            if ($record['pair']->get() !== null) {
                continue;
            }
            if ($record['master'] !== null && $record['master']->get() === null) {
                self::closeOrphanedMaster($record['fd']);
            }
            unset($this->inFlight[$key]);
        }
    }

    /**
     * Close an orphaned POSIX master fd, guarded against reaping a
     * recycled number. Residual known limit, disclosed: a number that
     * was closed normally and then RE-ALLOCATED to a fresh /dev/ptmx
     * inside the same unswept window looks identical to the orphan;
     * every in-lane close path (release/drain) clears the slot first,
     * so hitting that window requires a direct master close plus a
     * reuse before any further pool bookkeeping call.
     */
    private static function closeOrphanedMaster(?int $fd): void
    {
        if ($fd === null) {
            return;
        }
        try {
            if (@\readlink('/proc/self/fd/' . $fd) !== '/dev/ptmx') {
                return; // already closed or reused — not ours anymore
            }
            PosixMasterPty::closeRawFd($fd);
        } catch (\Throwable) {
            // No /proc (non-Linux host) or a refused syscall: the fd
            // survives to process end, the pre-PTY-38 documented floor.
        }
    }

    /**
     * Sweeps any fully-abandoned slots when the pool itself goes away.
     * Pairs still referenced by callers are left intact — their owners
     * hold the close obligation.
     */
    public function __destruct()
    {
        $this->sweepDead();
    }

    /**
     * Release a previously-acquired pair, closing its master and
     * freeing the slot. Releasing an unknown or already-released pair
     * is a no-op (idempotent, safe in finally blocks).
     */
    public function release(PtyPair $pair): void
    {
        $key = \spl_object_id($pair);
        $record = $this->inFlight[$key] ?? null;
        if ($record === null || $record['pair']->get() !== $pair) {
            // Unknown pair, or an id collision against a dead slot —
            // the stranger is not ours to close.
            return;
        }
        unset($this->inFlight[$key]);

        $master = $pair->master();
        if (!$master->isClosed()) {
            $master->close();
        }
    }

    /** Number of pairs currently outstanding. */
    public function inFlight(): int
    {
        $this->sweepDead();
        return \count($this->inFlight);
    }

    /** Lifetime count of successful acquires — never decreases. */
    public function totalAcquired(): int
    {
        return $this->totalAcquired;
    }

    /** Capacity headroom left before the next `acquire()` rejects. */
    public function available(): int
    {
        return $this->maxSize - $this->inFlight();
    }

    /**
     * Close every outstanding pair. Intended for shutdown / test
     * teardown — does not affect future `acquire()` calls.
     */
    public function drain(): void
    {
        foreach ($this->inFlight as $record) {
            $pair = $record['pair']->get();
            if ($pair === null) {
                continue; // collected; the sweep or its orphan leg owns it
            }
            $master = $pair->master();
            if (!$master->isClosed()) {
                $master->close();
            }
        }
        $this->inFlight = [];
    }
}
