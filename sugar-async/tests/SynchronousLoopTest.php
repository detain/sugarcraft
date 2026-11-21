<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Async\Backoff;
use SugarCraft\Async\Poll;
use SugarCraft\Async\SynchronousLoop;

final class SynchronousLoopTest extends TestCase
{
    public function testATimerFiresOnlyWhenRunAndAfterItsSleep(): void
    {
        $sleeps = [];
        $loop = SynchronousLoop::new(static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        $fired = 0;

        $loop->addTimer(0.5, static function () use (&$fired): void {
            $fired++;
        });
        self::assertSame(0, $fired, 'arming a timer must not fire it');
        self::assertSame([], $sleeps);

        $loop->run();
        self::assertSame([0.5], $sleeps, 'run() waits out the armed interval through the sleeper');
        self::assertSame(1, $fired);
    }

    public function testACancelledTimerNeverSleepsOrFires(): void
    {
        $sleeps = [];
        $loop = SynchronousLoop::new(static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        $fired = 0;

        $timer = $loop->addTimer(1.0, static function () use (&$fired): void {
            $fired++;
        });
        $loop->cancelTimer($timer);
        $loop->run();

        self::assertSame([], $sleeps);
        self::assertSame(0, $fired);
    }

    public function testCancellingAnAlreadyFiredTimerIsANoOp(): void
    {
        $loop = SynchronousLoop::new(static function (float $s): void {
        });
        $timer = $loop->addTimer(0.1, static function (): void {
        });
        $loop->run();

        $loop->cancelTimer($timer);
        $next = $loop->addTimer(0.2, static function (): void {
        });
        self::assertNotSame($timer, $next, 'a stale cancel must not disturb a later timer');
    }

    public function testArmingASecondTimerWhileOneIsPendingRefuses(): void
    {
        $loop = SynchronousLoop::new(static function (float $s): void {
        });
        $loop->addTimer(0.1, static function (): void {
        });

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('one pending timer at a time');
        $loop->addTimer(0.2, static function (): void {
        });
    }

    public function testACallbackThatArmsTheNextTimerKeepsTheDrainGoing(): void
    {
        $sleeps = [];
        $loop = SynchronousLoop::new(static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        $ticks = 0;
        $tick = null;
        $tick = static function () use (&$ticks, &$tick, $loop): void {
            $ticks++;
            if ($ticks < 3) {
                $loop->addTimer(0.25, $tick);
            }
        };
        $loop->addTimer(0.25, $tick);

        $loop->run();

        self::assertSame(3, $ticks);
        self::assertSame([0.25, 0.25, 0.25], $sleeps);
    }

    public function testStopHaltsTheDrainAndLeavesTheTimerPending(): void
    {
        $sleeps = [];
        $loop = SynchronousLoop::new(static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        $fired = 0;
        $tick = null;
        $tick = static function () use (&$fired, &$tick, $loop): void {
            $fired++;
            $loop->stop();
            $loop->addTimer(0.5, $tick);
        };
        $loop->addTimer(0.5, $tick);

        $loop->run();
        self::assertSame(1, $fired);
        self::assertSame([0.5], $sleeps);

        $loop->run();
        self::assertSame(2, $fired, 'a later run() resumes the still-pending timer');
    }

    public function testThePollUntilChainDrivesThroughTheAdapterWithItsOwnCadence(): void
    {
        $sleeps = [];
        $loop = SynchronousLoop::new(static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        $attempts = 0;
        $result = null;
        $error = null;

        Poll::until(
            static function () use (&$attempts): ?string {
                $attempts++;

                return $attempts < 3 ? null : 'done';
            },
            Backoff::new(250.0, 1.0),
            null,
            $loop,
        )->then(
            static function (string $value) use (&$result): void {
                $result = $value;
            },
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );
        $loop->run();

        self::assertNull($error);
        self::assertSame('done', $result);
        self::assertSame(3, $attempts);
        self::assertSame([0.25, 0.25], $sleeps, 'one pause per continuing attempt, none after the answer');
    }

    public function testAPollCallbackThatThrowsRejectsWithoutAnotherSleep(): void
    {
        $sleeps = [];
        $loop = SynchronousLoop::new(static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
        $attempts = 0;
        $error = null;

        Poll::until(
            static function () use (&$attempts): ?string {
                $attempts++;
                if ($attempts === 2) {
                    throw new \RuntimeException('cap reached');
                }

                return null;
            },
            Backoff::new(1000.0, 1.0),
            null,
            $loop,
        )->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });
        $loop->run();

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame('cap reached', $error->getMessage());
        self::assertSame([1.0], $sleeps, 'the throw settles the poll before a third attempt is scheduled');
    }

    /** @return iterable<string, array{string, list<mixed>}> */
    public static function unsupportedMethods(): iterable
    {
        yield 'addReadStream' => ['addReadStream', [STDIN, static function (): void {
        }]];
        yield 'addWriteStream' => ['addWriteStream', [STDOUT, static function (): void {
        }]];
        yield 'removeReadStream' => ['removeReadStream', [STDIN]];
        yield 'removeWriteStream' => ['removeWriteStream', [STDOUT]];
        yield 'addPeriodicTimer' => ['addPeriodicTimer', [1.0, static function (): void {
        }]];
        yield 'futureTick' => ['futureTick', [static function (): void {
        }]];
        yield 'addSignal' => ['addSignal', [2, static function (): void {
        }]];
        yield 'removeSignal' => ['removeSignal', [2, static function (): void {
        }]];
    }

    /** @param list<mixed> $args */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedMethods')]
    public function testUnsupportedSurfaceRefusesNamingTheMethod(string $method, array $args): void
    {
        $loop = SynchronousLoop::new();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('does not support ' . $method . '()');
        $loop->{$method}(...$args);
    }
}
