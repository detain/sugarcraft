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
 * The seats around {@see Backoff} that actually wait: a budgeted synchronous
 * poll ({@see bounded()}), the deadline-resume nap ({@see nap()}), and the
 * loop-driven redial ({@see reconnect()}).
 */
final class Retry
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
    public const MAX_BACKOFF_SECONDS = 3600.0;

    /** Longest single sleep slice {@see nap()} takes when a beat is wired, microseconds. */
    public const BEAT_SLICE_MICROSECONDS = 1_000_000;

    /**
     * Run $operation once, then again after each wait while $shouldRetry says
     * its result is not final and the budget has not passed.
     *
     * Every wait is clamped to what remains of the budget, so the call never
     * overshoots $budgetMs by a tick (an unclamped poll sleeps its full tick
     * past the deadline). The last result is returned whether or not it is
     * final — the caller decides what an exhausted budget means.
     *
     * @template T
     * @param callable(): T $operation
     * @param callable(T): bool $shouldRetry  True when the result wants another try
     * @param int $budgetMs  Wall-clock budget measured from the first call
     * @param Backoff $backoff  Wait schedule; attempt n is the n-th wait
     * @param null|callable(): float $now  Monotonic seconds (test seam)
     * @param null|callable(int): void $sleep  Given microseconds (test seam)
     * @return T
     */
    public static function bounded(
        callable $operation,
        callable $shouldRetry,
        int $budgetMs,
        Backoff $backoff,
        ?callable $now = null,
        ?callable $sleep = null,
    ): mixed {
        if ($budgetMs < 0) {
            throw new \InvalidArgumentException('Budget must be >= 0 ms');
        }
        $now ??= Clock::monotonicSeconds(...);
        $sleep ??= static function (int $micros): void {
            usleep($micros);
        };

        $deadline = $now() + $budgetMs / 1000;
        $result = $operation();
        for ($n = 1; $shouldRetry($result) && $backoff->allows($n); $n++) {
            $remaining = $deadline - $now();
            if ($remaining <= 0.0) {
                break;
            }
            $micros = min($backoff->forAttempt($n) * 1000, (int) ceil($remaining * 1_000_000));
            if ($micros > 0) {
                $sleep($micros);
            }
            $result = $operation();
        }

        return $result;
    }

    /**
     * Sleep $owedMicros against a DEADLINE, not one usleep(): a usleep() is a
     * wait until the next signal (EINTR), so it is re-armed from the clock
     * until the debt is paid. A handler that takes time is paid out of the
     * wait instead of added to it.
     *
     * @param int $owedMicros  Debt, microseconds; <= 0 returns at once
     * @param null|callable(int): void $sleeper  Given microseconds owed this slice
     * @param null|callable(): float $now  Seconds (wall or monotonic — only
     *        differences are read); defaults to the monotonic clock
     * @param null|callable(): void $onBeat  Called between slices of at most
     *        {@see BEAT_SLICE_MICROSECONDS} so a waiter can heartbeat during a
     *        long silence. Fail-soft: a throw never costs the wait its sleep.
     *        Without one the first slice is the whole remaining debt.
     */
    public static function nap(
        int $owedMicros,
        ?callable $sleeper = null,
        ?callable $now = null,
        ?callable $onBeat = null,
    ): void {
        if ($owedMicros <= 0) {
            return;
        }
        $now ??= Clock::monotonicSeconds(...);
        $sleeper ??= static function (int $micros): void {
            usleep($micros);
        };

        $deadline = $now() + $owedMicros / 1_000_000;
        while (true) {
            $remaining = $deadline - $now();
            if ($remaining <= 0.0) {
                return;
            }
            // ceil so a sub-microsecond remainder still asks for one and the
            // loop cannot spin on a value that rounds to zero.
            $micros = (int) ceil($remaining * 1_000_000);
            if ($onBeat !== null && $micros > self::BEAT_SLICE_MICROSECONDS) {
                $micros = self::BEAT_SLICE_MICROSECONDS;
            }
            $sleeper($micros);
            if ($onBeat === null) {
                continue;
            }
            try {
                $onBeat();
            } catch (\Throwable) {
                // The sleep is the payload; the beat is advisory.
            }
        }
    }

    /**
     * Redial until $dial succeeds. Each failure waits `$backoff->forAttempt(n)`
     * and tries again; with `$backoff->withAttempts(null)` (the default) that
     * is forever, until the token fires. A bounded Backoff rejects with the
     * last failure once {@see Backoff::allows()} says no.
     *
     * Success resolves the promise and ENDS the cycle: the "success resets the
     * schedule" law is that the next drop calls reconnect() again and starts
     * at attempt 1, so no counter outlives a connection.
     *
     * Cancelling the returned promise, or firing $token, stops the cycle (the
     * timer is released, an in-flight dial is cancelled) and rejects with
     * {@see OperationCancelledException}.
     *
     * @param callable(): PromiseInterface $dial  One attempt; a throw counts as a failure
     * @param null|callable(int, int, \Throwable): void $onRetry  (failedAttempt,
     *        plannedDelayMs, failure) at scheduling time. Advisory: throws are swallowed.
     */
    public static function reconnect(
        callable $dial,
        Backoff $backoff,
        ?CancellationToken $token = null,
        ?LoopInterface $loop = null,
        ?callable $onRetry = null,
    ): PromiseInterface {
        $loop ??= Loop::get();
        $timer = null;
        $pending = null;
        $done = false;
        $registration = null;
        $deferred = new Deferred(static function () use (&$done, &$timer, &$pending, $loop): never {
            $done = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            $pending?->cancel();
            throw new OperationCancelledException('Reconnect cancelled');
        });

        $finish = static function () use (&$done, &$timer, &$registration, $loop): void {
            $done = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
                $timer = null;
            }
            $registration?->unsubscribe();
        };

        $attempt = null;
        $attempt = static function (int $n) use (&$attempt, &$done, &$timer, &$pending, $dial, $backoff, $deferred, $loop, $onRetry, $finish): void {
            if ($done) {
                return;
            }
            $timer = null;
            try {
                $promise = $dial();
            } catch (\Throwable $e) {
                $promise = \React\Promise\reject($e);
            }
            // Same guard retryAttempt() uses: a non-promise is refused by name and
            // is terminal (a caller bug redialled down the ladder only burns the
            // budget). Without it, ->then() on a bare value is an Error raised
            // inside a loop timer, where it escapes $loop->run().
            if (!$promise instanceof PromiseInterface) {
                $finish();
                $deferred->reject(new \InvalidArgumentException(
                    'Reconnect dial must return a promise; ' . get_debug_type($promise) . ' returned.',
                ));

                return;
            }
            $pending = $promise;
            $promise->then(
                static function (mixed $value) use (&$done, $deferred, $finish): void {
                    if ($done) {
                        return;
                    }
                    $finish();
                    $deferred->resolve($value);
                },
                static function (\Throwable $failure) use (&$attempt, &$done, &$timer, $n, $backoff, $deferred, $loop, $onRetry, $finish): void {
                    if ($done) {
                        return;
                    }
                    if (!$backoff->allows($n)) {
                        $finish();
                        $deferred->reject($failure);

                        return;
                    }
                    // The ladder plateau retry() applies: an uncapped Backoff would
                    // otherwise arm a redial timer days out. Reported clamped too.
                    $delayMs = min($backoff->forAttempt($n), (int) (self::MAX_BACKOFF_SECONDS * 1000));
                    if ($onRetry !== null) {
                        try {
                            $onRetry($n, $delayMs, $failure);
                        } catch (\Throwable) {
                            // Advisory hook.
                        }
                    }
                    // The hook may have cancelled the token: the registration above
                    // has then already settled the promise and ran with $timer null,
                    // so a timer armed now could never be cancelled and would keep
                    // the loop alive for the whole backoff.
                    if ($done) {
                        return;
                    }
                    $timer = $loop->addTimer($delayMs / 1000, static function () use (&$attempt, $n): void {
                        $attempt($n + 1);
                    });
                },
            );
        };

        if ($token !== null) {
            $registration = $token->onCancel(static function () use (&$done, &$timer, &$pending, $deferred, $loop): void {
                if ($done) {
                    return;
                }
                $done = true;
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                }
                $pending?->cancel();
                $deferred->reject(new OperationCancelledException('Reconnect cancelled'));
            });
        }

        $attempt(1);

        return $deferred->promise();
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
        $loop ??= Loop::get();
        $clock = $now ?? static fn(): float => Clock::monotonicSeconds();

        if ($token->isCancelled()) {
            return reject(new OperationCancelledException('Retry cancelled before attempt ' . $attempt));
        }

        try {
            $operationPromise = $operation();
        } catch (\Throwable $e) {
            $operationPromise = reject($e);
        }
        if (!$operationPromise instanceof PromiseInterface) {
            // One guard vocabulary across the package (mirrors
            // AsyncCache::fetch): a non-promise is refused by name, not by
            // an obscure member-access failure two frames away. The refusal
            // is terminal — a deterministic caller bug retried down the
            // ladder just burns the budget — and travels the same rejection
            // channel as the operation's own failure would.
            return reject(new \InvalidArgumentException('AsyncOps retry operation must return a promise; ' . get_debug_type($operationPromise) . ' returned.'));
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
                if ($delay > self::MAX_BACKOFF_SECONDS) {
                    // Ladder plateau (see MAX_BACKOFF_SECONDS): clamp before
                    // the budget so the reported and armed delay carry both
                    // bounds — the doubling stops at the horizon, the cycle
                    // still ends via the attempt cap or $maxTotalSeconds.
                    $delay = self::MAX_BACKOFF_SECONDS;
                }
                if ($remainingBudget !== null && $delay > $remainingBudget) {
                    $delay = $remainingBudget;
                }
                if ($onRetry !== null) {
                    // Reported with the CLAMPED delay so a UI counting down
                    // to the next attempt cannot be fooled by a budget cut.
                    try {
                        $onRetry($attempt, $delay);
                    } catch (\Throwable $hookError) {
                        AsyncOps::logFailure('sugar-async: retry progress hook failed: ' . $hookError->getMessage());
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

                // The progress hook may have cancelled the token. The listener above
                // then fired at once with $pending still null and rejected $deferred,
                // so no timer may be armed: nothing could cancel it afterwards.
                if ($settled === true) {
                    return $deferred->promise();
                }

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
}
