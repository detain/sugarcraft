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
 * withTimeout(), withDeadline(), cancellable(), retry(), all(), race() and
 * onPending() are stateless helpers. debounce() and throttle() return
 * invokable Subscription handles that retain mutable timer/cooldown state
 * across calls; singleFlight() returns a stateful coalescing closure.
 *
 * Nothing here blocks the caller: every wait is expressed as a promise,
 * and every timer is armed on a supplied or shared event loop.
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
     * When the abandoned work itself must stop — a hung I/O holding its
     * consumer's in-flight gate open forever — use the cancelling sibling
     * {@see withDeadline()} instead.
     *
     * @param null|LoopInterface $loop  Event loop; pass null to use the shared Loop::get().
     * @param PromiseInterface $promise
     * @param float $seconds  Timeout in seconds (must be > 0 and finite)
     * @param string|null $label  Operation name woven into the rejection
     *        message and carried on {@see TimeoutException::label()} — a TUI
     *        catching one TimeoutException among several wrapped calls needs
     *        the identity as data, not archaeology through the stack trace.
     * @return PromiseInterface
     */
    public static function withTimeout(
        ?LoopInterface $loop,
        PromiseInterface $promise,
        float $seconds,
        ?string $label = null,
    ): PromiseInterface {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Timeout seconds must be a positive finite number');
        }

        $loop ??= Loop::get();

        $settled = false;
        /** @var \React\EventLoop\TimerInterface|null */
        $timer = null;

        $deferred = new Deferred(static function () use (&$settled, $loop, &$timer): never {
            $settled = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            throw new OperationCancelledException('Async operation cancelled');
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
     * fires — the cancelling sibling of {@see withTimeout()}, which lets the
     * inner run on.
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
     * @param null|LoopInterface $loop  Event loop; pass null to use the shared Loop::get().
     * @param PromiseInterface $promise
     * @param float $seconds  Deadline in seconds (must be > 0 and finite)
     * @param null|callable(): void $onTimeout  Abort hook, fired once when the
     *        deadline hits; errors are swallowed so a failed kill cannot mask
     *        the timeout rejection.
     * @param string|null $label  Operation name for the rejection message and
     *        {@see TimeoutException::label()}; see withTimeout().
     * @return PromiseInterface
     */
    public static function withDeadline(
        ?LoopInterface $loop,
        PromiseInterface $promise,
        float $seconds,
        ?callable $onTimeout = null,
        ?string $label = null,
    ): PromiseInterface {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Deadline seconds must be a positive finite number');
        }

        $loop ??= Loop::get();

        $settled = false;
        /** @var \React\EventLoop\TimerInterface|null */
        $timer = null;

        $deferred = new Deferred(function () use (&$settled, $loop, &$timer, $promise, $onTimeout): never {
            $settled = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            if ($onTimeout !== null) {
                try {
                    $onTimeout();
                } catch (\Throwable) {
                    // Same law as the deadline path: a failed kill is not the
                    // caller's truth.
                }
            }
            try {
                $promise->cancel();
            } catch (\Throwable) {
                // The abandonment rejection below stands regardless.
            }
            throw new OperationCancelledException('Async operation cancelled');
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
     * unsubscribes its own registration the moment the operation settles, and
     * onCancel() hands back a removal handle for consumer code to do the same.
     *
     * @template T
     * @param PromiseInterface<T> $promise  The in-flight operation
     * @param CancellationToken|null $token  Cancel handle; null returns the promise untouched
     * @param null|callable(): void $onCancel  Underlying-work abort hook (fired once, errors swallowed)
     * @return PromiseInterface<T>
     */
    public static function cancellable(
        PromiseInterface $promise,
        ?CancellationToken $token,
        ?callable $onCancel = null,
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

        $deferred = new Deferred(static function () use ($abort): never {
            $abort();
            throw new OperationCancelledException('Async operation cancelled');
        });

        // onCancel fires synchronously when the token is already cancelled,
        // so a pre-cancelled token rejects before the result can land.
        $registration = $token->onCancel(static function () use (&$settled, $abort, $deferred): void {
            if ($settled === true) {
                return;
            }
            $abort();
            $deferred->reject(new OperationCancelledException('Async operation cancelled'));
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
     * worker itself ({ withDeadline()}/{ onPending()}) when the loader
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
     * OperationCancelledException.
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
     * @param CancellationToken|null $token  Optional cancellation token to abort retries
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
        // of silently no-opping on a canceller-less Deferred.
        $stop = CancellationSource::new();
        $mirror = null;
        if ($token !== null) {
            // The mirror is dropped as soon as the cycle settles (below), so a
            // long-lived external token carries no residue per retry.
            $mirror = $token->onCancel(static function () use ($stop): void {
                $stop->cancel();
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

        $outer = new Deferred(static function () use ($stop, $core): never {
            $stop->cancel();
            try {
                $core->cancel();
            } catch (\Throwable) {
                // A driver whose abort explodes still abandons the wait.
            }
            throw new OperationCancelledException('Async operation cancelled');
        });
        $core->then(
            static function (mixed $value) use ($outer, $mirror): void {
                $mirror?->unsubscribe();
                $outer->resolve($value);
            },
            static function (\Throwable $error) use ($outer, $mirror): void {
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

    /** Message subject: named when the caller passed a label, generic otherwise. */
    private static function subject(?string $label): string
    {
        return $label === null ? 'Operation' : 'Operation "' . $label . '"';
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
     * Watch a promise for slowness. While $promise is still pending after
     * $seconds, the $onPending notice runs ONCE (a "taking longer than
     * expected" spinner hint). The returned promise tracks the inner one
     * either way — the notice neither cancels nor abandons the work, and the
     * E646 law holds: this is an advisory tick, never a wall-clock killer of
     * user-paced operations. With $onPending null the wait itself is bounded:
     * the returned promise rejects with a TimeoutException (still pending)
     * instead, and the inner keeps running like under withTimeout().
     *
     * @param null|callable(): void $onPending  Advisory notice; throws are logged, never propagated
     * @return PromiseInterface<mixed>
     */
    public static function onPending(
        PromiseInterface $promise,
        float $seconds,
        ?callable $onPending = null,
        ?LoopInterface $loop = null,
        ?string $label = null,
    ): PromiseInterface {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Pending notice seconds must be a positive finite number');
        }

        $loop ??= \React\EventLoop\Loop::get();
        $settled = false;
        $timer = null;

        $deferred = new Deferred(static function () use (&$settled, &$timer, $loop): never {
            if ($settled !== true) {
                $settled = true;
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
            }
            throw new OperationCancelledException('Async operation cancelled');
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
