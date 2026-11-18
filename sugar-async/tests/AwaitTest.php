<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use SugarCraft\Async\Await;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\TimeoutException;
use function React\Promise\reject;
use function React\Promise\resolve;

final class AwaitTest extends TestCase
{
    public function testReturnsTheResolvedValueWhileDrivingTheLoop(): void
    {
        $loop = new StreamSelectLoop();
        $deferred = new Deferred();
        $loop->addTimer(0.01, static fn () => $deferred->resolve('done'));

        self::assertSame('done', Await::until($deferred->promise(), 2.0, $loop));
    }

    public function testReturnsAnAlreadyResolvedValueWithoutRunningTheLoop(): void
    {
        self::assertSame(7, Await::until(resolve(7), 1.0, new StreamSelectLoop()));
    }

    public function testRethrowsTheRejectionReasonAsIs(): void
    {
        $reason = new \DomainException('nope');

        try {
            Await::until(reject($reason), 1.0, new StreamSelectLoop());
            self::fail('Await must rethrow the rejection');
        } catch (\DomainException $caught) {
            self::assertSame($reason, $caught);
        }
    }

    public function testTimesOutWithATypedExceptionWhileTheInnerStaysPending(): void
    {
        $loop = new StreamSelectLoop();
        $deferred = new Deferred();

        try {
            Await::until($deferred->promise(), 0.02, $loop);
            self::fail('A pending promise must time out');
        } catch (TimeoutException $e) {
            self::assertSame(0.02, $e->seconds());
            self::assertStringContainsString('still pending', $e->getMessage());
        }
    }

    public function testAnAbandonedPromiseSettlingLaterDoesNotStopAnotherRun(): void
    {
        $loop = new StreamSelectLoop();
        $abandoned = new Deferred();
        try {
            Await::until($abandoned->promise(), 0.01, $loop);
        } catch (TimeoutException) {
            // expected: the wait is over, the promise is not
        }

        // Regression: the abandoned promise's then-callback used to call
        // Loop::stop() unconditionally, cutting this later run short.
        $abandoned->resolve('late');
        $survivor = new Deferred();
        $loop->addTimer(0.05, static fn () => $survivor->resolve('still running'));

        self::assertSame('still running', Await::until($survivor->promise(), 2.0, $loop));
    }

    public function testRefusesToNestInsideAnotherAwait(): void
    {
        $loop = new StreamSelectLoop();
        $outer = AsyncOps::deferred(
            static fn () => Await::until(resolve(1), 1.0, $loop),
            $loop,
        );

        $this->expectException(\LogicException::class);
        Await::until($outer, 2.0, $loop);
    }

    /** @dataProvider invalidSecondsProvider */
    public function testRejectsANonPositiveOrNonFiniteBudget(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Await::until(resolve(1), $seconds, new StreamSelectLoop());
    }

    /** @return iterable<string, array{float}> */
    public static function invalidSecondsProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
        yield 'nan' => [NAN];
    }
}
