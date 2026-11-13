<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * The seats around {@see Backoff} that actually wait: a budgeted synchronous
 * poll ({@see bounded()}), the deadline-resume nap ({@see nap()}), and the
 * loop-driven redial ({@see reconnect()}).
 */
final class Retry
{
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
     * timer is released, an in-flight dial is cancel()ed) and rejects with
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
                    $delayMs = $backoff->forAttempt($n);
                    if ($onRetry !== null) {
                        try {
                            $onRetry($n, $delayMs, $failure);
                        } catch (\Throwable) {
                            // Advisory hook.
                        }
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
}
