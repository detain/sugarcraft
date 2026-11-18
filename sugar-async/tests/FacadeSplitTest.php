<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Await;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\Pending;
use SugarCraft\Async\Retry;
use SugarCraft\Async\Timeouts;
use SugarCraft\Async\TimeoutException;
use function React\Promise\resolve;

/**
 * AsyncOps forwards its timeout, pending and retry names to the classes they
 * were split into. Each split class is exercised directly here; the forwarding
 * names are covered by the suites that call AsyncOps.
 */
final class FacadeSplitTest extends TestCase
{
    public function testTimeoutsWithTimeoutResolvesAnEarlyValue(): void
    {
        $loop = new StreamSelectLoop();

        self::assertSame(4, Await::until(Timeouts::withTimeout(resolve(4), 1.0, $loop), 2.0, $loop));
    }

    public function testTimeoutsWithDeadlineRejectsWhenTheWorkOverruns(): void
    {
        $loop = new StreamSelectLoop();
        $inner = new \React\Promise\Deferred();

        $this->expectException(TimeoutException::class);
        Await::until(Timeouts::withDeadline($inner->promise(), 0.02, $loop), 2.0, $loop);
    }

    public function testPendingBoundWaitRejectsWithATypedTimeout(): void
    {
        $loop = new StreamSelectLoop();
        $inner = new \React\Promise\Deferred();

        $this->expectException(TimeoutException::class);
        Await::until(Pending::boundWait($inner->promise(), 0.02, $loop), 2.0, $loop);
    }

    public function testRetryRetryResolvesAfterATransientFailure(): void
    {
        $loop = new StreamSelectLoop();
        $calls = 0;
        $promise = Retry::retry(static function () use (&$calls) {
            $calls++;
            if ($calls < 2) {
                return \React\Promise\reject(new \RuntimeException('transient'));
            }

            return resolve('ok');
        }, 3, 0.001, null, null, 0.0, $loop);

        self::assertSame('ok', Await::until($promise, 2.0, $loop));
        self::assertSame(2, $calls);
    }

    public function testTheFacadeNamesReachTheSplitClasses(): void
    {
        $loop = new StreamSelectLoop();
        $inner = new \React\Promise\Deferred();

        $this->expectException(TimeoutException::class);
        Await::until(AsyncOps::boundWait($inner->promise(), 0.02, $loop), 2.0, $loop);
    }

    public function testTheHardBackoffCeilingIsOneConstantReadByBothNames(): void
    {
        self::assertSame(Retry::MAX_BACKOFF_SECONDS, AsyncOps::MAX_BACKOFF_SECONDS);
        self::assertSame(3600.0, Retry::MAX_BACKOFF_SECONDS);
    }
}
