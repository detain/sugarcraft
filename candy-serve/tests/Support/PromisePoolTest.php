<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Support;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use SugarCraft\Serve\Support\PromisePool;

/**
 * @covers \SugarCraft\Serve\Support\PromisePool
 */
final class PromisePoolTest extends TestCase
{
    public function testEmptyItemsResolveImmediately(): void
    {
        $loop = new StreamSelectLoop();

        $result = null;
        PromisePool::map($loop, [], static fn ($x) => $x)
            ->then(static function (array $r) use (&$result): void {
                $result = $r;
            });

        $this->assertSame([], $result);
    }

    public function testResultsPreserveInputOrderForSyncTasks(): void
    {
        $loop = new StreamSelectLoop();

        $result = null;
        PromisePool::map($loop, [1, 2, 3, 4, 5], static fn (int $x): int => $x * 2, 2)
            ->then(static function (array $r) use (&$result): void {
                $result = $r;
            });
        $loop->run();

        $this->assertSame([2, 4, 6, 8, 10], $result);
    }

    public function testExecutionOrderFollowsInputOrderWithLimitOne(): void
    {
        $loop = new StreamSelectLoop();

        $order = [];
        PromisePool::map($loop, ['a', 'b', 'c'], static function (string $x) use (&$order): string {
            $order[] = $x;

            return $x;
        }, 1)->then(static fn () => null);
        $loop->run();

        $this->assertSame(['a', 'b', 'c'], $order);
    }

    /**
     * The bound is meaningful for tasks that return pending promises: a
     * slot is held from launch until the promise settles. Instrumented
     * counter proves at most $limit tasks overlap — and that the pool
     * actually fills all $limit slots.
     */
    public function testBoundedConcurrencyRespected(): void
    {
        $loop = new StreamSelectLoop();

        $inFlight = 0;
        $maxInFlight = 0;
        $started = 0;

        $fn = static function (int $item) use (&$inFlight, &$maxInFlight, &$started, $loop) {
            $started++;
            $inFlight++;
            $maxInFlight = \max($maxInFlight, $inFlight);

            $deferred = new Deferred();
            $loop->addTimer(0.01 * $started, static function () use ($deferred, &$inFlight, $item): void {
                $inFlight--;
                $deferred->resolve($item);
            });

            return $deferred->promise();
        };

        $result = null;
        PromisePool::map($loop, \range(1, 7), $fn, 3)
            ->then(static function (array $r) use (&$result): void {
                $result = $r;
            });
        $loop->run();

        $this->assertSame(\range(1, 7), $result);
        $this->assertSame(7, $started);
        $this->assertSame(3, $maxInFlight, 'pool must fill exactly $limit slots, never more');
    }

    public function testSynchronousThrowRejectsAggregate(): void
    {
        $loop = new StreamSelectLoop();

        $error = null;
        PromisePool::map($loop, [1, 2, 3], static function (int $x): int {
            if ($x === 2) {
                throw new \RuntimeException('task 2 failed');
            }

            return $x;
        }, 2)->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $loop->run();

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('task 2 failed', $error->getMessage());
    }

    public function testRejectedTaskPromiseRejectsAggregate(): void
    {
        $loop = new StreamSelectLoop();

        $error = null;
        PromisePool::map($loop, [1, 2], static function (int $x) {
            return $x === 1
                ? \React\Promise\reject(new \LogicException('boom'))
                : $x;
        }, 2)->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $loop->run();

        $this->assertInstanceOf(\LogicException::class, $error);
        $this->assertSame('boom', $error->getMessage());
    }

    public function testLimitBelowOneIsClampedToOne(): void
    {
        $loop = new StreamSelectLoop();

        $result = null;
        PromisePool::map($loop, [1, 2, 3], static fn (int $x): int => $x + 10, 0)
            ->then(static function (array $r) use (&$result): void {
                $result = $r;
            });
        $loop->run();

        $this->assertSame([11, 12, 13], $result);
    }
}
