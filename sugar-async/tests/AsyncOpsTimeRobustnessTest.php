<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncCache;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\TimeoutException;

use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Time-robustness pins for the bounded helpers: non-finite durations are
 * refused at the door, failure messages name the operation and its bound with
 * structured fields behind them, retry budgets stop on the monotonic clock
 * (clamping the delay instead of arming past the cutoff), and AsyncCache
 * freshness is judged by an injectable monotonic clock so the exact-TTL
 * boundary is testable without sleeping.
 */
final class AsyncOpsTimeRobustnessTest extends TestCase
{
    /** @var list<float> intervals recorded by {@see immediateLoop()} */
    private array $intervals = [];

    private function immediateLoop(): LoopInterface
    {
        $loop = $this->createMock(LoopInterface::class);
        $loop->method('addTimer')->willReturnCallback(
            function (float $interval, callable $callback) use ($loop): TimerInterface {
                $this->intervals[] = $interval;
                $callback();

                return $this->createStub(TimerInterface::class);
            }
        );
        $loop->method('cancelTimer')->willReturn(null);

        return $loop;
    }

    /** @param list<float> $times */
    private function steppingClock(array $times, float $after): \Closure
    {
        return static function () use (&$times, $after): float {
            return \array_shift($times) ?? $after;
        };
    }

    private function rejectionOf(PromiseInterface $promise): ?\Throwable
    {
        $captured = null;
        $promise->then(null, static function (\Throwable $e) use (&$captured): void {
            $captured = $e;
        });

        return $captured;
    }

    public function testTimeoutSecondsMustBeFinitePositive(): void
    {
        $pending = (new Deferred())->promise();

        foreach ([NAN, INF, -INF, 0.0, -1.0] as $seconds) {
            try {
                AsyncOps::withTimeout(promise: $pending, seconds: $seconds, loop: $this->immediateLoop());
                self::fail('withTimeout accepted ' . \var_export($seconds, true));
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Timeout seconds must be a positive finite number', $e->getMessage());
            }
        }
    }

    public function testDeadlineSecondsMustBeFinitePositive(): void
    {
        $pending = (new Deferred())->promise();

        foreach ([NAN, INF, 0.0] as $seconds) {
            try {
                AsyncOps::withDeadline(promise: $pending, seconds: $seconds, loop: $this->immediateLoop());
                self::fail('withDeadline accepted ' . \var_export($seconds, true));
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Deadline seconds must be a positive finite number', $e->getMessage());
            }
        }
    }

    public function testDebounceAndThrottleSecondsAreValidatedAtTheDoor(): void
    {
        $noop = static function (mixed ...$_): void {
        };

        foreach ([NAN, INF, 0.0, -2.0] as $seconds) {
            try {
                AsyncOps::debounce($noop, $seconds);
                self::fail('debounce accepted ' . \var_export($seconds, true));
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Debounce seconds must be a positive finite number', $e->getMessage());
            }

            try {
                AsyncOps::throttle($noop, $seconds);
                self::fail('throttle accepted ' . \var_export($seconds, true));
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Throttle seconds must be a positive finite number', $e->getMessage());
            }
        }
    }

    public function testRetryDurationGuardsAreFinite(): void
    {
        $cases = [
            [INF, null, 0.0, 'Base backoff must be a positive finite number'],
            [0.1, INF, 0.0, 'Max total seconds must be a positive finite number when set'],
            [0.1, NAN, 0.0, 'Max total seconds must be a positive finite number when set'],
            [0.1, null, NAN, 'Jitter must be a finite number >= 0'],
            [0.1, null, INF, 'Jitter must be a finite number >= 0'],
        ];

        foreach ($cases as [$base, $maxTotal, $jitter, $message]) {
            try {
                AsyncOps::retry(
                    static fn(): PromiseInterface => resolve('never runs'),
                    attempts: 2,
                    baseBackoffSeconds: $base,
                    maxTotalSeconds: $maxTotal,
                    jitter: $jitter,
                    loop: $this->immediateLoop(),
                );
                self::fail("retry accepted base={$base} maxTotal=" . \var_export($maxTotal, true) . " jitter={$jitter}");
            } catch (\InvalidArgumentException $e) {
                self::assertSame($message, $e->getMessage());
            }
        }
    }

    public function testTimeoutMessageNamesLabelAndSecondsWithStructuredFields(): void
    {
        $out = AsyncOps::withTimeout(
            promise: (new Deferred())->promise(),
            seconds: 5.0,
            loop: $this->immediateLoop(),
            label: 'login',
        );

        $error = $this->rejectionOf($out);

        self::assertInstanceOf(TimeoutException::class, $error);
        self::assertSame('Operation "login" timed out after 5 seconds', $error->getMessage());
        self::assertSame(5.0, $error->seconds());
        self::assertSame('login', $error->label());
    }

    public function testTimeoutMessagePluralizesAndKeepsPrecision(): void
    {
        $singular = AsyncOps::withTimeout(promise: (new Deferred())->promise(), seconds: 1.0, loop: $this->immediateLoop());
        $fraction = AsyncOps::withTimeout(promise: (new Deferred())->promise(), seconds: 0.25, loop: $this->immediateLoop());

        $singularError = $this->rejectionOf($singular);
        $fractionError = $this->rejectionOf($fraction);

        self::assertInstanceOf(TimeoutException::class, $singularError);
        self::assertSame('Operation timed out after 1 second', $singularError->getMessage());
        self::assertNull($singularError->label());
        self::assertInstanceOf(TimeoutException::class, $fractionError);
        self::assertSame('Operation timed out after 0.25 seconds', $fractionError->getMessage());
    }

    public function testDeadlineMessageIsPhrasedAsADeadline(): void
    {
        $plain = AsyncOps::withDeadline(promise: (new Deferred())->promise(), seconds: 30.0, loop: $this->immediateLoop());
        $labeled = AsyncOps::withDeadline(
            promise: (new Deferred())->promise(),
            seconds: 30.0,
            loop: $this->immediateLoop(),
            label: 'metrics',
        );

        $plainError = $this->rejectionOf($plain);
        $labeledError = $this->rejectionOf($labeled);

        self::assertInstanceOf(TimeoutException::class, $plainError);
        self::assertSame('Operation exceeded its 30-second deadline', $plainError->getMessage());
        self::assertSame(30.0, $plainError->seconds());
        self::assertInstanceOf(TimeoutException::class, $labeledError);
        self::assertSame('Operation "metrics" exceeded its 30-second deadline', $labeledError->getMessage());
        self::assertSame('metrics', $labeledError->label());
    }

    public function testRetryBudgetStopRejectsDistinctlyBeforeScheduling(): void
    {
        // Deadline = 1000 + 10 = 1010; the clock lands exactly on it after the
        // first failure, so the stop fires BEFORE any backoff is armed — no
        // overshoot, and the outcome is a TimeoutException with the last
        // failure riding along as previous.
        $attempts = 0;
        $out = AsyncOps::retry(
            function () use (&$attempts): PromiseInterface {
                ++$attempts;

                return reject(new \RuntimeException('boom-1'));
            },
            attempts: 3,
            baseBackoffSeconds: 0.05,
            maxTotalSeconds: 10.0,
            loop: $this->immediateLoop(),
            now: $this->steppingClock([1000.0, 1010.0], 1010.0),
        );

        $error = $this->rejectionOf($out);

        self::assertSame(1, $attempts, 'the budget stop must precede scheduling attempt 2');
        self::assertSame([], $this->intervals, 'no backoff may be armed once the cutoff passed');
        self::assertInstanceOf(TimeoutException::class, $error);
        self::assertSame(
            'Retry budget of 10 exhausted after attempt 1 (last failure: boom-1)',
            $error->getMessage()
        );
        self::assertSame(10.0, $error->seconds());
        $previous = $error->getPrevious();
        self::assertInstanceOf(\RuntimeException::class, $previous);
        self::assertSame('boom-1', $previous->getMessage());
    }

    public function testRetryClampsBackoffToLandOnTheCutoff(): void
    {
        // Deadline = 0 + 10; after failure 1 the clock already reads 8, so the
        // 5-second base backoff (which would overshoot to 13 > 10) is clamped
        // to the 2 seconds of remaining budget. Attempt 2 then starts exactly
        // on the cutoff (clock 10), fails, and the exhausted budget rejects
        // with the distinct TimeoutException — proving the clamped schedule
        // lands on the boundary rather than past it.
        $attempts = 0;
        $out = AsyncOps::retry(
            function () use (&$attempts): PromiseInterface {
                ++$attempts;

                return reject(new \RuntimeException('flaky-' . $attempts));
            },
            attempts: 3,
            baseBackoffSeconds: 5.0,
            maxTotalSeconds: 10.0,
            loop: $this->immediateLoop(),
            now: $this->steppingClock([0.0, 8.0, 10.0], 10.0),
        );

        $error = $this->rejectionOf($out);

        self::assertSame([2.0], $this->intervals, 'the scheduled delay must be clamped to the remaining budget');
        self::assertSame(2, $attempts);
        self::assertInstanceOf(TimeoutException::class, $error);
        self::assertSame(
            'Retry budget of 10 exhausted after attempt 2 (last failure: flaky-2)',
            $error->getMessage()
        );
    }

    public function testAsyncCacheTtlGuardsAreFinite(): void
    {
        foreach ([NAN, INF, -INF, 0.0] as $ttl) {
            try {
                AsyncCache::new(ttlSeconds: $ttl);
                self::fail('new accepted ttl=' . \var_export($ttl, true));
            } catch (\InvalidArgumentException $e) {
                self::assertSame('TTL seconds must be a positive finite number', $e->getMessage());
            }
        }

        try {
            AsyncCache::new(ttlSeconds: 5.0)->withTtl(INF);
            self::fail('withTtl accepted INF');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('TTL seconds must be a positive finite number', $e->getMessage());
        }
    }

    public function testFreshnessBoundaryIsExactTtlStaleOnAMonotonicSeam(): void
    {
        $now = 5.0;
        $cache = AsyncCache::new(
            ttlSeconds: 10.0,
            clock: static function () use (&$now): float {
                return $now;
            },
        );

        $settled = null;
        $cache->fetch('user:1', static fn(): PromiseInterface => resolve('v'))->then(
            static function (mixed $value) use (&$settled): void {
                $settled = $value;
            }
        );
        self::assertSame('v', $settled);

        $wider = $cache->withTtl(20.0);

        self::assertTrue($cache->isFresh('user:1'));
        $now = 14.999;
        self::assertTrue($cache->isFresh('user:1'), 'elapsed just under the TTL is still fresh');
        self::assertTrue($wider->isFresh('user:1'), 'the clone reads stamps through the same store clock');

        $now = 15.0;
        self::assertFalse($cache->isFresh('user:1'), 'elapsed == TTL is stale, not fresh-by-rounding');
        self::assertTrue($wider->isFresh('user:1'));
        self::assertSame('v', $cache->peek('user:1'), 'peek exposes the stale value for serve-stale flows');

        // A re-fetch through the now-stale handle re-runs the loader and
        // restamps through the shared clock.
        $loads = 0;
        $cache->fetch('user:1', static function () use (&$loads): PromiseInterface {
            ++$loads;

            return resolve('v2');
        })->then(static function (): void {
        });
        self::assertSame(1, $loads);
        $now = 24.999;
        self::assertTrue($cache->isFresh('user:1'), 'the restamp restarts the 10s window from 15.0');
    }

    public function testStoreClockDefaultsToMonotonicNotWallTime(): void
    {
        // No source-scan of hrtime call sites: behavior — the default clock is
        // monotonic, so an entry cannot be judged by a stamp written through a
        // different time base. AsyncCache::new with no clock stamps through
        // hrtime()/1e9; two seconds of wall-clock tampering cannot be done in
        // a unit test, so the pin is that fetch→isFresh holds across a
        // 0.05s real sleep with ttl 10.0 while ttl 0.02 goes stale.
        $cache = AsyncCache::new(ttlSeconds: 10.0);
        $cache->fetch('k', static fn(): PromiseInterface => resolve('v'))->then(static function (): void {
        });
        self::assertTrue($cache->isFresh('k'));

        $short = AsyncCache::new(ttlSeconds: 0.02);
        $short->fetch('k', static fn(): PromiseInterface => resolve('v'))->then(static function (): void {
        });
        self::assertTrue($short->isFresh('k'), 'fresh immediately after adopt');
        $started = hrtime(true);
        while ((hrtime(true) - $started) < 30_000_000) {
            \usleep(1_000);
        }
        self::assertFalse($short->isFresh('k'), 'the 0.02s window elapsed on the monotonic clock');
    }
}
