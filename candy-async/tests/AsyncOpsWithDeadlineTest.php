<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\Promise;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\TimeoutException;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Guards the cancelling-deadline semantics ported from candy-query's
 * QueryTimeout (port Q4): a promise that never settles (network partition —
 * drivers only reject on an observed socket error) must reject with
 * TimeoutException, CANCEL the inner, and fire the abort hook exactly once —
 * a timed-out operation must not keep burning its producer after the caller
 * has given up. The opposite shape of withTimeout, which lets the inner run;
 * the arming law (timer before handlers) applies to both, so the
 * pre-settled-inner pins mirror the withTimeout leak regressions.
 *
 * Every test drives a private StreamSelectLoop: the established isolation
 * pattern in this lib (sibling tests stop() their run() early and would leave
 * armed timers on the shared loop), and StreamSelectLoop refreshes its clock
 * at arm time, so bounded waits stay honest without the LoopPin.
 *
 * @covers \SugarCraft\Async\AsyncOps::withDeadline
 */
final class AsyncOpsWithDeadlineTest extends TestCase
{
    public function testPendingPromiseRejectsWithTimeoutExceptionWhenDeadlineFires(): void
    {
        $loop = new StreamSelectLoop();
        // Never settles and cancels to a no-op — models a dropped route.
        $never = new Promise(static function (): void {}, static function (): void {});

        $error = null;
        AsyncOps::withDeadline($loop, $never, 0.05)->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        // run() returns once the fired deadline leaves zero armed timers.
        $loop->run();

        $this->assertInstanceOf(TimeoutException::class, $error);
        $this->assertStringContainsString('deadline', $error->getMessage());
    }

    public function testDeadlineCancelsTheInnerPromiseExactlyOnce(): void
    {
        $loop = new StreamSelectLoop();
        $cancellations = 0;
        $never = new Promise(
            static function (): void {},
            static function () use (&$cancellations): void {
                $cancellations++;
            },
        );

        AsyncOps::withDeadline($loop, $never, 0.05)->then(null, static fn () => null);

        $loop->run();

        $this->assertSame(1, $cancellations, 'the deadline must cancel() the inner — that is the whole point vs withTimeout');
    }

    public function testOnTimeoutHookFiresOnceAndItsErrorCannotMaskTheTimeout(): void
    {
        $loop = new StreamSelectLoop();
        $never = new Promise(static function (): void {}, static function (): void {});

        $kills = 0;
        $error = null;
        AsyncOps::withDeadline($loop, $never, 0.05, static function () use (&$kills): void {
            $kills++;
            throw new \LogicException('kill failed — must not mask the timeout');
        })->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $loop->run();

        $this->assertSame(1, $kills, 'onTimeout hook fires exactly once on deadline');
        $this->assertInstanceOf(TimeoutException::class, $error);
    }

    public function testLateDriverResultAfterDeadlineIsDropped(): void
    {
        // A Deferred inner ignores cancel() (no canceller) — exactly the
        // react/mysql no-op shape — and answers after the deadline. The
        // caller-facing promise settled on the timeout and must not flip.
        $loop = new StreamSelectLoop();
        $pending = new Deferred();

        $outcomes = [];
        AsyncOps::withDeadline($loop, $pending->promise(), 0.05)->then(
            static function ($v) use (&$outcomes): void {
                $outcomes[] = ['resolved', $v];
            },
            static function (\Throwable $e) use (&$outcomes): void {
                $outcomes[] = ['rejected', $e::class];
            },
        );

        $loop->addTimer(0.15, static function () use ($pending): void {
            $pending->resolve('too late');
        });
        $loop->addTimer(0.3, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertSame([['rejected', TimeoutException::class]], $outcomes, 'late result must not resurrect a timed-out promise');
    }

    public function testZeroSecondsThrows(): void
    {
        $loop = new StreamSelectLoop();

        $this->expectException(\InvalidArgumentException::class);
        AsyncOps::withDeadline($loop, resolve('x'), 0.0);
    }

    public function testNegativeSecondsThrows(): void
    {
        $loop = new StreamSelectLoop();

        $this->expectException(\InvalidArgumentException::class);
        AsyncOps::withDeadline($loop, resolve('x'), -1.0);
    }

    public function testPreResolvedInnerResolvesAndLeaksNoTimer(): void
    {
        // withTimeout arming-law regression, ported: the settle handler must
        // find the deadline timer already in hand to cancel it, or the 0.5s
        // timer holds the loop after the work completed. Idle-drain bounds the
        // leak directly: run() returns only at zero armed timers.
        $loop = new StreamSelectLoop();

        $wrapped = AsyncOps::withDeadline($loop, resolve('done'), 0.5);

        $resolved = null;
        $wrapped->then(function ($v) use (&$resolved): void {
            $resolved = $v;
        });

        $start = microtime(true);
        $loop->run();
        $drain = microtime(true) - $start;

        $this->assertSame('done', $resolved);
        $this->assertLessThan(0.2, $drain, 'deadline timer leaked past settlement of a pre-resolved inner');
    }

    public function testPreRejectedInnerPropagatesAndLeaksNoTimer(): void
    {
        $loop = new StreamSelectLoop();

        $wrapped = AsyncOps::withDeadline($loop, reject(new \RuntimeException('fail')), 0.5);

        $rejected = null;
        $wrapped->then(null, function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });

        $start = microtime(true);
        $loop->run();
        $drain = microtime(true) - $start;

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertSame('fail', $rejected->getMessage());
        $this->assertLessThan(0.2, $drain, 'deadline timer leaked past rejection of a pre-rejected inner');
    }

    public function testHookDoesNotFireWhenTheInnerSettlesInTime(): void
    {
        $loop = new StreamSelectLoop();

        $kills = 0;
        $value = null;
        AsyncOps::withDeadline($loop, resolve([['ok' => 1]]), 30.0, static function () use (&$kills): void {
            $kills++;
        })->then(static function (array $result) use (&$value): void {
            $value = $result;
        });

        $loop->run(); // deadline timer was cancelled at settle; drain is instant

        $this->assertSame([['ok' => 1]], $value);
        $this->assertSame(0, $kills, 'an operation that finished in time must never be aborted');
    }
}
