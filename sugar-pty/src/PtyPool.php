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
 * Wired in plan step P6.2; profile of `PosixPtySystem::open()` measured
 * 0.05 ms per call with the libc handle cached, well under the plan's
 * 5 ms threshold for a handle-reuse pool — this class therefore focuses
 * on session bookkeeping rather than syscall caching.
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
     * @var array<int, PtyPair>
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
        if (\count($this->inFlight) >= $this->maxSize) {
            throw PoolExhaustedException::atCapacity($this->maxSize);
        }
        $pair = $this->system->open($cols, $rows);
        $this->inFlight[\spl_object_id($pair)] = $pair;
        $this->totalAcquired++;
        return $pair;
    }

    /**
     * Release a previously-acquired pair, closing its master and
     * freeing the slot. Releasing an unknown or already-released pair
     * is a no-op (idempotent, safe in finally blocks).
     */
    public function release(PtyPair $pair): void
    {
        $key = \spl_object_id($pair);
        if (!isset($this->inFlight[$key])) {
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
        return $this->maxSize - \count($this->inFlight);
    }

    /**
     * Close every outstanding pair. Intended for shutdown / test
     * teardown — does not affect future `acquire()` calls.
     */
    public function drain(): void
    {
        foreach ($this->inFlight as $pair) {
            $master = $pair->master();
            if (!$master->isClosed()) {
                $master->close();
            }
        }
        $this->inFlight = [];
    }
}
