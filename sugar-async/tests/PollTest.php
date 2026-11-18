<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
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

    public function testPollClampsItsBackoffDelayToTheLadderPlateau(): void
    {
        // A 2 h base would arm a 2 h poll timer; the delay must plateau at the
        // same ceiling retry() uses. StreamSelectLoop is final, so the spy
        // delegates every LoopInterface call and records the armed intervals.
        $loop = new class (new StreamSelectLoop()) implements LoopInterface {
            /** @var list<float> */
            public array $armed = [];

            public function __construct(private readonly LoopInterface $inner)
            {
            }

            public function addReadStream($stream, $listener): void
            {
                $this->inner->addReadStream($stream, $listener);
            }

            public function addWriteStream($stream, $listener): void
            {
                $this->inner->addWriteStream($stream, $listener);
            }

            public function removeReadStream($stream): void
            {
                $this->inner->removeReadStream($stream);
            }

            public function removeWriteStream($stream): void
            {
                $this->inner->removeWriteStream($stream);
            }

            public function addTimer($interval, $callback)
            {
                $this->armed[] = (float) $interval;

                return $this->inner->addTimer($interval, $callback);
            }

            public function addPeriodicTimer($interval, $callback)
            {
                return $this->inner->addPeriodicTimer($interval, $callback);
            }

            public function cancelTimer($timer): void
            {
                $this->inner->cancelTimer($timer);
            }

            public function futureTick($listener): void
            {
                $this->inner->futureTick($listener);
            }

            public function addSignal($signal, $listener): void
            {
                $this->inner->addSignal($signal, $listener);
            }

            public function removeSignal($signal, $listener = null): void
            {
                $this->inner->removeSignal($signal, $listener);
            }

            public function run(): void
            {
                $this->inner->run();
            }

            public function stop(): void
            {
                $this->inner->stop();
            }
        };
        $source = CancellationSource::new();
        $promise = Poll::until(static fn (): ?int => null, Backoff::new(7_200_000.0)->withoutJitter(), $source->token(), $loop);
        $promise->then(null, static function (): void {
        });
        $source->cancel();

        self::assertSame([3600.0], $loop->armed);
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

    /**
     * Regression: a callable that cancels the token and then answers null used
     * to arm the next backoff timer after the poll had already rejected, which
     * kept the loop alive for the whole delay.
     */
    public function testASynchronousCancelInsideTheAttemptArmsNoTimer(): void
    {
        $loop = new StreamSelectLoop();
        $source = CancellationSource::new();
        $calls = 0;
        $promise = Poll::until(static function () use (&$calls, $source): ?int {
            $calls++;
            $source->cancel();

            return null;
        }, Backoff::new(500.0)->withoutJitter(), $source->token(), $loop);

        try {
            Await::until($promise, 2.0, $loop);
            self::fail('A cancelled poll must reject');
        } catch (OperationCancelledException) {
            // expected
        }

        $started = microtime(true);
        $loop->run();
        self::assertLessThan(0.25, microtime(true) - $started, 'no backoff timer may outlive the settled poll');
        self::assertSame(1, $calls);
    }
}
