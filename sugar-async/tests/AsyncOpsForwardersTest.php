<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\Backoff;
use SugarCraft\Async\DrainResult;
use function React\Promise\resolve;

final class AsyncOpsForwardersTest extends TestCase
{
    public function testRetryBoundedForwardsAndReturnsLastResult(): void
    {
        $n = 0;
        $out = AsyncOps::retryBounded(
            static function () use (&$n): int { return ++$n; },
            static fn (int $v): bool => $v < 3,
            1000,
            Backoff::new(1.0, 1.0)->withoutJitter(),
            null,
            static function (int $micros): void {},
        );
        self::assertSame(3, $out);
    }

    public function testNapPaysDebtThroughSleeper(): void
    {
        $slept = 0;
        $t = 0.0;
        AsyncOps::nap(
            500,
            static function (int $m) use (&$slept, &$t): void { $slept += $m; $t += $m / 1e6; },
            static function () use (&$t): float { return $t; },
        );
        self::assertSame(500, $slept);
    }

    public function testReconnectResolvesOnFirstSuccessfulDial(): void
    {
        $result = null;
        AsyncOps::reconnect(
            static fn () => resolve('up'),
            Backoff::new(1.0)->withoutJitter(),
            null,
            Loop::get(),
        )->then(static function (mixed $v) use (&$result): void { $result = $v; });
        self::assertSame('up', $result);
    }

    public function testDrainReadsPipeToEof(): void
    {
        $pipe = fopen('php://temp', 'w+');
        fwrite($pipe, 'hello');
        rewind($pipe);
        $r = AsyncOps::drain([0 => $pipe], microtime(true) + 2.0, 1024, static fn (): float => microtime(true), running: static fn (): bool => false);
        self::assertInstanceOf(DrainResult::class, $r);
        self::assertSame('hello', $r->buffer(0));
        self::assertTrue($r->closed(), "the running probe is forwarded: an exited child ends the Windows arm before the deadline");
    }
}
