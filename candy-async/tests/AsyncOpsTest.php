<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\TimeoutException;

/**
 * @covers \SugarCraft\Async\AsyncOps
 */
final class AsyncOpsTest extends TestCase
{
    public function testWithTimeoutRejectsOnTimeout(): void
    {
        $loop = Loop::get();

        // Create a promise that never resolves
        $deferred = new Deferred();
        $promise = $deferred->promise();

        $wrapped = AsyncOps::withTimeout($loop, $promise, 0.05);

        $rejected = null;
        $wrapped->otherwise(function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });

        // Run the loop for 100ms to let the timeout fire
        $loop->addTimer(0.1, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertInstanceOf(TimeoutException::class, $rejected);
    }

    public function testWithTimeoutResolvesBeforeTimeout(): void
    {
        $loop = Loop::get();

        $deferred = new Deferred();
        $promise = $deferred->promise();

        $wrapped = AsyncOps::withTimeout($loop, $promise, 5.0);

        // Resolve immediately
        $deferred->resolve('success');

        $resolved = null;
        $wrapped->then(function ($value) use (&$resolved): void {
            $resolved = $value;
        });

        $loop->addTimer(0.05, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertSame('success', $resolved);
    }

    public function testWithTimeoutRejectsWhenPromiseRejects(): void
    {
        $loop = Loop::get();

        $deferred = new Deferred();
        $promise = $deferred->promise();

        $wrapped = AsyncOps::withTimeout($loop, $promise, 5.0);

        $deferred->reject(new \RuntimeException('fail'));

        $rejected = null;
        $wrapped->otherwise(function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });

        $loop->addTimer(0.05, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertSame('fail', $rejected->getMessage());
    }

    public function testWithTimeoutThrowsOnZeroSeconds(): void
    {
        $loop = Loop::get();
        $deferred = new Deferred();

        $this->expectException(\InvalidArgumentException::class);
        AsyncOps::withTimeout($loop, $deferred->promise(), 0.0);
    }

    public function testDebounceOnlyLastCallFires(): void
    {
        $loop = Loop::get();
        $calls = [];

        $debounced = AsyncOps::debounce(
            function (...$args) use (&$calls): void {
                $calls[] = $args;
            },
            0.05,
            $loop,
        );

        $debounced('first');
        $debounced('second');
        $debounced('third');

        // No calls yet
        $this->assertSame([], $calls);

        // After window, only last fires
        $loop->addTimer(0.1, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertCount(1, $calls);
        $this->assertSame(['third'], $calls[0]);
    }

    public function testThrottleLimitsCallFrequency(): void
    {
        $loop = Loop::get();
        $calls = 0;

        $throttled = AsyncOps::throttle(
            function () use (&$calls): void {
                $calls++;
            },
            0.05,
            $loop,
        );

        $throttled();
        $throttled();
        $throttled();

        // Only first fires
        $this->assertSame(1, $calls);

        // After cooldown, next call fires
        $loop->addTimer(0.06, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        // No new calls since no explicit call was made after cooldown
        $this->assertSame(1, $calls);
    }

    public function testRetrySucceedsOnFirstAttempt(): void
    {
        $loop = Loop::get();
        $attempts = 0;

        $promise = AsyncOps::retry(
            function () use (&$attempts): PromiseInterface {
                $attempts++;
                return \React\Promise\resolve('ok');
            },
            attempts: 3,
            baseBackoffSeconds: 0.01,
        );

        $result = null;
        $promise->then(function ($v) use (&$result, $loop): void {
            $result = $v;
            $loop->stop();
        });

        $loop->addTimer(0.1, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertSame('ok', $result);
        $this->assertSame(1, $attempts);
    }

    public function testRetryRetriesOnFailure(): void
    {
        $loop = Loop::get();
        $attempts = 0;

        $promise = AsyncOps::retry(
            function () use (&$attempts): PromiseInterface {
                $attempts++;
                if ($attempts < 3) {
                    return \React\Promise\reject(new \RuntimeException("attempt $attempts fail"));
                }
                return \React\Promise\resolve('success');
            },
            attempts: 3,
            baseBackoffSeconds: 0.01,
        );

        $result = null;
        $promise->then(function ($v) use (&$result, $loop): void {
            $result = $v;
            $loop->stop();
        });

        $loop->addTimer(0.5, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertSame('success', $result);
        $this->assertSame(3, $attempts);
    }

    public function testRetryFailsAfterMaxAttempts(): void
    {
        $loop = Loop::get();
        $attempts = 0;

        $promise = AsyncOps::retry(
            function () use (&$attempts): PromiseInterface {
                $attempts++;
                return \React\Promise\reject(new \RuntimeException("fail $attempts"));
            },
            attempts: 3,
            baseBackoffSeconds: 0.01,
        );

        $rejected = null;
        $promise->otherwise(function (\Throwable $e) use (&$rejected, $loop): void {
            $rejected = $e;
            $loop->stop();
        });

        $loop->addTimer(0.5, function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertSame('fail 3', $rejected->getMessage());
        $this->assertSame(3, $attempts);
    }

    public function testRetryThrowsOnInvalidAttempts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AsyncOps::retry(
            fn() => \React\Promise\resolve('ok'),
            attempts: 0,
        );
    }

    public function testRetryThrowsOnInvalidBackoff(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AsyncOps::retry(
            fn() => \React\Promise\resolve('ok'),
            attempts: 3,
            baseBackoffSeconds: 0.0,
        );
    }

    public function testRetryDoesNotImposePerAttemptTimeout(): void
    {
        // A slow healthy operation (60ms) must NOT be force-failed by the
        // retry logic. With baseBackoffSeconds=0.01 (10ms), the old code's
        // backoff*2=20ms deadline would have killed this before it completed.
        // After Step 6, retry() imposes no per-attempt timeout.
        $loop = Loop::get();
        $attempts = 0;
        $deferred = new Deferred();

        $promise = AsyncOps::retry(
            function () use (&$attempts, $deferred): PromiseInterface {
                $attempts++;
                // Return a promise that resolves after 60ms
                return $deferred->promise();
            },
            attempts: 3,
            baseBackoffSeconds: 0.01,
        );

        $result = null;
        $promise->then(function ($v) use (&$result, $loop): void {
            $result = $v;
            $loop->stop();
        });

        // Resolve the deferred after 60ms (slow operation)
        $loop->addTimer(0.06, static function () use ($deferred): void {
            $deferred->resolve('slow_success');
        });

        // Run for 150ms total (enough for the 60ms op + loop overhead)
        $loop->addTimer(0.15, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertSame('slow_success', $result);
        $this->assertSame(1, $attempts);
    }
}
