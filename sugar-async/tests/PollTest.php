<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Await;
use SugarCraft\Async\Backoff;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Async\Poll;
use SugarCraft\Async\TimeoutException;

final class PollTest extends TestCase
{
    private function fastBackoff(?int $attempts = null): Backoff
    {
        return Backoff::new(1.0)->withoutJitter()->withAttempts($attempts);
    }

    public function testResolvesWithTheFirstNonNullAnswer(): void
    {
        $loop = new StreamSelectLoop();
        $calls = 0;
        $promise = Poll::until(static function () use (&$calls): ?string {
            $calls++;

            return $calls === 3 ? 'ready' : null;
        }, $this->fastBackoff(), null, $loop);

        self::assertSame('ready', Await::until($promise, 2.0, $loop));
        self::assertSame(3, $calls);
    }

    public function testTheFirstAttemptRunsBeforeTheLoopIsDriven(): void
    {
        $calls = 0;
        Poll::until(static function () use (&$calls): int {
            $calls++;

            return 1;
        }, $this->fastBackoff(), null, new StreamSelectLoop());

        self::assertSame(1, $calls);
    }

    public function testRejectsWithTimeoutWhenTheBackoffAllowsNoFurtherAttempt(): void
    {
        $loop = new StreamSelectLoop();
        $calls = 0;
        $promise = Poll::until(static function () use (&$calls): ?int {
            $calls++;

            return null;
        }, $this->fastBackoff(attempts: 3), null, $loop);

        try {
            Await::until($promise, 2.0, $loop);
            self::fail('An exhausted poll must reject');
        } catch (TimeoutException $e) {
            self::assertStringContainsString('gave up after 3 attempts', $e->getMessage());
        }
        self::assertSame(3, $calls);
    }

    public function testRejectsWithTheCallablesOwnException(): void
    {
        $loop = new StreamSelectLoop();
        $promise = Poll::until(static function (): never {
            throw new \RuntimeException('probe failed');
        }, $this->fastBackoff(), null, $loop);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('probe failed');
        Await::until($promise, 2.0, $loop);
    }

    public function testAPreCancelledTokenRejectsWithoutAttempting(): void
    {
        $source = CancellationSource::new();
        $source->cancel();
        $calls = 0;

        $promise = Poll::until(static function () use (&$calls): int {
            $calls++;

            return 1;
        }, $this->fastBackoff(), $source->token(), new StreamSelectLoop());

        self::assertSame(0, $calls);
        $this->expectException(OperationCancelledException::class);
        Await::until($promise, 1.0, new StreamSelectLoop());
    }

    public function testATokenFiredDuringBackoffStopsFurtherAttempts(): void
    {
        $loop = new StreamSelectLoop();
        $source = CancellationSource::new();
        $calls = 0;
        $promise = Poll::until(static function () use (&$calls): ?int {
            $calls++;

            return null;
        }, Backoff::new(200.0)->withoutJitter(), $source->token(), $loop);
        $loop->addTimer(0.02, static fn () => $source->cancel());

        try {
            Await::until($promise, 2.0, $loop);
            self::fail('A cancelled poll must reject');
        } catch (OperationCancelledException) {
            // expected
        }
        self::assertSame(1, $calls, 'no attempt may run after the token fired');
    }

    public function testCancellingTheReturnedPromiseStopsThePoll(): void
    {
        $loop = new StreamSelectLoop();
        $calls = 0;
        $promise = Poll::until(static function () use (&$calls): ?int {
            $calls++;

            return null;
        }, Backoff::new(200.0)->withoutJitter(), null, $loop);
        $loop->addTimer(0.02, static fn () => $promise->cancel());

        $this->expectException(OperationCancelledException::class);
        try {
            Await::until($promise, 2.0, $loop);
        } finally {
            self::assertSame(1, $calls);
        }
    }
}
