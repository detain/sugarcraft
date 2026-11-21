<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use SugarCraft\Async\Await;
use SugarCraft\Async\LoopDeadline;

final class LoopDeadlineTest extends TestCase
{
    public function testFiresOnceWhenTheBudgetElapses(): void
    {
        $loop = new StreamSelectLoop();
        $fired = new Deferred();
        $count = 0;
        $deadline = LoopDeadline::arm($loop, 0.01, static function () use ($fired, &$count): void {
            $count++;
            $fired->resolve(true);
        });

        Await::until($fired->promise(), 2.0, $loop);

        self::assertTrue($deadline->fired());
        self::assertFalse($deadline->isArmed());
        self::assertSame(1, $count);
    }

    public function testDisarmReleasesTheDeadlineBeforeItFires(): void
    {
        $loop = new StreamSelectLoop();
        $fired = false;
        $deadline = LoopDeadline::arm($loop, 0.01, static function () use (&$fired): void {
            $fired = true;
        });
        self::assertTrue($deadline->isArmed());

        $deadline->disarm();
        Await::until(self::settleAfter($loop, 0.03), 2.0, $loop);

        self::assertFalse($fired);
        self::assertFalse($deadline->fired());
        self::assertFalse($deadline->isArmed());
    }

    public function testDisarmIsIdempotentAndHarmlessAfterFiring(): void
    {
        $loop = new StreamSelectLoop();
        $fired = new Deferred();
        $deadline = LoopDeadline::arm($loop, 0.01, static fn () => $fired->resolve(true));
        Await::until($fired->promise(), 2.0, $loop);

        $deadline->disarm();
        $deadline->disarm();

        self::assertTrue($deadline->fired());
    }

    /** @dataProvider invalidSecondsProvider */
    public function testRejectsANonPositiveOrNonFiniteBudget(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LoopDeadline::arm(new StreamSelectLoop(), $seconds, static function (): void {
        });
    }

    /** A promise that resolves after $seconds on $loop, used to keep the loop running. */
    private static function settleAfter(StreamSelectLoop $loop, float $seconds): \React\Promise\PromiseInterface
    {
        $deferred = new Deferred();
        $loop->addTimer($seconds, static fn () => $deferred->resolve(true));

        return $deferred->promise();
    }

    /** @return iterable<string, array{float}> */
    public static function invalidSecondsProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
    }
}
