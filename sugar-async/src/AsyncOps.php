<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Static helpers for async operations on top of ReactPHP.
 *
 * withTimeout(), withDeadline(), cancellable(), retry(), all(), race(),
 * pendingNotice() and boundWait() are stateless helpers. debounce() and
 * throttle() return invokable Subscription handles that retain mutable
 * timer/cooldown state across calls; singleFlight() returns a stateful
 * coalescing closure.
 *
 * The operations these helpers invoke must return a PromiseInterface:
 * retry(), singleFlight() and AsyncCache::fetch() share one guard
 * vocabulary, refusing a non-promise with an InvalidArgumentException that
 * names the returned type. The refusal travels the caller's own rejection
 * channel (the attempt's, the flight's), never a synchronous surprise.
 *
 * Nothing here blocks the caller: every wait is expressed as a promise,
 * and every timer is armed on a supplied or shared event loop.
 *
 * Parameter order is uniform: the wrapped promise leads, and the event loop
 * is always a trailing optional parameter (house rule: no static here takes
 * the loop first). withTimeout() and withDeadline() still accept the
 * historical loop-first positional call for one more release — transposed
 * with an E_USER_DEPRECATED notice, support ends in 2.0.0.
 */
final class AsyncOps
{
    /**
     * Hard ceiling on a single retry backoff delay (seconds).
     *
     * The 2^n ladder plateaus here instead of growing unbounded: past this
     * horizon a timer is no longer a long wait but a silently-stuck cycle
     * (StreamSelectLoop clamps one tick to ~2.56 h; ext-uv casts float
     * intervals toward ints), and the doubling itself outlives every real
     * outage. onRetry() reports the capped value, so an operator sees the
     * plateau rather than a fantasy exponent.
     */
    public const MAX_BACKOFF_SECONDS = Retry::MAX_BACKOFF_SECONDS;

    /**
     * Sink for swallowed-failure notices; null means the PHP default.
     *
     * @var (callable(string): void)|null
     */
    private static ?\Closure $logSink = null;

    /**
     * Redirect (or restore) the destination of swallowed-failure notices.
     *
     * Every hook failure this library swallows (cancellation callbacks,
     * the retry/pendingNotice hooks, subscription disposal) is logged, not
     * propagated — see the hook-failure invariant in the README. By default
     * the line goes to error_log(); a TUI host that must not interleave text
     * over a live frame installs a sink here to route those lines wherever
     * it keeps diagnostics. Pass null to restore the error_log default.
     *
     * A sink that throws is itself swallowed: the notice seam must never
     * turn a contained failure back into a propagating one.
     *
     * @param (callable(string): void)|null $sink
     */
    public static function setLogSink(?callable $sink): void
    {
        self::$logSink = $sink === null ? null : \Closure::fromCallable($sink);
    }

    /**
     * Route one swallowed-failure notice through the configured sink.
     *
     * @internal The library's four hook-failure sites call this; hosts use
     *           {@see setLogSink()} and never call it directly.
     */
    public static function logFailure(string $message): void
    {
        $sink = self::$logSink ?? static fn(string $line): bool => \error_log($line);
        try {
            $sink($message);
        } catch (\Throwable) {
            // A broken sink is the host's problem to notice in its own
            // diagnostics; it must not escape the hook that failed first.
        }
    }

    /**
     * Wrap a promise with a timeout. If the timeout fires before the
     * promise settles, the returned promise rejects with TimeoutException.
     * The inner promise is NOT cancelled and keeps running to completion.
     * The wait itself is abandonable: cancel() on the returned promise
     * releases the timer and rejects with OperationCancelledException —
     * abandon, not abort (stopping the work is withDeadline()'s contract).
     * When the work itself must stop as the wait ends — a hung I/O holding its
     * consumer's in-flight gate open forever — use {@see withDeadline()},
     * which cancels the inner operation on both expiry and abandonment.
     *
     * @param PromiseInterface $promise
     * @param float $seconds  Timeout in seconds (must be > 0 and finite)
     * @param LoopInterface|null $loop  Event loop; null (default) uses the shared Loop::get().
     * @param string|null $label  Operation name woven into the rejection
     *        message and carried on {@see TimeoutException::label()} — a TUI
     *        catching one TimeoutException among several wrapped calls needs
     *        the identity as data, not archaeology through the stack trace.
     * @return PromiseInterface
     *
     * @deprecated Passing the loop FIRST (loop, promise, seconds, ...) is
     *             deprecated since 1.x: such calls are transposed with an
     *             E_USER_DEPRECATED notice. Loop-first support is REMOVED-IN
     *             2.0.0 — call withTimeout($promise, $seconds, $loop).
     */
    public static function withTimeout(
        PromiseInterface|LoopInterface $promise,
        mixed $seconds = null,
        LoopInterface|float|int|string|null $loop = null,
        string|null $label = null,
    ): PromiseInterface {
        return Timeouts::withTimeout($promise, $seconds, $loop, $label);
    }

    /**
     * Wrap a promise with a deadline that CANCELS the inner promise when it
     * fires; {@see withTimeout()} by contrast lets the inner run on.
     *
     * WHY both exist: withTimeout answers "how long will the CALLER wait" and
     * stays out of the operation's way; withDeadline answers "how long may the
     * WORK take" — a hung I/O whose socket never errors (a silently dropped
     * route produces no socket error in react/mysql/PgAsync terms) would
     * otherwise keep a pending promise and its consumer's in-flight gate alive
     * forever. When the deadline fires, the inner is cancelled and the
     * optional $onTimeout hook gets one shot at the server-side abort the
     * driver's own cancellation cannot perform; a late driver result is
     * dropped, never resurrected.
     *
     * Bounded-I/O-ceiling semantics: a deadline is a ceiling computed on the
     * REMAINING budget — the caller decides how much of its allowance is left,
     * mirroring how retry() converts maxTotalSeconds into one absolute cutoff
     * — not a blanket per-request wall-clock killer applied blindly to every
     * hop.
     *
     * The timer is armed before the inner's settle handlers attach (the
     * withTimeout arming rule): react/promise v3 settles an already-settled
     * inner synchronously inside then(), so the handlers must find the timer
     * in hand to cancel it.
     *
     * Cancel() on the returned promise runs the full deadline teardown —
     * hook fired, inner cancelled, OperationCancelledException — the wait
     * ending early is still a wait that must not outlive its work.
     *
     * @param PromiseInterface $promise
     * @param float $seconds  Deadline in seconds (must be > 0 and finite)
     * @param LoopInterface|null $loop  Event loop; null (default) uses the shared Loop::get().
     * @param null|callable(): void $onTimeout  Abort hook, fired once when the
     *        deadline hits; errors are swallowed so a failed kill cannot mask
     *        the timeout rejection.
     * @param string|null $label  Operation name for the rejection message and
     *        {@see TimeoutException::label()}; see withTimeout().
     * @return PromiseInterface
     *
     * @deprecated Passing the loop FIRST (loop, promise, seconds, ...) is
     *             deprecated since 1.x: such calls are transposed with an
     *             E_USER_DEPRECATED notice. Loop-first support is REMOVED-IN
     *             2.0.0 — call withDeadline($promise, $seconds, $loop).
     */
    public static function withDeadline(
        PromiseInterface|LoopInterface $promise,
        mixed $seconds = null,
        LoopInterface|float|int|string|null $loop = null,
        ?callable $onTimeout = null,
        ?string $label = null,
    ): PromiseInterface {
        return Timeouts::withDeadline($promise, $seconds, $loop, $onTimeout, $label);
    }

    /**
     * Bind a promise to a {@see CancellationToken}: cooperative, two-sided,
     * immediate cancellation.
     *
     * Client side (always): the returned promise rejects synchronously with
     * {@see OperationCancelledException} the moment the token fires — no event
     * loop turn is needed — and a late-arriving inner result is dropped, never
     * resurrected. Server side (optional): the $onCancel hook fires exactly
     * once so the producer can abort the underlying work (MySQL KILL QUERY,
     * closing a socket); hook errors are swallowed because cancellation must
     * never fail the caller a second time. The inner promise is also
     * cancelled, so producers that support promise cancellation clean up
     * themselves, and cancelling the RETURNED promise behaves exactly like
     * cancelling through the token.
     *
     * Extracted from the candy-sql CancellableQuery pattern.
     *
     * A token reused across many operations does not accumulate: the wrapper
     * unsubscribes its own registration the moment the operation settles OR is
     * abandoned, and onCancel() hands back a removal handle for consumer code
     * to do the same.
     *
     * @template T
     * @param PromiseInterface<T> $promise  The in-flight operation
     * @param CancellationToken|null $token  Cancel handle; null returns the promise untouched
     * @param null|callable(): void $onCancel  Underlying-work abort hook (fired once, errors swallowed)
     * @param string|null $label  Operation name woven into the rejection
     *        message and carried on {@see OperationCancelledException::label()}.
     * @return PromiseInterface<T>
     */
    public static function cancellable(
        PromiseInterface $promise,
        ?CancellationToken $token,
        ?callable $onCancel = null,
        ?string $label = null,
    ): PromiseInterface {
        if ($token === null) {
            return $promise;
        }

        $settled = false;

        $abort = static function () use (&$settled, $promise, $onCancel): void {
            if ($settled === true) {
                return;
            }
            $settled = true;
            if ($onCancel !== null) {
                try {
                    $onCancel();
                } catch (\Throwable) {
                    // A failed abort must not mask the cancellation.
                }
            }
            // A driver's cancel() may explode; the caller's cancellation is
            // already decided (token path) or is the throw below (wrapper
            // path), so a failed inner abort must not escape $abort.
            try {
                $promise->cancel();
            } catch (\Throwable) {
            }
        };

        // The registration is captured by reference: cancel() cannot fire
        // before the method returns, so the canceller always sees the
        // assigned handle. Dropping it here (not only at settlement) is what
        // keeps an abandoned operation from leaving its closure chain on a
        // long-lived token forever — a driver that never settles would
        // otherwise hold the hold-open until the token itself dies.
        $registration = null;
        $deferred = new Deferred(static function () use ($abort, &$registration, $label): never {
            $abort();
            $registration?->unsubscribe();
            throw new OperationCancelledException(Timeouts::cancellationMessage($label), label: $label);
        });

        // onCancel fires synchronously when the token is already cancelled,
        // so a pre-cancelled token rejects before the result can land.
        $registration = $token->onCancel(static function () use (&$settled, $abort, $deferred, $label): void {
            if ($settled === true) {
                return;
            }
            $abort();
            $deferred->reject(new OperationCancelledException(Timeouts::cancellationMessage($label), label: $label));
        });

        $promise->then(
            static function (mixed $value) use (&$settled, $deferred, $registration): void {
                // The operation is over; the wrapper's own hold on the token
                // is released (idempotent) so shared tokens stay clean.
                $registration->unsubscribe();
                if ($settled !== true) {
                    $settled = true;
                    $deferred->resolve($value);
                }
            },
            static function (\Throwable $e) use (&$settled, $deferred, $registration): void {
                $registration->unsubscribe();
                if ($settled !== true) {
                    $settled = true;
                    $deferred->reject($e);
                }
            },
        );

        return $deferred->promise();
    }

    /**
     * Coalesce concurrent invocations of an async worker by key.
     *
     * The returned callable runs $worker only when no flight is in progress
     * for the key derived from its arguments; every caller that arrives while
     * a flight is open receives the SAME promise. The slot is freed the moment
     * the flight settles, so the next wave starts fresh — this is request
     * coalescing, NOT result caching (for that, see {@see AsyncCache}).
     *
     * Rides the same {@see InFlightMap} mechanism that backs AsyncCache's
     * loader dedupe: the slot is occupied before the worker runs and freed
     * exactly once when it settles.
     *
     * A leader whose promise never settles blocks the key forever: bound the
     * worker itself ({@see withDeadline()}/{@see boundWait()}) when the loader
     * may stall. Flight state is per-process — never share a singleFlight
     * closure across pcntl_fork: a child's settles do not propagate, so the
     * parent's slot leaks.
     *
     * The shared flight is deliberately NOT caller-abandonable: every
     * coalesced caller holds the SAME promise, so one caller's cancel() must
     * not reject it for the others. If YOUR join must be abandonable, wrap
     * it — AsyncOps::cancellable($flight, $token).
     *
     * @param callable(...mixed): string $keyFn  Derives the flight key from the call arguments (must not return '')
     * @param callable(...mixed): PromiseInterface $worker  Starts the backing operation
     * @return callable(...mixed): PromiseInterface  The coalescing wrapper
     */
    public static function singleFlight(callable $keyFn, callable $worker): callable
    {
        $flights = new InFlightMap();

        return static function (mixed ...$args) use ($keyFn, $worker, $flights): PromiseInterface {
            $key = $keyFn(...$args);
            if ($key === '') {
                throw new \InvalidArgumentException('Flight key must not be empty');
            }

            $existing = $flights->find($key);
            if ($existing !== null) {
                return $existing;
            }

            // Drive a Deferred so the in-flight gate is occupied BEFORE the
            // worker can settle (react/promise v3 settles an already-resolved
            // promise synchronously inside then()) and freed exactly once
            // when it does — the in-flight ordering rule InFlightMap pins.
            $deferred = new Deferred();
            $flight = $deferred->promise();
            $flights->begin($key, $flight);

            try {
                $workerResult = $worker(...$args);
                if (!$workerResult instanceof PromiseInterface) {
                    // Same guard vocabulary as retry and AsyncCache::fetch:
                    // name the offending type instead of letting a
                    // then()-on-string Error ride the flight.
                    throw new \InvalidArgumentException('AsyncOps singleFlight worker must return a promise; ' . get_debug_type($workerResult) . ' returned.');
                }
                $workerResult->then(
                    static function (mixed $value) use ($key, $flight, $flights, $deferred): void {
                        $flights->end($key, $flight);
                        $deferred->resolve($value);
                    },
                    static function (\Throwable $error) use ($key, $flight, $flights, $deferred): void {
                        $flights->end($key, $flight);
                        $deferred->reject($error);
                    },
                );
            } catch (\Throwable $error) {
                // A worker that throws synchronously (or returns a non-promise)
                // fails this flight loudly and frees the slot, so the next wave
                // can retry rather than inheriting a stuck flight.
                $flights->end($key, $flight);
                $deferred->reject($error);
            }

            return $flight;
        };
    }

    /**
     * Retry a callable $attempts times in total with exponential backoff.
     *
     * Cancel() on the returned promise abandons the whole cycle: backoff
     * timers are released, no further attempt is scheduled, the current
     * attempt's promise is cancelled, and the returned promise rejects with
     * OperationCancelledException. A $token fired mid-attempt does the same
     * to the visible promise: the in-flight attempt is cancelled, and if the
     * driver ignores the abort (a hung or canceller-less promise), the wait
     * rejects immediately with a generic 'Retry cancelled mid-cycle'
     * OperationCancelledException rather than waiting forever; a cooperative
     * driver still settles with the stage-specific rejection.
     *
     * The attempt cap alone does not bound wall-clock: exponential backoff
     * (2^n) can stretch a bounded attempt count into an unbounded wait, and a
     * fleet of clients retrying in lockstep produces a thundering herd. Pass
     * $maxTotalSeconds to cap total elapsed time across attempts, and $jitter
     * to randomize each backoff. Both default to the historical behavior
     * (no cap, no jitter). The ladder itself plateaus at
     * {@see self::MAX_BACKOFF_SECONDS}: a delay past that ceiling is clamped
     * to it (and reported to $onRetry clamped), so the cycle can never arm a
     * beyond-the-horizon forever-timer.
     *
     * @param callable(): PromiseInterface $operation  The operation to retry
     * @param int $attempts  Maximum number of attempts (must be >= 1)
     * @param float $baseBackoffSeconds  Initial backoff after a failure (seconds)
     * @param CancellationToken|null $token  Optional cancellation token to abort
     *        retries — honoured before, during and after each attempt (see above).
     * @param float|null $maxTotalSeconds  Optional wall-clock budget across all
     *        attempts. The budget bounds when a NEW attempt may start: a
     *        backoff that would overshoot the cutoff is clamped to start
     *        exactly at it, and once the cutoff passes the cycle stops without
     *        scheduling — rejecting with a TimeoutException naming the
     *        exhausted budget (the last failure rides along as `previous`), so
     *        "gave up after the budget" is distinguishable from "the operation
     *        keeps failing". Null (default) imposes no cap.
     * @param float $jitter  Fractional randomization of each backoff in
     *        [0, $jitter]; the delay lands in [backoff, backoff*(1+$jitter)].
     *        0.0 (default) means no jitter (exactly the base backoff).
     * @param LoopInterface|null $loop  Optional event loop; uses Loop::get() if null.
     * @param null|callable(): float $now  Optional monotonic-seconds clock
     *        (test seam — fake it to drive budget exhaustion without sleeping);
     *        defaults to hrtime(true)/1e9.
     * @param null|callable(int, float): void $onRetry  Progress hook fired at
     *        scheduling time with (attemptNumber, plannedDelaySeconds) before
     *        each backoff is armed — the surface an "attempt 2 of 3" spinner
     *        renders from. Advisory: like the other notice hooks, a throw from
     *        it is logged, never propagated.
     * @return PromiseInterface
     */
    public static function retry(
        callable $operation,
        int $attempts = 3,
        float $baseBackoffSeconds = 0.1,
        ?CancellationToken $token = null,
        ?float $maxTotalSeconds = null,
        float $jitter = 0.0,
        ?LoopInterface $loop = null,
        ?callable $now = null,
        ?callable $onRetry = null,
    ): PromiseInterface {
        return Retry::retry($operation, $attempts, $baseBackoffSeconds, $token, $maxTotalSeconds, $jitter, $loop, $now, $onRetry);
    }




    /** Message subject: named when the caller passed a label, generic otherwise. */

    /** Rejection text for an abandoned or cancelled wait: named when labelled. */



    /**
     * Fan-in: resolve with every value once ALL promises resolve, preserving
     * keys; reject with the FIRST error the moment one arrives. Non-promise
     * entries are treated as already-resolved values. An empty set resolves
     * with []. These combinators are the package's own promise vocabulary: callers
     * need all() and race() here and never reach past this package for a
     * fan-in primitive.
     *
     * @param iterable<mixed> $promises
     * @return PromiseInterface<array>
     */
    public static function all(iterable $promises): PromiseInterface
    {
        $list = is_array($promises) ? $promises : iterator_to_array($promises);
        if ($list === []) {
            return resolve([]);
        }

        $deferred = new Deferred();
        $pending = count($list);
        $values = [];
        $settled = false;

        foreach ($list as $key => $item) {
            $promise = $item instanceof PromiseInterface ? $item : resolve($item);
            $promise->then(
                static function (mixed $value) use ($key, &$values, &$pending, &$settled, $deferred): void {
                    if ($settled === true) {
                        return;
                    }
                    $values[$key] = $value;
                    if (--$pending === 0) {
                        $settled = true;
                        $deferred->resolve($values);
                    }
                },
                static function (\Throwable $error) use (&$settled, $deferred): void {
                    if ($settled === true) {
                        return; // first rejection wins; later settles are nobody's business
                    }
                    $settled = true;
                    $deferred->reject($error);
                },
            );
        }

        return $deferred->promise();
    }

    /**
     * Fan-in: settle with whichever settles first — value or error. The empty
     * set is a refusal, not a forever-pending promise — that shape renders as
     * a screen frozen with no error to show.
     *
     * @param iterable<mixed> $promises
     * @return PromiseInterface<mixed>
     */
    public static function race(iterable $promises): PromiseInterface
    {
        $list = is_array($promises) ? $promises : iterator_to_array($promises);
        if ($list === []) {
            throw new \InvalidArgumentException('race() requires at least one promise');
        }

        $deferred = new Deferred();
        $settled = false;

        foreach ($list as $item) {
            $promise = $item instanceof PromiseInterface ? $item : resolve($item);
            $promise->then(
                static function (mixed $value) use (&$settled, $deferred): void {
                    if ($settled === true) {
                        return;
                    }
                    $settled = true;
                    $deferred->resolve($value);
                },
                static function (\Throwable $error) use (&$settled, $deferred): void {
                    if ($settled === true) {
                        return;
                    }
                    $settled = true;
                    $deferred->reject($error);
                },
            );
        }

        return $deferred->promise();
    }

    /**
     * Announce slowness without interrupting the wait. If $promise is still
     * pending after $seconds, $onPending runs ONCE (a "taking longer than
     * expected" spinner hint). The returned promise tracks the inner one —
     * the notice neither cancels nor abandons the work, and the E646 rule
     * holds: this is an advisory tick, never a wall-clock killer of
     * user-paced operations. A hook that throws is logged, never propagated.
     *
     * @param callable(): void $onPending  Required advisory notice
     * @return PromiseInterface<mixed>
     */
    public static function pendingNotice(
        PromiseInterface $promise,
        float $seconds,
        callable $onPending,
        ?LoopInterface $loop = null,
    ): PromiseInterface {
        return Pending::pendingNotice($promise, $seconds, $onPending, $loop);
    }

    /**
     * Bound the wait without stopping the work. If $promise is still pending
     * after $seconds, the returned promise rejects with a TimeoutException
     * whose message names (still pending) — the inner operation keeps
     * running, like under withTimeout(). These are the bounded-wait
     * semantics for user-paced operations: this library puts no blanket
     * wall-clock timeout on operations whose pace belongs to the user. To
     * announce a slow wait instead of ending it, use {@see pendingNotice()}.
     *
     * @return PromiseInterface<mixed>
     */
    public static function boundWait(
        PromiseInterface $promise,
        float $seconds,
        ?LoopInterface $loop = null,
        ?string $label = null,
    ): PromiseInterface {
        return Pending::boundWait($promise, $seconds, $loop, $label);
    }

    /**
     * @deprecated REMOVED-IN: 2.0.0 — the dual contract is split into
     *             {@see pendingNotice()} (advisory hook) and
     *             {@see boundWait()} (bounded rejection) so the mode is
     *             visible at the call site. This shim delegates: a hook
     *             goes to pendingNotice(), a null hook to boundWait().
     */
    public static function onPending(
        PromiseInterface $promise,
        float $seconds,
        ?callable $onPending = null,
        ?LoopInterface $loop = null,
        ?string $label = null,
    ): PromiseInterface {
        return Pending::onPending($promise, $seconds, $onPending, $loop, $label);
    }


    /**
     * Wrap a callable with debounce — only the last call within the window
     * fires, and only after $seconds have elapsed since it.
     *
     * The returned handle is an invokable {@see Debounced} Subscription: each
     * call answers a promise (fire's value; null when superseded or dropped),
     * flush() runs the pending burst now, and unsubscribe() disposes it. A
     * CancellationSource given as $source disposes the handle on cancel.
     *
     * @param callable(mixed...): mixed $fn  The function to debounce
     * @param float $seconds  Debounce window (positive finite seconds)
     * @param LoopInterface|null $loop  Optional loop; uses Loop::get() if null
     * @param CancellationSource|null $source  Optional extra dispose source
     * @return Debounced  The invokable, disposable wrapper
     */
    public static function debounce(
        callable $fn,
        float $seconds,
        ?LoopInterface $loop = null,
        ?CancellationSource $source = null,
    ): Debounced {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Debounce seconds must be a positive finite number');
        }

        return Debounced::new($fn, $seconds, $loop, $source);
    }

    /**
     * Wrap a callable with throttle — the function fires at most once every
     * $seconds, regardless of call frequency. Leading-edge by default: calls
     * inside the window answer null. With $trailing true, the LAST suppressed
     * call fires when the window closes instead, and that fire consumes a
     * fresh window — the cap holds at every edge.
     *
     * The returned handle is an invokable {@see Throttled} Subscription;
     * unsubscribe() cancels the cooldown and drops any queued trailing call,
     * flush() runs it immediately instead.
     *
     * @param callable(mixed...): mixed $fn  The function to throttle
     * @param float $seconds  Minimum interval between fires (positive finite seconds)
     * @param LoopInterface|null $loop  Optional loop; uses Loop::get() if null
     * @param bool $trailing  Fire the last suppressed call at window end
     * @return Throttled  The invokable, disposable wrapper
     */
    public static function throttle(
        callable $fn,
        float $seconds,
        ?LoopInterface $loop = null,
        bool $trailing = false,
    ): Throttled {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Throttle seconds must be a positive finite number');
        }

        return Throttled::new($fn, $seconds, $loop, $trailing);
    }

    /**
     * Synchronous poll-until-final under a wall-clock budget. Forwards to
     * {@see Retry::bounded()} so callers that already import AsyncOps need no
     * second entry point; unlike {@see retry()} this blocks and keys off the
     * result (not a rejection).
     *
     * @template T
     * @param callable(): T $operation
     * @param callable(T): bool $shouldRetry
     * @return T
     */
    public static function retryBounded(
        callable $operation,
        callable $shouldRetry,
        int $budgetMs,
        Backoff $backoff,
        ?callable $now = null,
        ?callable $sleep = null,
    ): mixed {
        return Retry::bounded($operation, $shouldRetry, $budgetMs, $backoff, $now, $sleep);
    }

    /**
     * Pay a sleep debt that survives signal interruption. Forwards to
     * {@see Retry::nap()}.
     */
    public static function nap(
        int $owedMicros,
        ?callable $sleeper = null,
        ?callable $now = null,
        ?callable $onBeat = null,
    ): void {
        Retry::nap($owedMicros, $sleeper, $now, $onBeat);
    }

    /**
     * Redial until a connection succeeds or the token fires. Forwards to
     * {@see Retry::reconnect()}.
     *
     * @param callable(): PromiseInterface $dial
     * @param null|callable(int, int, \Throwable): void $onRetry
     */
    public static function reconnect(
        callable $dial,
        Backoff $backoff,
        ?CancellationToken $token = null,
        ?LoopInterface $loop = null,
        ?callable $onRetry = null,
    ): PromiseInterface {
        return Retry::reconnect($dial, $backoff, $token, $loop, $onRetry);
    }

    /**
     * Drain several child pipes against one deadline. Forwards to
     * {@see ChildPump::drain()}.
     *
     * @param array<int|string, resource> $pipes
     * @param array<int, int|string> $discard
     * @param null|callable(): bool $running  Child-still-running probe; Windows
     *        needs it to end before the deadline ({@see WindowsPipes::runningProbe()})
     */
    public static function drain(
        array $pipes,
        float $deadline,
        int $maxBytes,
        ?callable $clock = null,
        bool $stopAtCap = false,
        array $discard = [],
        ?callable $running = null,
    ): DrainResult {
        return ChildPump::drain($pipes, $deadline, $maxBytes, $clock, $stopAtCap, $discard, running: $running);
    }

    /**
     * Run $fn on the next loop tick instead of inline, answering its result as
     * a promise. Use it to take a slow or re-entrant step off the caller's
     * stack: the caller gets control back now, and $fn runs once the current
     * callback has returned.
     *
     * A throw from $fn rejects the promise with that exception. No cancel
     * handle is offered: once $fn is queued it runs, and the caller that needs
     * to stop it must check its own state inside $fn. Mirrors the command
     * effects candy-code's SessionCommands pushed onto the loop by hand.
     *
     * @template T
     * @param \Closure(): T $fn
     * @return PromiseInterface<T>
     */
    public static function deferred(\Closure $fn, ?LoopInterface $loop = null): PromiseInterface
    {
        $loop ??= Loop::get();
        $deferred = new Deferred();
        $loop->futureTick(static function () use ($fn, $deferred): void {
            try {
                $deferred->resolve($fn());
            } catch (\Throwable $error) {
                $deferred->reject($error);
            }
        });

        return $deferred->promise();
    }
}
