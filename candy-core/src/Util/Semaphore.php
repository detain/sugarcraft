<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Exception\SemaphoreClosedException;
use SugarCraft\Core\Lang;

use function React\Promise\resolve;

/**
 * A bounded-concurrency permit pool for promise-returning work.
 *
 * Promoted from phlix-console-client `src/Media/Semaphore.php`, where one pool
 * capped how many poster fetches could be in flight against a home media
 * server. Only the cap travelled: the app's `PHLIX_POSTER_CONCURRENCY` env
 * lookup is gone, because a foundation utility must take its limit as an
 * argument and read nothing from the process environment.
 *
 * At most `limit()` callbacks run at once; everything else parks in a FIFO
 * queue and is dispatched as a slot frees. FIFO is deliberate — a fan-out that
 * could jump the queue would starve whoever asked first, and with a pool shared
 * across components "who asked first" is the only fairness contract available.
 *
 * ## Which methods mutate
 *
 * The pool is stateful and its identity IS the lock: two pools each cap at N,
 * so handing out a copy would hand out a second budget. Therefore
 * {@see acquire()}, {@see release()}, {@see run()} and {@see close()} mutate the
 * receiver, and cloning is forbidden. {@see withLimit()} is the one immutable
 * fluent setter — it derives a *fresh, empty* pool at a new cap, and refuses to
 * do so while permits are outstanding or once the pool is closed, rather than
 * silently orphaning live waiters or resurrecting a retired lock. Retuning a
 * pool that is already under traffic is deliberately not offered: changing the
 * cap of a live lock mid-flight is a design decision for whoever owns the
 * traffic, not for this class.
 *
 * ## Queue depth and lifecycle
 *
 * The FIFO queue is unbounded — only the in-flight count is capped. A producer
 * that can outrun the pool must apply back-pressure upstream; `waiting()` makes
 * the pile-up observable. When a pool is retired, call {@see close()}: it fails
 * every parked waiter with {@see SemaphoreClosedException} at a known point, so
 * a caller can tell "the pool went away" from "my task failed" by `instanceof`.
 * A bare `acquire()` promise drops with the pool, but the handlers `run()`
 * installs reference the pool and form a reference cycle, so there the destructor
 * fires only when the cycle collector gets to it (measured: those waiters stay
 * pending across `unset()` and are failed by `gc_collect_cycles()`) — the
 * destructor is a safety net, not the guarantee. Use {@see close()}.
 *
 * One react/promise v3 detail is worth knowing at the call site: a waiter parked
 * through a bare `acquire()` with no rejection handler attached still gets
 * rejected by `close()`, and react/promise reports an unhandled rejection with an
 * `\error_log()` line when the rejected promise is collected. Attach a handler
 * (or go through `run()`, which always has one) rather than dropping the promise.
 *
 * ## Not a twin of the existing bounded-concurrency types
 *
 * {@see \SugarCraft\Core\WorkerPool} bounds *subprocess* concurrency for
 * CPU-bound work (`proc_open` children fed over pipes); this bounds in-process
 * promise concurrency. The one-shot batch mapper in the candy-serve layer maps a
 * single batch with a cap and cannot be shared between call sites. This class is
 * the reusable primitive: give one pool to every component that touches the same
 * scarce resource and the cap actually holds.
 */
final class Semaphore
{
    /**
     * Permits parked in arrival order, head first.
     *
     * @var list<Deferred<self>>
     */
    private array $waiters = [];

    /** Permits currently handed out. */
    private int $held = 0;

    /** True while the grant loop is running, so a nested release cannot recurse into it. */
    private bool $dispatching = false;

    /** Set by {@see close()}: no further permit may be promised. */
    private bool $closed = false;

    private function __construct(
        private readonly int $limit,
    ) {
    }

    /**
     * A copied pool would be a second budget on the same scarce resource, and
     * both copies would hold the very same waiter objects — so the grant that
     * one of them thinks it owns can be handed out again by the other.
     */
    private function __clone(): void
    {
    }

    /**
     * Create a pool that admits at most $limit concurrent permits.
     *
     * The boundary check is deliberately not delegated to
     * {@see Validation::positive()}: "must be positive" is true but less useful
     * at this call site than naming the actual floor, since a limit of 1 is a
     * meaningful serialising pool and 0 is the one value that can never work.
     *
     * @throws \InvalidArgumentException when $limit is below 1 — a pool that
     *                                   admits nothing can never dispatch, and
     *                                   one with a negative cap is nonsense.
     */
    public static function new(int $limit): self
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(Lang::t('semaphore.limit_invalid', [
                'limit' => (string) $limit,
            ]));
        }

        return new self($limit);
    }

    /**
     * Derive a fresh, empty pool with a different cap, leaving this one alone.
     *
     * Only for construction-time wiring: a live, idle pool can be re-capped; a
     * pool with permits outstanding cannot be retuned through the back door, and
     * a retired one cannot be revived through this side door either. To restart
     * after {@see close()}, construct with {@see Semaphore::new()} — saying so
     * beats silently handing back a pool that admits work its owner closed.
     *
     * @throws \InvalidArgumentException when $limit is below 1 (checked first,
     *                                   so bad input reads as bad input whether
     *                                   or not the pool happens to be busy).
     * @throws \LogicException when permits are outstanding — a derived pool
     *                         inherits none of that state, so the holders would
     *                         be releasing into a lock nobody watches — or when
     *                         the pool is closed.
     */
    public function withLimit(int $limit): self
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException(Lang::t('semaphore.limit_invalid', [
                'limit' => (string) $limit,
            ]));
        }

        if ($this->closed) {
            throw new \LogicException(Lang::t('semaphore.derive_closed'));
        }

        if ($this->held > 0 || $this->waiters !== []) {
            throw new \LogicException(Lang::t('semaphore.limit_busy', [
                'held' => (string) $this->held,
                'waiting' => (string) count($this->waiters),
            ]));
        }

        return new self($limit);
    }

    /** The concurrency ceiling this pool was built with. */
    public function limit(): int
    {
        return $this->limit;
    }

    /** Callers queued behind the ceiling. */
    public function waiting(): int
    {
        return count($this->waiters);
    }

    /** Permits handed out right now. */
    public function running(): int
    {
        return $this->held;
    }

    /**
     * Slots still free — `limit()` minus {@see running()}.
     *
     * A retired pool reports its unfilled slots as available until in-flight
     * work drains; nothing may be granted through them any more, so pair this
     * with {@see closed()} before treating a nonzero answer as a live budget.
     */
    public function available(): int
    {
        return $this->limit - $this->held;
    }

    /** Whether {@see close()} has retired this pool. */
    public function closed(): bool
    {
        return $this->closed;
    }

    /**
     * Ask for a permit; the promise resolves with this pool once one is free.
     *
     * Resolves synchronously when a slot is already open, which is what lets
     * callers treat the pool as an ordinary promise source under the loop.
     *
     * @return PromiseInterface<self> Resolves with this pool when a slot frees;
     *                               if the pool is retired while the caller is
     *                               parked, the promise rejects with
     *                               {@see SemaphoreClosedException}.
     * @throws \LogicException when the pool is already closed — parking a waiter
     *                         behind a retired pool guarantees it is never
     *                         granted, so say so to the caller who still holds the
     *                         reference instead of handing them a promise that
     *                         must hang.
     */
    public function acquire(): PromiseInterface
    {
        if ($this->closed) {
            throw new \LogicException(Lang::t('semaphore.closed'));
        }

        $this->waiters[] = $waiter = new Deferred();
        $this->dispatch();

        return $waiter->promise();
    }

    /**
     * Hand one permit back and wake the next waiter, if any.
     *
     * Idempotent at the floor: a release with nothing held is a no-op rather
     * than a negative count, because a negative `held` would push `available()`
     * above `limit()` and every grant would then be off by one.
     *
     * What the floor does NOT do is make an unbalanced release safe. Permits
     * are unowned tokens, so a caller who releases twice on an error path can
     * free a slot a live task still occupies and let `limit()+1` tasks through.
     * Balance your own acquire/release pairs, or go through {@see run()}, which
     * owns the pairing for you.
     */
    public function release(): void
    {
        if ($this->held === 0) {
            return;
        }

        $this->held--;
        $this->dispatch();
    }

    /**
     * Run $task through the pool: it starts only once a permit is free, and the
     * permit returns the moment the task's promise settles.
     *
     * This is the normal entry point — acquire/release pairing by hand is where
     * a missed release starts leaking capacity.
     *
     * The permit is handed back BEFORE the returned promise is settled, so a
     * handler observing `running()`/`available()` sees this task's slot already
     * recycled and may immediately submit follow-up work through the same pool.
     * The trade-off is that the next queued task can start a few frames before
     * your handler runs; the alternative ordering reported a phantom permit to
     * every completion handler.
     *
     * @template T
     * @param callable(): (PromiseInterface<T>|T) $task a plain value is accepted
     *                                                  and treated as already resolved.
     * @return PromiseInterface<T> Resolves with the task's value, or rejects with
     *                            the task's own throwable. A task parked when the
     *                            pool is retired rejects instead with
     *                            {@see SemaphoreClosedException} — a distinct type,
     *                            because the task never ran and the caller has to
     *                            be able to tell that from a failure of its own.
     * @throws \LogicException when the pool is already closed (see {@see acquire()}).
     */
    public function run(callable $task): PromiseInterface
    {
        /** @var Deferred<T> $done */
        $done = new Deferred();

        $this->acquire()->then(
            function () use ($task, $done): void {
                $this->launch($task, $done);
            },
            function (\Throwable $reason) use ($done): void {
                // Only reachable from close()/destruction; keep the cause.
                $done->reject($reason);
            },
        );

        return $done->promise();
    }

    /**
     * Retire the pool: fail every parked waiter and refuse further permits.
     *
     * Parked waiters reject with {@see SemaphoreClosedException}; in-flight tasks
     * are deliberately untouched — they hold the permits and their promises
     * belong to their callers, so closing never aborts work that is already
     * running, it only stops new work from being promised a slot. Idempotent.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        $queued = $this->waiters;
        $this->waiters = [];

        // One throwable for the whole queue: building it per waiter captured a
        // stack frame set apiece, so retiring a pool with hundreds of parked
        // tasks buried stderr in near-identical rejections.
        $reason = new SemaphoreClosedException(Lang::t('semaphore.shutdown'));

        foreach ($queued as $waiter) {
            $waiter->reject($reason);
        }
    }

    /**
     * Invoke a permit-holder's task and give the permit back on settlement.
     *
     * @template T
     * @param callable(): (PromiseInterface<T>|T) $task
     * @param Deferred<T> $done
     */
    private function launch(callable $task, Deferred $done): void
    {
        try {
            $promise = resolve($task());
        } catch (\Throwable $reason) {
            // The client original let a synchronous throw escape its dispatch
            // loop with the slot already counted as running and the deferred
            // never settled — one leaked permit and one promise hanging forever.
            // A task that blows up on invocation must still return its permit.
            $this->release();
            $done->reject($reason);

            return;
        }

        $promise->then(
            function (mixed $value) use ($done): void {
                $this->release();
                $done->resolve($value);
            },
            function (\Throwable $reason) use ($done): void {
                $this->release();
                $done->reject($reason);
            },
        );
    }

    /**
     * Grant permits to the head of the queue while capacity allows.
     *
     * Non-re-entrant by necessity. react/promise settles synchronously, so
     * `resolve()` runs the waiter's handlers here — and a handler that finishes
     * its work immediately calls {@see release()}, which would otherwise re-enter
     * this loop one PHP stack frame deeper per queued task. A saturated pool
     * with a few thousand short-lived tasks drained that way overflowed the
     * stack and dumped core. Holding the flag makes the inner call a no-op and
     * lets the ONE loop below pick up the freed permit on its next iteration, so
     * the drain costs constant stack regardless of queue depth.
     *
     * The same flag is why a task must not re-feed this pool synchronously from
     * its own completion handler in an unbounded loop: the drain pass would
     * never return to the event loop. Real work returns a pending promise.
     */
    private function dispatch(): void
    {
        if ($this->dispatching) {
            return;
        }

        $this->dispatching = true;

        try {
            // `!$this->closed` is redundant today — `acquire()` is the only
            // appender and it refuses a closed pool, while `close()` empties the
            // queue first — but stating it here keeps "a retired pool grants
            // nothing" checkable by reading this loop, instead of requiring the
            // reader to prove it across three methods.
            while (!$this->closed && $this->waiters !== [] && $this->held < $this->limit) {
                /** @var Deferred<self> $waiter */
                $waiter = array_shift($this->waiters);
                $this->held++;

                // The ceiling is safe against re-entrancy because `held` is
                // incremented before the callback runs and the loop condition is
                // re-read from live state after it, never from a cached copy.
                $waiter->resolve($this);
            }
        } finally {
            $this->dispatching = false;
        }
    }

    /**
     * Fail anything still parked when the pool goes away unreferenced.
     *
     * A dropped pool can never grant again, so leaving waiters pending turns a
     * lifecycle mistake into a hang. Prefer {@see close()}, which does this at a
     * point the caller chooses — the promise handlers a parked `run()` installs
     * reference the pool, so `unset()` alone leaves the destructor waiting on
     * PHP's cycle collector.
     */
    public function __destruct()
    {
        $this->close();
    }
}
