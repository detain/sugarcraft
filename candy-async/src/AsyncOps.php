<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;

/**
 * Static helpers for async operations on top of ReactPHP.
 *
 * withTimeout(), withDeadline(), cancellable() and retry() are stateless
 * helpers. debounce(), throttle() and singleFlight() return stateful closures
 * that retain mutable timer/cooldown/in-flight state across calls.
 *
 * All timers are bounded \u2014 no real waits >100ms in test fixtures.
 */
final class AsyncOps
{
    /**
     * Wrap a promise with a timeout. If the timeout fires before the
     * promise settles, the returned promise rejects with TimeoutException.
     * The inner promise is NOT cancelled and keeps running to completion.
     * When the abandoned work itself must stop — a hung I/O holding its
     * consumer's in-flight gate open forever — use the cancelling sibling
     * {@see withDeadline()} instead.
     *
     * @param LoopInterface $loop
     * @param PromiseInterface $promise
     * @param float $seconds  Timeout in seconds (must be > 0)
     * @return PromiseInterface
     */
    public static function withTimeout(
        LoopInterface $loop,
        PromiseInterface $promise,
        float $seconds,
    ): PromiseInterface {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('Timeout seconds must be positive');
        }

        $deferred = new Deferred();

        // Arm the timeout BEFORE attaching the settle handlers. react/promise
        // v3 settles an already-settled inner synchronously inside then(), so
        // a handler attached first would see a not-yet-assigned timer, skip
        // the cancel, and leak the armed timer onto the (shared) loop for its
        // full duration. Armed first, the timer is always in hand when the
        // handler runs — pre-settled inners cancel it in the same synchronous
        // pass, and cancelTimer on an already-fired one-shot is a no-op.
        $timer = $loop->addTimer($seconds, static function () use ($deferred, $seconds): void {
            $deferred->reject(new TimeoutException(
                'Operation timed out after ' . $seconds . ' second(s)',
            ));
        });

        // Settle the outer promise when the inner settles.
        $promise->then(
            function ($value) use ($deferred, $timer, $loop): void {
                $loop->cancelTimer($timer);
                $deferred->resolve($value);
            },
            function (\Throwable $reason) use ($deferred, $timer, $loop): void {
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
     * E646 semantics: a deadline is a bounded I/O ceiling computed on the
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
     * @param LoopInterface $loop
     * @param PromiseInterface $promise
     * @param float $seconds  Deadline in seconds (must be > 0)
     * @param callable(): void|null $onTimeout  Abort hook, fired once when the
     *        deadline hits; errors are swallowed so a failed kill cannot mask
     *        the timeout rejection.
     * @return PromiseInterface
     */
    public static function withDeadline(
        LoopInterface $loop,
        PromiseInterface $promise,
        float $seconds,
        ?callable $onTimeout = null,
    ): PromiseInterface {
        if ($seconds <= 0) {
            throw new \InvalidArgumentException('Deadline seconds must be positive');
        }

        $deferred = new Deferred();
        $settled = false;

        $timer = $loop->addTimer($seconds, function () use (&$settled, $deferred, $promise, $seconds, $onTimeout): void {
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
                'Operation exceeded deadline of ' . $seconds . ' second(s)',
            ));

            // Cancel AFTER settling the caller-facing promise: the caller's
            // truth is the timeout, and a driver whose cancel() re-settles the
            // inner must not reach them through the guards below.
            $promise->cancel();
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
     * Extracted from the candy-query CancellableQuery pattern.
     *
     * Note: {@see CancellationToken} has no callback unregistration, so a
     * token reused across many operations accumulates one (settled-guarded,
     * cheap) closure per operation until it fires. Prefer one token per
     * logical operation.
     *
     * @template T
     * @param PromiseInterface<T> $promise  The in-flight operation
     * @param CancellationToken|null $token  Cancel handle; null returns the promise untouched
     * @param callable(): void|null $onCancel  Underlying-work abort hook (fired once, errors swallowed)
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
            $promise->cancel();
        };

        $deferred = new Deferred(static function () use ($abort): never {
            $abort();
            throw new OperationCancelledException('Async operation cancelled');
        });

        // onCancel fires synchronously when the token is already cancelled,
        // so a pre-cancelled token rejects before the result can land.
        $token->onCancel(static function () use (&$settled, $abort, $deferred): void {
            if ($settled === true) {
                return;
            }
            $abort();
            $deferred->reject(new OperationCancelledException('Async operation cancelled'));
        });

        $promise->then(
            static function (mixed $value) use (&$settled, $deferred): void {
                if ($settled !== true) {
                    $settled = true;
                    $deferred->resolve($value);
                }
            },
            static function (\Throwable $e) use (&$settled, $deferred): void {
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
     * Extracted from the phlix ApiClient refreshInFlight pattern; rides the
     * same InFlightMap mechanism that backs AsyncCache's loader dedupe.
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
            // when it does — the phlix ordering law.
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
     * Retry a callable up to $attempts times with exponential backoff.
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
     *        attempts; once exceeded, retry aborts with the last failure. Null
     *        (default) imposes no cap.
     * @param float $jitter  Fractional randomization of each backoff in
     *        [0, $jitter]; the delay lands in [backoff, backoff*(1+$jitter)].
     *        0.0 (default) means no jitter (exactly the base backoff).
     * @param LoopInterface|null $loop  Optional event loop; uses Loop::get() if null.
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
    ): PromiseInterface {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('Attempts must be >= 1');
        }
        if ($baseBackoffSeconds <= 0) {
            throw new \InvalidArgumentException('Base backoff must be positive');
        }
        if ($maxTotalSeconds !== null && $maxTotalSeconds <= 0) {
            throw new \InvalidArgumentException('Max total seconds must be positive when set');
        }
        if ($jitter < 0) {
            throw new \InvalidArgumentException('Jitter must be >= 0');
        }

        $token ??= CancellationSource::new()->token();

        // Convert the relative budget to an absolute wall-clock deadline once,
        // so recursive attempts share a single fixed cutoff.
        $deadline = $maxTotalSeconds !== null ? microtime(true) + $maxTotalSeconds : null;

        return self::retryAttempt($operation, $attempts, $baseBackoffSeconds, $token, 1, $loop, $jitter, $deadline);
    }

    /**
     * @internal  Recursive retry implementation.
     *
     * @param LoopInterface|null $loop  Event loop; defaults to Loop::get().
     *        Accepting it (mirroring debounce()/throttle()) lets tests drive
     *        retries with a mock loop instead of the global singleton.
     * @param float $jitter  Fractional backoff randomization; see retry().
     * @param float|null $deadline  Absolute microtime() cutoff, or null for none.
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
    ): PromiseInterface {
        $loop ??= \React\EventLoop\Loop::get();

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
            static function (\Throwable $e) use ($operation, $remaining, $backoff, $token, $attempt, $loop, $jitter, $deadline): PromiseInterface {
                if ($token->isCancelled()) {
                    return reject(new OperationCancelledException('Retry cancelled after attempt ' . $attempt));
                }

                if ($remaining <= 1) {
                    return reject($e);
                }

                // Wall-clock DoS guard: stop retrying once the total budget is
                // spent, even if attempts remain. Reject with the last failure.
                if ($deadline !== null && microtime(true) >= $deadline) {
                    return reject($e);
                }

                // Schedule the next attempt with a future tick to avoid blocking
                // the loop. Jitter the delay to avoid a thundering herd; the base
                // still doubles un-jittered to keep the exponential schedule.
                $delay = self::jitteredBackoff($backoff, $jitter);
                $deferred = new Deferred();

                // Cancellation must be prompt. Without this wiring a cancel
                // landing mid-backoff left the promise pending and the shared
                // loop held for the remaining (doubling) delay. $settled makes
                // the abort and the timer callback mutually exclusive; it also
                // disarms a stale registration, because the token offers no
                // unregister — an abort queued by a stage that already ran
                // must never fire on a later cancel().
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
                    static function () use ($operation, $remaining, $backoff, $token, $attempt, $deferred, $loop, $jitter, $deadline, &$settled, &$pending): void {
                        if ($settled === true) {
                            return; // cancelled during backoff; abort already rejected
                        }
                        $settled = true;
                        $pending = null;
                        try {
                            $next = self::retryAttempt($operation, $remaining - 1, $backoff * 2, $token, $attempt + 1, $loop, $jitter, $deadline);
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
     * with $jitter <= 0.0 the delay is exactly $backoff (BC default).
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
     * Wrap a callable with debounce \u2014 only the last call within the
     * window fires, and only after $seconds have elapsed since the last call.
     *
     * @param callable(mixed...): void $fn  The function to debounce
     * @param float $seconds  Debounce window (seconds)
     * @param LoopInterface|null $loop  Optional loop; uses Loop::get() if null
     * @return callable(mixed...): void  The debounced wrapper
     */
    public static function debounce(
        callable $fn,
        float $seconds,
        ?LoopInterface $loop = null,
    ): callable {
        $loop ??= \React\EventLoop\Loop::get();
        $timer = null;

        return static function (...$args) use ($fn, $seconds, $loop, &$timer): void {
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            $timer = $loop->addTimer($seconds, static function () use ($fn, $args): void {
                $fn(...$args);
            });
        };
    }

    /**
     * Wrap a callable with throttle \u2014 the function fires at most once
     * every $seconds, regardless of call frequency.
     *
     * @param callable(mixed...): void $fn  The function to throttle
     * @param float $seconds  Minimum interval between calls (seconds)
     * @param LoopInterface|null $loop  Optional loop; uses Loop::get() if null
     * @return callable(mixed...): void  The throttled wrapper
     */
    public static function throttle(
        callable $fn,
        float $seconds,
        ?LoopInterface $loop = null,
    ): callable {
        $loop ??= \React\EventLoop\Loop::get();
        $cooldown = false;

        return static function (...$args) use ($fn, $seconds, $loop, &$cooldown): void {
            if ($cooldown === true) {
                return;
            }
            $cooldown = true;
            $fn(...$args);
            $loop->addTimer($seconds, static function () use (&$cooldown): void {
                $cooldown = false;
            });
        };
    }
}
