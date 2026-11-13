<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
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
 * Nothing here blocks the caller: every wait is expressed as a promise,
 * and every timer is armed on a supplied or shared event loop.
 *
 * Parameter order is uniform: the wrapped promise leads, and the event loop
 * is always a trailing optional parameter (house law: no static here takes
 * the loop first). withTimeout() and withDeadline() still accept the
 * historical loop-first positional call for one more release — transposed
 * with an E_USER_DEPRECATED notice, support ends in 2.0.0.
 */
final class AsyncOps
{
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
        [$promise, $seconds, $loop, , $label] = self::normalizeWaitArgs(
            __FUNCTION__, $promise, $seconds, $loop, null, $label,
        );

        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Timeout seconds must be a positive finite number');
        }

        $loop ??= Loop::get();

        $settled = false;
        /** @var \React\EventLoop\TimerInterface|null */
        $timer = null;

        $deferred = new Deferred(static function () use (&$settled, $loop, &$timer, $label): never {
            $settled = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            throw new OperationCancelledException(self::cancellationMessage($label), label: $label);
        });

        // Arm the timeout BEFORE attaching the settle handlers. react/promise
        // v3 settles an already-settled inner synchronously inside then(), so
        // a handler attached first would see a not-yet-assigned timer, skip
        // the cancel, and leak the armed timer onto the (shared) loop for its
        // full duration. Armed first, the timer is always in hand when the
        // handler runs — pre-settled inners cancel it in the same synchronous
        // pass, and cancelTimer on an already-fired one-shot is a no-op.
        $timer = $loop->addTimer($seconds, function () use (&$settled, $deferred, $seconds, $label): void {
            if ($settled === true) {
                return; // wait already abandoned or settled — nothing to do
            }
            $settled = true;
            $deferred->reject(new TimeoutException(
                self::timeoutMessage($label, $seconds),
                seconds: $seconds,
                label: $label,
            ));
        });

        // Settle the outer promise when the inner settles.
        $promise->then(
            function ($value) use (&$settled, $deferred, $timer, $loop): void {
                if ($settled === true) {
                    return; // abandoned or timed out — late results are dropped
                }
                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->resolve($value);
            },
            function (\Throwable $reason) use (&$settled, $deferred, $timer, $loop): void {
                if ($settled === true) {
                    return;
                }
                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->reject($reason);
            },
        );

        return $deferred->promise();
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
     * forever. When the deadline fires, the inner is cancel()ed and the
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
     * withTimeout arming law): react/promise v3 settles an already-settled
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
        [$promise, $seconds, $loop, $onTimeout, $label] = self::normalizeWaitArgs(
            __FUNCTION__, $promise, $seconds, $loop, $onTimeout, $label,
        );

        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Deadline seconds must be a positive finite number');
        }

        $loop ??= Loop::get();

        $settled = false;
        /** @var \React\EventLoop\TimerInterface|null */
        $timer = null;

        $deferred = new Deferred(function () use (&$settled, $loop, &$timer, $promise, $onTimeout, $label): never {
            $settled = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            if ($onTimeout !== null) {
                try {
                    $onTimeout();
                } catch (\Throwable) {
                    // Same invariant as the deadline path: a failed kill is not the
                    // caller's truth.
                }
            }
            try {
                $promise->cancel();
            } catch (\Throwable) {
                // The abandonment rejection below stands regardless.
            }
            throw new OperationCancelledException(self::cancellationMessage($label), label: $label);
        });

        $timer = $loop->addTimer($seconds, function () use (&$settled, $deferred, $promise, $seconds, $onTimeout, $label): void {
            if ($settled === true) {
                return;
            }
            $settled = true;

            if ($onTimeout !== null) {
                try {
                    $onTimeout();
                } catch (\Throwable) {
                    // The timeout rejection below is the caller-facing truth;
                    // a failed abort hook must not replace it.
                }
            }

            $deferred->reject(new TimeoutException(
                self::deadlineMessage($label, $seconds),
                seconds: $seconds,
                label: $label,
            ));

            // Cancel AFTER settling the caller-facing promise: the caller's
            // truth is the timeout, and a driver whose cancel() re-settles the
            // inner must not reach them through the guards below. A driver
            // whose cancel() THROWS must likewise not escape this callback —
            // it would poison the loop tick for every other watcher.
            try {
                $promise->cancel();
            } catch (\Throwable) {
            }
        });

        $promise->then(
            function ($value) use (&$settled, $deferred, $timer, $loop): void {
                if ($settled === true) {
                    return; // deadline already won — late results are dropped
                }
                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->resolve($value);
            },
            function (\Throwable $reason) use (&$settled, $deferred, $timer, $loop): void {
                if ($settled === true) {
                    return;
                }
                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->reject($reason);
            },
        );

        return $deferred->promise();
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
     * cancel()ed, so producers that support promise cancellation clean up
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
            throw new OperationCancelledException(self::cancellationMessage($label), label: $label);
        });

        // onCancel fires synchronously when the token is already cancelled,
        // so a pre-cancelled token rejects before the result can land.
        $registration = $token->onCancel(static function () use (&$settled, $abort, $deferred, $label): void {
            if ($settled === true) {
                return;
            }
            $abort();
            $deferred->reject(new OperationCancelledException(self::cancellationMessage($label), label: $label));
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
            // when it does — the in-flight ordering law InFlightMap pins.
            $deferred = new Deferred();
            $flight = $deferred->promise();
            $flights->begin($key, $flight);

            try {
                $worker(...$args)->then(
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
     * attempt's promise is cancel()ed, and the returned promise rejects with
     * OperationCancelledException. A $token fired mid-attempt does the same
     * to the visible promise: the in-flight attempt is cancel()ed, and if the
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
     * (no cap, no jitter).
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
        if ($attempts < 1) {
            throw new \InvalidArgumentException('Attempts must be >= 1');
        }
        if ($baseBackoffSeconds <= 0 || !is_finite($baseBackoffSeconds)) {
            throw new \InvalidArgumentException('Base backoff must be a positive finite number');
        }
        if ($maxTotalSeconds !== null && ($maxTotalSeconds <= 0 || !is_finite($maxTotalSeconds))) {
            throw new \InvalidArgumentException('Max total seconds must be a positive finite number when set');
        }
        if ($jitter < 0 || !is_finite($jitter)) {
            throw new \InvalidArgumentException('Jitter must be a finite number >= 0');
        }

        // One internal stop source drives every cancellation path: the
        // external token is mirrored into it, every backoff stage watches it,
        // and abandoning the returned promise fires it. Stages consult exactly
        // one token, and a consumer's cancel() stops the whole cycle instead
        // of silently doing nothing on a Deferred that cannot be cancelled.
        $stop = CancellationSource::new();
        $mirror = null;
        // By-reference captures: the token can only fire after retry() has
        // returned (an already-cancelled token fires synchronously at
        // registration, while these are still null — guarded below), so the
        // mirror always sees the assigned stage promises.
        $core = null;
        $outer = null;
        $coreSettled = false;
        if ($token !== null) {
            // The mirror is dropped the moment the cycle settles or the wait is
            // abandoned (below), so a long-lived external token carries no
            // residue per retry.
            $mirror = $token->onCancel(static function () use ($stop, &$core, &$outer, &$coreSettled): void {
                $stop->cancel();
                // Honour a token fire DURING an attempt: stages consult the
                // stop token between attempts, but a hung driver never regains
                // control to consult anything. Aborting the in-flight promise
                // lets a cooperative driver settle the cycle with its
                // stage-specific rejection; if nothing settled, the visible
                // wait rejects here instead of waiting forever.
                if ($core !== null) {
                    try {
                        $core->cancel();
                    } catch (\Throwable) {
                        // An exploding driver abort still gets the treatment below.
                    }
                }
                if ($outer !== null && $coreSettled === false) {
                    $outer->reject(new OperationCancelledException('Retry cancelled mid-cycle'));
                }
            });
        }

        // One monotonic clock for the whole cycle. hrtime() is immune to
        // wall-clock steps (NTP corrections, manual date changes) that
        // microtime() happily follows — a backward step used to silently
        // stretch or skip the budget.
        $clock = $now ?? static fn(): float => Clock::monotonicSeconds();

        // Convert the relative budget to an absolute cutoff once, so recursive
        // attempts share a single fixed deadline.
        $deadline = $maxTotalSeconds !== null ? $clock() + $maxTotalSeconds : null;

        $core = self::retryAttempt(
            $operation,
            $attempts,
            $baseBackoffSeconds,
            $stop->token(),
            1,
            $loop,
            $jitter,
            $deadline,
            $clock,
            $maxTotalSeconds,
            $onRetry,
        );

        $outer = new Deferred(static function () use ($stop, $core, $mirror): never {
            $stop->cancel();
            try {
                $core->cancel();
            } catch (\Throwable) {
                // A driver whose abort explodes still abandons the wait.
            }
            $mirror?->unsubscribe();
            throw new OperationCancelledException('Async operation cancelled');
        });
        $core->then(
            static function (mixed $value) use ($outer, $mirror, &$coreSettled): void {
                $coreSettled = true;
                $mirror?->unsubscribe();
                $outer->resolve($value);
            },
            static function (\Throwable $error) use ($outer, $mirror, &$coreSettled): void {
                $coreSettled = true;
                $mirror?->unsubscribe();
                $outer->reject($error);
            },
        );

        return $outer->promise();
    }

    /**
     * @internal  Recursive retry implementation.
     *
     * @param LoopInterface|null $loop  Event loop; defaults to Loop::get().
     *        Accepting it (mirroring debounce()/throttle()) lets tests drive
     *        retries with a mock loop instead of the global singleton.
     * @param float $jitter  Fractional backoff randomization; see retry().
     * @param float|null $deadline  Absolute monotonic-clock cutoff, or null
     *        for none.
     * @param callable(): float $now  Monotonic-seconds clock (see retry()).
     * @param float|null $maxTotalSeconds  The budget behind $deadline, for the
     *        exhaustion message.
     * @param null|callable(int, float): void $onRetry  Progress hook; see retry().
     */
    private static function retryAttempt(
        callable $operation,
        int $remaining,
        float $backoff,
        CancellationToken $token,
        int $attempt,
        ?LoopInterface $loop = null,
        float $jitter = 0.0,
        ?float $deadline = null,
        ?callable $now = null,
        ?float $maxTotalSeconds = null,
        ?callable $onRetry = null,
    ): PromiseInterface {
        $loop ??= \React\EventLoop\Loop::get();
        $clock = $now ?? static fn(): float => Clock::monotonicSeconds();

        if ($token->isCancelled()) {
            return reject(new OperationCancelledException('Retry cancelled before attempt ' . $attempt));
        }

        try {
            $operationPromise = $operation();
        } catch (\Throwable $e) {
            $operationPromise = reject($e);
        }
        return $operationPromise->then(
            static fn ($value) => $value,
            static function (\Throwable $e) use ($operation, $remaining, $backoff, $token, $attempt, $loop, $jitter, $deadline, $clock, $maxTotalSeconds, $onRetry): PromiseInterface {
                if ($token->isCancelled()) {
                    return reject(new OperationCancelledException('Retry cancelled after attempt ' . $attempt));
                }

                if ($remaining <= 1) {
                    return reject($e);
                }

                // Wall-clock DoS guard, evaluated BEFORE the next backoff is
                // armed: once the cutoff has passed, stop scheduling and reject
                // with a TimeoutException naming the exhausted budget — the
                // last failure rides along as `previous`, so "gave up after
                // the budget" is distinguishable from "the operation keeps
                // failing" without message archaeology. (Rejecting bare $e
                // here made the two shapes identical.)
                $remainingBudget = $deadline !== null ? $deadline - $clock() : null;
                if ($remainingBudget !== null && $remainingBudget <= 0.0) {
                    return reject(new TimeoutException(
                        'Retry budget of ' . MessageFormat::seconds($maxTotalSeconds ?? 0.0)
                            . ' exhausted after attempt ' . $attempt
                            . ' (last failure: ' . $e->getMessage() . ')',
                        previous: $e,
                        seconds: $maxTotalSeconds,
                    ));
                }

                // Schedule the next attempt with a future tick to avoid blocking
                // the loop. Jitter the delay to avoid a thundering herd; the base
                // still doubles un-jittered to keep the exponential schedule.
                // The budget bounds when a NEW attempt may start: a delay that
                // would overshoot the cutoff is clamped to land exactly on it —
                // never armed past the cutoff with the stop checked only after
                // the failure that the overshoot caused.
                $delay = self::jitteredBackoff($backoff, $jitter);
                if ($remainingBudget !== null && $delay > $remainingBudget) {
                    $delay = $remainingBudget;
                }
                if ($onRetry !== null) {
                    // Reported with the CLAMPED delay so a UI counting down
                    // to the next attempt cannot be fooled by a budget cut.
                    try {
                        $onRetry($attempt, $delay);
                    } catch (\Throwable $hookError) {
                        error_log('sugar-async: retry progress hook failed: ' . $hookError->getMessage());
                    }
                }
                $deferred = new Deferred();

                // Cancellation must be prompt. Without this wiring a cancel
                // landing mid-backoff left the promise pending and the shared
                // loop held for the remaining (doubling) delay. $settled makes
                // the abort and the timer callback mutually exclusive: this
                // registration is deliberately kept for the life of the
                // backoff (the removal handle onCancel() returns is discarded
                // here), so the latch — not an unregister — is what stops an
                // abort queued by a stage that already ran from firing on a
                // later cancel().
                $pending = null;
                $settled = false;

                $token->onCancel(static function () use (&$pending, &$settled, $deferred, $loop, $attempt): void {
                    if ($settled === true) {
                        return;
                    }
                    $settled = true;
                    if ($pending !== null) {
                        $loop->cancelTimer($pending);
                        $pending = null;
                    }
                    $deferred->reject(new OperationCancelledException(
                        'Retry cancelled during backoff after attempt ' . $attempt,
                    ));
                });

                $pending = $loop->addTimer(
                    $delay,
                    static function () use ($operation, $remaining, $backoff, $token, $attempt, $deferred, $loop, $jitter, $deadline, $clock, $maxTotalSeconds, $onRetry, &$settled, &$pending): void {
                        if ($settled === true) {
                            return; // cancelled during backoff; abort already rejected
                        }
                        $settled = true;
                        $pending = null;
                        try {
                            $next = self::retryAttempt($operation, $remaining - 1, $backoff * 2, $token, $attempt + 1, $loop, $jitter, $deadline, $clock, $maxTotalSeconds, $onRetry);
                            $next->then(
                                static fn ($v) => $deferred->resolve($v),
                                static fn ($e) => $deferred->reject($e),
                            );
                        } catch (\Throwable $e) {
                            $deferred->reject($e);
                        }
                    },
                );
                return $deferred->promise();
            },
        );
    }

    /**
     * Apply randomized jitter to a backoff delay, spreading retries to avoid a
     * thundering herd. Returns a delay in [$backoff, $backoff * (1 + $jitter)];
     * with $jitter <= 0.0 the delay is exactly $backoff, keeping the
     * historical no-jitter schedule.
     *
     * Uses random_int() (CSPRNG-backed) rather than mt_rand()/rand() so it does
     * not depend on — or perturb — global mt_srand() seed state.
     */
    private static function jitteredBackoff(float $backoff, float $jitter): float
    {
        if ($jitter <= 0.0) {
            return $backoff;
        }
        $fraction = random_int(0, PHP_INT_MAX) / PHP_INT_MAX;
        return $backoff + ($backoff * $jitter * $fraction);
    }

    /**
     * Accept the lawful (promise, seconds, ..., loop) order and the deprecated
     * loop-first positional order, and hand back the lawful one.
     *
     * @param string $method  Caller name, for messages.
     * @param PromiseInterface|LoopInterface $promise
     * @param mixed $seconds
     * @param mixed $loop
     * @param mixed $onTimeout
     * @param mixed $label
     * @return array{0: PromiseInterface, 1: float, 2: LoopInterface|null, 3: mixed, 4: string|null}
     */
    private static function normalizeWaitArgs(
        string $method,
        PromiseInterface|LoopInterface $promise,
        mixed $seconds,
        mixed $loop,
        mixed $onTimeout,
        mixed $label,
    ): array {
        if ($promise instanceof LoopInterface) {
            // Deprecated positional transpose: (loop, promise, seconds, [hook], [label]).
            trigger_error(
                $method . '(): passing the event loop first is deprecated — the loop is a trailing'
                    . ' optional parameter; call ' . $method . '($promise, $seconds, $loop) instead'
                    . ' (loop-first support ends in 2.0.0).',
                E_USER_DEPRECATED,
            );
            if (!$seconds instanceof PromiseInterface) {
                throw new \InvalidArgumentException(
                    $method . '(): a loop-first call must pass the promise as its second argument.',
                );
            }
            if (!is_numeric($loop)) {
                throw new \InvalidArgumentException(
                    $method . '(): a loop-first call must pass the seconds as its third argument.',
                );
            }
            [$legacyLoop, $realPromise, $realSeconds] = [$promise, $seconds, $loop];
            $promise = $realPromise;
            $seconds = (float) $realSeconds;
            $loop = $legacyLoop;
        }

        if (!$promise instanceof PromiseInterface) {
            throw new \InvalidArgumentException($method . '(): the first argument must be the promise to wrap.');
        }
        if (!is_numeric($seconds)) {
            throw new \InvalidArgumentException($method . '(): the second argument must be the timeout in seconds.');
        }
        if ($loop !== null && !$loop instanceof LoopInterface) {
            throw new \InvalidArgumentException($method . '(): the loop must be an event loop or null.');
        }
        if ($label !== null && !is_string($label)) {
            throw new \InvalidArgumentException($method . '(): the label must be a string or null.');
        }

        return [$promise, (float) $seconds, $loop, $onTimeout, $label];
    }

    /** Message subject: named when the caller passed a label, generic otherwise. */
    private static function subject(?string $label): string
    {
        return $label === null ? 'Operation' : 'Operation "' . $label . '"';
    }

    /** Rejection text for an abandoned or cancelled wait: named when labelled. */
    private static function cancellationMessage(?string $label): string
    {
        return $label === null
            ? 'Async operation cancelled'
            : self::subject($label) . ' cancelled';
    }

    private static function timeoutMessage(?string $label, float $seconds): string
    {
        return self::subject($label) . ' timed out after ' . MessageFormat::seconds($seconds) . ' ' . MessageFormat::unit($seconds);
    }

    private static function deadlineMessage(?string $label, float $seconds): string
    {
        // "N-second deadline": the bound is a point in time the wait gets
        // (a duration elapses; a deadline is exceeded).
        return self::subject($label) . ' exceeded its ' . MessageFormat::seconds($seconds) . '-second deadline';
    }

    /**
     * Fan-in: resolve with every value once ALL promises resolve, preserving
     * keys; reject with the FIRST error the moment one arrives. Non-promise
     * entries are treated as already-resolved values. An empty set resolves
     * with []. This is the library-side promise vocabulary so callers never
     * reach past this package for the primitive.
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
     * Fan-in: settle with whoever gets there first, value or error. The empty
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
     * the notice neither cancels nor abandons the work, and the E646 law
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
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Pending notice seconds must be a positive finite number');
        }

        return self::watchPending($promise, $seconds, $onPending, null, $loop);
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
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Bounded wait seconds must be a positive finite number');
        }

        return self::watchPending($promise, $seconds, null, $label, $loop);
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
        \trigger_error(
            'AsyncOps::onPending(): the dual-contract helper is deprecated — call pendingNotice() with a hook or boundWait() without one (support ends in 2.0.0).',
            \E_USER_DEPRECATED,
        );

        return $onPending !== null
            ? self::pendingNotice($promise, $seconds, $onPending, $loop)
            : self::boundWait($promise, $seconds, $loop, $label);
    }

    /**
     * Shared timer machinery for pendingNotice() and boundWait(): the hook
     * arm announces and keeps waiting, the no-hook arm rejects with a
     * TimeoutException at expiry while the inner runs on.
     *
     * @param null|callable(): void $onPending
     * @return PromiseInterface<mixed>
     */
    private static function watchPending(
        PromiseInterface $promise,
        float $seconds,
        ?callable $onPending,
        ?string $label,
        ?LoopInterface $loop,
    ): PromiseInterface {
        $loop ??= \React\EventLoop\Loop::get();
        $settled = false;
        $timer = null;

        $deferred = new Deferred(static function () use (&$settled, &$timer, $loop, $label): never {
            if ($settled !== true) {
                $settled = true;
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
            }
            throw new OperationCancelledException(self::cancellationMessage($label), label: $label);
        });

        $timer = $loop->addTimer($seconds, static function () use (&$settled, &$timer, $loop, $onPending, $deferred, $seconds, $label): void {
            $timer = null;
            if ($settled === true) {
                return;
            }
            if ($onPending !== null) {
                try {
                    $onPending();
                } catch (\Throwable $noticeError) {
                    error_log('sugar-async: pending notice failed: ' . $noticeError->getMessage());
                }
                return; // advisory: the wait continues
            }
            $settled = true;
            $deferred->reject(new TimeoutException(
                self::timeoutMessage($label, $seconds) . ' (still pending)',
                seconds: $seconds,
                label: $label,
            ));
        });

        $promise->then(
            static function (mixed $value) use (&$settled, &$timer, $loop, $deferred): void {
                if ($settled === true) {
                    return;
                }
                $settled = true;
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
                $deferred->resolve($value);
            },
            static function (\Throwable $error) use (&$settled, &$timer, $loop, $deferred): void {
                if ($settled === true) {
                    return;
                }
                $settled = true;
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
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
     * call fires when the window closes instead.
     *
     * The returned handle is an invokable {@see Throttled} Subscription;
     * unsubscribe() cancels the cooldown and drops any queued trailing call.
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
}
