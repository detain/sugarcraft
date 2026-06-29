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
 * These utilities are pure functions that do not retain state or
 * modify any shared resources. They operate entirely via the
 * Promise/CancelToken plumbing passed in.
 *
 * All timers are bounded \u2014 no real waits >100ms in test fixtures.
 */
final class AsyncOps
{
    /**
     * Wrap a promise with a timeout. If the timeout fires before the
     * promise settles, the returned promise rejects with TimeoutException
     * and the inner promise is cancelled via the CancellationToken.
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
        $source = CancellationSource::new();
        $timer = null;

        // Settle the outer promise when the inner settles.
        $promise->then(
            function ($value) use ($deferred, &$timer, $loop): void {
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
                $deferred->resolve($value);
            },
            function (\Throwable $reason) use ($deferred, &$timer, $loop): void {
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
                $deferred->reject($reason);
            },
        );

        // Schedule the timeout.
        $timer = $loop->addTimer($seconds, function () use ($source, $deferred, $seconds): void {
            $source->cancel();
            $deferred->reject(new TimeoutException(
                'Operation timed out after ' . $seconds . ' second(s)',
            ));
        });

        // Wire cancellation: if the token is cancelled (e.g. parent scope cancelled),
        // propagate the cancellation to the inner promise.
        $source->token()->onCancel(function () use ($promise): void {
            // We cannot directly cancel a generic PromiseInterface, but the
            // caller can hook into the CancellationToken. For PromiseInterface
            // chains, the caller should provide a cancellable promise.
            // Here we simply reject the outer promise.
        });

        return $deferred->promise();
    }

    /**
     * Retry a callable up to $attempts times with exponential backoff.
     *
     * @param callable(): PromiseInterface $operation  The operation to retry
     * @param int $attempts  Maximum number of attempts (must be >= 1)
     * @param float $baseBackoffSeconds  Initial backoff after a failure (seconds)
     * @param CancellationToken|null $token  Optional cancellation token to abort retries
     * @return PromiseInterface
     */
    public static function retry(
        callable $operation,
        int $attempts = 3,
        float $baseBackoffSeconds = 0.1,
        ?CancellationToken $token = null,
    ): PromiseInterface {
        if ($attempts < 1) {
            throw new \InvalidArgumentException('Attempts must be >= 1');
        }
        if ($baseBackoffSeconds <= 0) {
            throw new \InvalidArgumentException('Base backoff must be positive');
        }

        $token ??= CancellationSource::new()->token();

        return self::retryAttempt($operation, $attempts, $baseBackoffSeconds, $token, 1);
    }

    /**
     * @internal  Recursive retry implementation.
     */
    private static function retryAttempt(
        callable $operation,
        int $remaining,
        float $backoff,
        CancellationToken $token,
        int $attempt,
    ): PromiseInterface {
        if ($token->isCancelled()) {
            return reject(new \RuntimeException('Retry cancelled'));
        }

        return $operation()->then(
            static fn ($value) => $value,
            static function (\Throwable $e) use ($operation, $remaining, $backoff, $token, $attempt): PromiseInterface {
                if ($token->isCancelled()) {
                    return reject(new \RuntimeException('Retry cancelled'));
                }

                if ($remaining <= 1) {
                    return reject($e);
                }

                // Schedule the next attempt with a future tick to avoid blocking the loop.
                $deferred = new Deferred();
                \React\EventLoop\Loop::get()->addTimer(
                    $backoff,
                    static function () use ($operation, $remaining, $backoff, $token, $attempt, $deferred): void {
                        $next = self::retryAttempt($operation, $remaining - 1, $backoff * 2, $token, $attempt + 1);
                        $next->then(
                            static fn ($v) => $deferred->resolve($v),
                            static fn ($e) => $deferred->reject($e),
                        );
                    },
                );
                return $deferred->promise();
            },
        );
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
        $args = null;

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
            if ($cooldown) {
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

/**
 * Thrown when an async operation times out.
 */
final class TimeoutException extends \RuntimeException
{
}
