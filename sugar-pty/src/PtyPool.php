<?php

declare(strict_types=1);

namespace SugarCraft\Pty;

use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\Contract\PtySystem;
use SugarCraft\Pty\Exception\PoolExhaustedException;
use SugarCraft\Pty\Posix\PosixPtySystem;

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
 * `release()` stops occupying a slot once the object is collected,
 * instead of pinning pool capacity forever. Dropping without releasing
 * is still NOT a safe teardown — {@see PosixPtyPair} is a plain value
 * object with no destructor, so an abandoned pair's MASTER FD LEAKS
 * until process end even though its slot frees. `release()` (or
 * `drain()` at shutdown) remains the contract for closing masters.
 * Dead slots are swept at the head of every bookkeeping call.
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
     * pinning capacity once collected (see class docblock for the
     * leaked-fd caveat). Key ids recycle after collection; `sweepDead()`
     * runs before every count/insert so a recycled id can only ever
     * collide with an already-dead entry being overwritten.
     *
     * @var array<int, \WeakReference<PtyPair>>
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
        $this->system = $system ?? new PosixPtySystem();
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
        $this->inFlight[\spl_object_id($pair)] = \WeakReference::create($pair);
        $this->totalAcquired++;
        return $pair;
    }

    /**
     * Forget slots whose pair has been collected without `release()`.
     * The masters of dead pairs are already unreachable — nothing to
     * close here (the fd leak until process end is documented on the
     * class); this only frees the capacity the abandoner was pinning.
     */
    private function sweepDead(): void
    {
        foreach ($this->inFlight as $key => $ref) {
            if ($ref->get() === null) {
                unset($this->inFlight[$key]);
            }
        }
    }

    /**
     * Release a previously-acquired pair, closing its master and
     * freeing the slot. Releasing an unknown or already-released pair
     * is a no-op (idempotent, safe in finally blocks).
     */
    public function release(PtyPair $pair): void
    {
        $key = \spl_object_id($pair);
        $ref = $this->inFlight[$key] ?? null;
        if ($ref === null || $ref->get() !== $pair) {
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
        foreach ($this->inFlight as $ref) {
            $pair = $ref->get();
            if ($pair === null) {
                continue; // already collected; master unreachable
            }
            $master = $pair->master();
            if (!$master->isClosed()) {
                $master->close();
            }
        }
        $this->inFlight = [];
    }
}
