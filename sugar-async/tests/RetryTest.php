<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use SugarCraft\Async\Backoff;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Async\Retry;
use function React\Promise\reject;
use function React\Promise\resolve;

final class RetryTest extends TestCase
{
    /** @return array{0: callable(): float, 1: callable(int): void, 2: \ArrayObject<int,int>} */
    private function fakeTime(): array
    {
        $t = 100.0;
        $slept = new \ArrayObject();
        $now = function () use (&$t): float {
            return $t;
        };
        $sleep = function (int $us) use (&$t, $slept): void {
            $slept[] = $us;
            $t += $us / 1_000_000;
        };

        return [$now, $sleep, $slept];
    }

    /** Spin the shared loop until $done, however many stray timers stop() it early. */
    private function runUntil(\React\EventLoop\LoopInterface $loop, callable $done): void
    {
        $give = microtime(true) + 5.0;
        while (!$done() && microtime(true) < $give) {
            $loop->addTimer(0.05, static fn () => $loop->stop());
            $loop->run();
        }
    }

    public function testBoundedReturnsFirstFinalResultWithoutSleeping(): void
    {
        [$now, $sleep, $slept] = $this->fakeTime();
        $r = Retry::bounded(fn () => 'ok', fn ($x) => $x !== 'ok', 1000, Backoff::new(50, 1.0), $now, $sleep);
        $this->assertSame('ok', $r);
        $this->assertSame([], $slept->getArrayCopy());
    }

    public function testBoundedRetriesUntilFinal(): void
    {
        [$now, $sleep, $slept] = $this->fakeTime();
        $calls = 0;
        $r = Retry::bounded(function () use (&$calls) {
            return ++$calls < 3 ? 'wait' : 'done';
        }, fn ($x) => $x === 'wait', 1000, Backoff::new(10, 2.0), $now, $sleep);
        $this->assertSame('done', $r);
        $this->assertSame([10_000, 20_000], $slept->getArrayCopy());
    }

    public function testBoundedClampsLastNapToBudgetAndReturnsLastResult(): void
    {
        [$now, $sleep, $slept] = $this->fakeTime();
        $calls = 0;
        $r = Retry::bounded(function () use (&$calls) {
            return ++$calls;
        }, fn () => true, 100, Backoff::new(60, 1.0), $now, $sleep);
        // 60ms then the remaining 40ms (not another 60), then the budget is gone.
        $this->assertSame([60_000, 40_000], $slept->getArrayCopy());
        $this->assertSame(3, $r);
    }

    public function testBoundedHonoursAttemptsCap(): void
    {
        [$now, $sleep, $slept] = $this->fakeTime();
        $calls = 0;
        Retry::bounded(function () use (&$calls) {
            return ++$calls;
        }, fn () => true, 10_000, Backoff::new(1, 1.0)->withAttempts(3), $now, $sleep);
        $this->assertSame(3, $calls);
    }

    public function testBoundedZeroBudgetRunsOnce(): void
    {
        [$now, $sleep, $slept] = $this->fakeTime();
        $calls = 0;
        Retry::bounded(function () use (&$calls) {
            return ++$calls;
        }, fn () => true, 0, Backoff::new(5), $now, $sleep);
        $this->assertSame(1, $calls);
        $this->assertSame([], $slept->getArrayCopy());
    }

    public function testBoundedRejectsNegativeBudget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Retry::bounded(fn () => 1, fn () => false, -1, Backoff::new(5));
    }

    public function testNapNoDebtNeverSleeps(): void
    {
        $slept = 0;
        Retry::nap(0, function () use (&$slept): void {
            $slept++;
        });
        Retry::nap(-5, function () use (&$slept): void {
            $slept++;
        });
        $this->assertSame(0, $slept);
    }

    public function testNapRearmsFromClockAfterShortSleep(): void
    {
        $t = 0.0;
        $calls = [];
        $now = function () use (&$t): float {
            return $t;
        };
        // EINTR-shaped: the first nap returns after only 100ms of a 300ms debt.
        $sleeper = function (int $us) use (&$t, &$calls): void {
            $calls[] = $us;
            $t += count($calls) === 1 ? 0.1 : $us / 1_000_000;
        };
        Retry::nap(300_000, $sleeper, $now);
        $this->assertSame([300_000, 200_000], $calls);
    }

    public function testNapBeatSlicesAndSurvivesThrowingBeat(): void
    {
        $t = 0.0;
        $calls = [];
        $beats = 0;
        $now = function () use (&$t): float {
            return $t;
        };
        $sleeper = function (int $us) use (&$t, &$calls): void {
            $calls[] = $us;
            $t += $us / 1_000_000;
        };
        Retry::nap(2_500_000, $sleeper, $now, function () use (&$beats): void {
            $beats++;
            throw new \RuntimeException('beat');
        });
        $this->assertSame([1_000_000, 1_000_000, 500_000], $calls);
        $this->assertSame(3, $beats);
    }

    public function testReconnectForeverUntilSuccessAndReportsDelays(): void
    {
        $loop = Loop::get();
        $fails = 0;
        $delays = [];
        $p = Retry::reconnect(
            function () use (&$fails) {
                return ++$fails <= 4 ? reject(new \RuntimeException("down $fails")) : resolve('up');
            },
            Backoff::new(1, 2.0, 4),
            null,
            $loop,
            function (int $n, int $ms, \Throwable $e) use (&$delays): void {
                $delays[] = [$n, $ms, $e->getMessage()];
            },
        );
        $out = null;
        $p->then(function ($v) use (&$out): void {
            $out = $v;
        });
        $this->runUntil($loop, function () use (&$out): bool {
            return $out !== null;
        });
        $this->assertSame('up', $out);
        $this->assertSame([[1, 1, 'down 1'], [2, 2, 'down 2'], [3, 4, 'down 3'], [4, 4, 'down 4']], $delays);
    }

    public function testReconnectBoundedRejectsWithLastFailure(): void
    {
        $loop = Loop::get();
        $n = 0;
        $p = Retry::reconnect(
            function () use (&$n) {
                return reject(new \RuntimeException('fail ' . ++$n));
            },
            Backoff::new(1)->withAttempts(3),
            null,
            $loop,
        );
        $err = null;
        $p->then(null, function (\Throwable $e) use (&$err): void {
            $err = $e;
        });
        $this->runUntil($loop, function () use (&$err): bool {
            return $err !== null;
        });
        $this->assertSame('fail 3', $err?->getMessage());
        $this->assertSame(3, $n);
    }

    public function testReconnectThrowingDialCountsAsFailure(): void
    {
        $loop = Loop::get();
        $n = 0;
        $p = Retry::reconnect(function () use (&$n) {
            if (++$n < 2) {
                throw new \RuntimeException('sync');
            }
            return resolve(1);
        }, Backoff::new(1), null, $loop);
        $out = null;
        $p->then(function ($v) use (&$out): void {
            $out = $v;
        });
        $this->runUntil($loop, function () use (&$out): bool {
            return $out !== null;
        });
        $this->assertSame(1, $out);
    }

    public function testReconnectTokenCancelsCycleAndReleasesTimer(): void
    {
        $loop = Loop::get();
        $source = CancellationSource::new();
        $n = 0;
        $p = Retry::reconnect(function () use (&$n) {
            $n++;
            return reject(new \RuntimeException('x'));
        }, Backoff::new(60_000), $source->token(), $loop);
        $err = null;
        $p->then(null, function (\Throwable $e) use (&$err): void {
            $err = $e;
        });
        $source->cancel();
        $this->assertInstanceOf(OperationCancelledException::class, $err);
        $this->assertSame(1, $n, 'no second dial after cancel');
    }

    public function testReconnectAlreadyCancelledTokenNeverDials(): void
    {
        $source = CancellationSource::new();
        $source->cancel();
        $n = 0;
        $p = Retry::reconnect(function () use (&$n) {
            $n++;
            return resolve(1);
        }, Backoff::new(1), $source->token(), Loop::get());
        $err = null;
        $p->then(null, function (\Throwable $e) use (&$err): void {
            $err = $e;
        });
        $this->assertSame(0, $n);
        $this->assertInstanceOf(OperationCancelledException::class, $err);
    }

    public function testReconnectCancelPromiseCancelsInFlightDial(): void
    {
        $d = new Deferred(function () use (&$cancelled): void {
            $cancelled = true;
        });
        $cancelled = false;
        $p = Retry::reconnect(fn () => $d->promise(), Backoff::new(1), null, Loop::get());
        $p->cancel();
        $this->assertTrue($cancelled);
    }
}
