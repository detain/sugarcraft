<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Await;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\ForkChannel;
use SugarCraft\Async\ForkedTask;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Async\TimeoutException;

final class ForkedTaskTest extends TestCase
{
    protected function setUp(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('ForkedTask needs ext-pcntl and ext-posix');
        }
    }

    public function testChildFramesReachCollectAndTheExitStatusSettles(): void
    {
        $loop = new StreamSelectLoop();
        $frames = [];
        $status = ForkedTask::run(
            static function (ForkChannel $channel): void {
                $channel->send('one');
                $channel->send('two');
            },
            static function (string $frame) use (&$frames): void {
                $frames[] = $frame;
            },
            $loop,
        );

        self::assertSame(0, Await::until($status, 5.0, $loop));
        self::assertSame(['one', 'two'], $frames);
    }

    public function testAThrowingChildSettlesWithExitStatusOne(): void
    {
        $loop = new StreamSelectLoop();
        $status = ForkedTask::run(
            static function (): void {
                throw new \RuntimeException('child failed');
            },
            static function (): void {
            },
            $loop,
        );

        self::assertSame(1, Await::until($status, 5.0, $loop));
    }

    public function testATokenFiredMidRunKillsTheChildAndRejects(): void
    {
        $loop = new StreamSelectLoop();
        $source = CancellationSource::new();
        $started = microtime(true);
        $promise = ForkedTask::run(
            static function (): void {
                sleep(30);
            },
            static function (): void {
            },
            $loop,
            $source->token(),
        );
        $loop->addTimer(0.05, static fn () => $source->cancel());

        try {
            Await::until($promise, 10.0, $loop);
            self::fail('A cancelled forked task must reject');
        } catch (OperationCancelledException) {
            // expected
        }
        self::assertLessThan(10.0, microtime(true) - $started, 'the child must be killed, not waited out');
    }

    public function testTheParentSideDeadlineKillsTheChildAndRejectsWithATimeout(): void
    {
        $loop = new StreamSelectLoop();
        $promise = ForkedTask::run(
            static function (): void {
                sleep(30);
            },
            static function (): void {
            },
            $loop,
            null,
            0.05,
        );

        try {
            Await::until($promise, 10.0, $loop);
            self::fail('An overrun forked task must time out');
        } catch (TimeoutException $e) {
            self::assertSame(0.05, $e->seconds());
        }
    }

    public function testACollectFailureRejectsAndKillsTheChild(): void
    {
        $loop = new StreamSelectLoop();
        $promise = ForkedTask::run(
            static function (ForkChannel $channel): void {
                $channel->send('boom');
                sleep(30);
            },
            static function (): void {
                throw new \DomainException('collector refused');
            },
            $loop,
        );

        $this->expectException(\DomainException::class);
        Await::until($promise, 10.0, $loop);
    }

    public function testAPreCancelledTokenRejectsWithoutForking(): void
    {
        $source = CancellationSource::new();
        $source->cancel();

        $promise = ForkedTask::run(
            static function (): void {
                throw new \LogicException('must not run');
            },
            static function (): void {
            },
            new StreamSelectLoop(),
            $source->token(),
        );

        $this->expectException(OperationCancelledException::class);
        Await::until($promise, 1.0, new StreamSelectLoop());
    }

    /** @dataProvider invalidDeadlineProvider */
    public function testRejectsANonPositiveOrNonFiniteDeadline(float $deadline): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ForkedTask::run(static function (): void {
        }, static function (): void {
        }, new StreamSelectLoop(), null, $deadline);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidDeadlineProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'infinite' => [INF];
    }
}
