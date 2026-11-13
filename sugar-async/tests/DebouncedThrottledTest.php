<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\Debounced;
use SugarCraft\Async\Subscription;
use SugarCraft\Async\Throttled;

/**
 * Contract pins for the disposable debounce/throttle handles (M2/M3): each
 * call answers a promise — fire's value, null for superseded/dropped — and
 * the handles are Subscriptions with flush()/unsubscribe()/isPending().
 */
final class DebouncedThrottledTest extends TestCase
{
    /** @var list<float> */
    private array $intervals = [];

    /** @var array<int, TimerInterface> */
    private array $live = [];

    public function testDebounceHandleIsAnInvokableSubscription(): void
    {
        $handle = AsyncOps::debounce(static function (): void {}, 0.05, $this->clockwork());
        self::assertInstanceOf(Debounced::class, $handle);
        self::assertInstanceOf(Subscription::class, $handle);
        self::assertIsCallable($handle);
    }

    public function testOnlyLastCallFiresAndSupersededCallsAnswerNull(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::debounce(
            static function (string $value) use (&$calls): string {
                $calls[] = $value;
                return strtoupper($value);
            },
            0.05,
            $loop,
        );

        $first = $handle('a');
        $second = $handle('b');
        self::assertSame(0, count($calls), 'nothing fires before the window');
        self::assertTrue($handle->isPending());

        $this->fireAll();
        self::assertSame(['b'], $calls);
        self::assertSame('B', $this->outcome($second));
        self::assertNull($this->outcome($first), 'superseded call answers null');
        self::assertFalse($handle->isPending());
    }

    public function testSynchronousThrowRejectsThatCallsPromise(): void
    {
        $loop = $this->clockwork();
        $handle = AsyncOps::debounce(
            static function (): string {
                throw new \RuntimeException('boom');
            },
            0.05,
            $loop,
        );

        $promise = $handle('x');
        $this->fireAll();
        self::assertSame('boom', $this->rejectionMessage($promise));
    }

    public function testFlushRunsThePendingBurstNowAndCancelsTheTimer(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::debounce(
            static function (string $value) use (&$calls): string {
                $calls[] = $value;
                return $value;
            },
            0.05,
            $loop,
        );

        $promise = $handle('now');
        $flushed = $handle->flush();
        self::assertSame(['now'], $calls);
        self::assertSame('now', $this->outcome($promise));
        self::assertSame('now', $this->outcome($flushed));
        self::assertSame([], $this->live, 'flush must release the armed timer');

        $this->fireAll();
        self::assertSame(['now'], $calls, 'no double-fire after flush');
    }

    public function testFlushOnIdleAnswersNullAndStartsNothing(): void
    {
        $handle = AsyncOps::debounce(static fn(): string => 'never', 0.05, $this->clockwork());
        self::assertNull($this->outcome($handle->flush()));
    }

    public function testUnsubscribeDropsThePendingBurstAsNull(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::debounce(
            static function () use (&$calls): void {
                $calls[] = 'fired';
            },
            0.05,
            $loop,
        );

        $promise = $handle('a');
        $handle->unsubscribe();
        self::assertNull($this->outcome($promise));
        self::assertFalse($handle->isActive());
        self::assertFalse($handle->isPending());
        self::assertSame([], $this->live);
        $this->fireAll();
        self::assertSame([], $calls);
    }

    public function testSpentHandleInvokesAnswerNullWithoutArmingAnything(): void
    {
        $loop = $this->clockwork();
        $handle = AsyncOps::debounce(static fn(): string => 'x', 0.05, $loop);
        $handle->unsubscribe();

        $late = $handle('ignored');
        self::assertNull($this->outcome($late));
        self::assertSame([], $this->live, 'a spent handle arms no timers');
    }

    public function testSourceCancellationDisposesTheHandle(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $source = CancellationSource::new();
        $handle = AsyncOps::debounce(
            static function () use (&$calls): void {
                $calls[] = 'fired';
            },
            0.05,
            $loop,
            $source,
        );

        $promise = $handle('a');
        $source->cancel();
        self::assertNull($this->outcome($promise));
        self::assertFalse($handle->isActive());
        $this->fireAll();
        self::assertSame([], $calls, 'the pending burst never runs');
    }

    public function testThrottleLeadingEdgeFiresAndAnswersItsOwnResult(): void
    {
        $loop = $this->clockwork();
        $handle = AsyncOps::throttle(static fn(string $v): string => $v, 0.05, $loop);
        self::assertSame('lead', $this->outcome($handle('lead')));
        self::assertSame([0.05], $this->intervals, 'only the cooldown window is armed');
        self::assertFalse($handle->isPending());
    }

    public function testMidWindowCallsAnswerNullAndDoNotRearm(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::throttle(
            static function (string $v) use (&$calls): string {
                $calls[] = $v;
                return $v;
            },
            0.05,
            $loop,
        );

        $handle('first');
        $suppressed = $handle('second');
        self::assertNull($this->outcome($suppressed));
        self::assertSame(['first'], $calls);
        self::assertCount(1, $this->intervals, 'suppressed calls re-arm nothing');

        $this->fireAll(); // window closes
        self::assertSame(['first'], $calls, 'leading-only mode drops the suppressed call');
    }

    public function testTrailingModeFiresTheLastSuppressedCallAtWindowEnd(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::throttle(
            static function (string $v) use (&$calls): string {
                $calls[] = $v;
                return $v;
            },
            0.05,
            $loop,
            trailing: true,
        );

        $handle('lead');
        $b = $handle('b');
        $c = $handle('c');
        self::assertTrue($handle->isPending());
        $this->fireAll();

        self::assertSame(['lead', 'c'], $calls);
        self::assertNull($this->outcome($b), 'earlier trailing candidates are superseded');
        self::assertSame('c', $this->outcome($c));
        self::assertFalse($handle->isPending());
    }

    public function testUnsubscribeCancelsTheQueuedTrailingCall(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::throttle(
            static function (string $v) use (&$calls): string {
                $calls[] = $v;
                return $v;
            },
            0.05,
            $loop,
            trailing: true,
        );

        $handle('lead');
        $queued = $handle('never');
        $handle->unsubscribe();

        self::assertNull($this->outcome($queued));
        self::assertFalse($handle->isActive());
        $this->fireAll();
        self::assertSame(['lead'], $calls, 'the queued trailing call was cancelled');
    }

    public function testGuardsRejectNonPositiveAndNonFiniteWindows(): void
    {
        foreach ([0.0, -1.0, \NAN, \INF] as $bad) {
            foreach (['debounce', 'throttle'] as $method) {
                try {
                    AsyncOps::{$method}(static function (): void {}, $bad, $this->clockwork());
                    self::fail('expected InvalidArgumentException for ' . $method . '(' . var_export($bad, true) . ')');
                } catch (\InvalidArgumentException $e) {
                    self::assertStringContainsString('positive finite', $e->getMessage());
                }
            }
        }
    }

    public function testRealLoopEndToEnd(): void
    {
        $loop = new StreamSelectLoop();
        $loop->addTimer(1.0, static fn() => $loop->stop());
        $calls = [];
        $handle = AsyncOps::debounce(
            static function (string $v) use (&$calls): string {
                $calls[] = $v;
                return $v;
            },
            0.03,
            $loop,
        );

        $outcome = null;
        $handle('a');
        $handle('b')->then(static function (mixed $value) use (&$outcome, $loop): void {
            $outcome = $value;
            $loop->stop();
        });
        $loop->run();

        self::assertSame(['b'], $calls);
        self::assertSame('b', $outcome);
    }

    public function testTrailingFireConsumesAFreshWindow(): void
    {
        // The double-fire repro (finding sugar-async-029): pre-fix the
        // cooldown callback cleared `cooling` and ran the trailing WITHOUT
        // re-arming, so a call at w+epsilon LED a second fire inside the
        // interval both docblocks promise caps at once per $seconds.
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::throttle(
            static function (string $v) use (&$calls): string {
                $calls[] = $v;
                return $v;
            },
            0.05,
            $loop,
            trailing: true,
        );

        $handle('lead');
        $handle('x');
        $this->step(); // edge w: 'x' fires as trailing, fresh window armed
        self::assertSame(['lead', 'x'], $calls);

        $y = $handle('y'); // lands at w+epsilon — must NOT lead a second fire
        self::assertSame(['lead', 'x'], $calls, 'the trailing fire consumed a fresh window');
        self::assertTrue($handle->isPending(), 'the w+epsilon call joins the trailing queue');

        $this->step(); // edge 2w: 'y' fires
        self::assertSame(['lead', 'x', 'y'], $calls);
        self::assertSame('y', $this->outcome($y));
    }

    public function testFlushRunsQueuedTrailingNowAndEndsTheCooldown(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::throttle(
            static function (string $v) use (&$calls): string {
                $calls[] = $v;
                return $v;
            },
            0.05,
            $loop,
            trailing: true,
        );

        $handle('lead');
        $queued = $handle('t');

        $handle->flush();

        self::assertSame(['lead', 't'], $calls);
        self::assertSame('t', $this->outcome($queued), 'the outcome arrives on the suppressed call promise');
        self::assertFalse($handle->isPending());
        self::assertSame([], $this->live, 'flush must release the armed cooldown timer');

        // The cooldown ended: the next call leads immediately.
        $handle('fresh');
        self::assertSame(['lead', 't', 'fresh'], $calls);
    }

    public function testFlushOnIdleStartsNothing(): void
    {
        $loop = $this->clockwork();
        $calls = [];
        $handle = AsyncOps::throttle(
            static function (string $v) use (&$calls): string {
                $calls[] = $v;
                return $v;
            },
            0.05,
            $loop,
            trailing: true,
        );

        $handle->flush(); // nothing queued, nothing cooling
        self::assertSame([], $calls);
        self::assertSame([], $this->live, 'an idle flush arms nothing');

        $handle('lead');
        $handle->flush(); // leading already fired; nothing was superseded
        self::assertSame(['lead'], $calls);
        self::assertSame([], $this->live, 'flush ends the cooldown even with nothing queued');
    }

    private function clockwork(): LoopInterface
    {
        $this->intervals = [];
        $this->live = [];

        $loop = $this->createMock(LoopInterface::class);
        $loop->method('addTimer')->willReturnCallback(
            function (float $interval, callable $callback): TimerInterface {
                $this->intervals[] = $interval;
                $timer = new class ($interval, $callback) implements TimerInterface {
                    /** @param callable(): mixed $cb */
                    public function __construct(
                        private readonly float $interval,
                        private readonly mixed $cb,
                    ) {
                    }

                    public function getInterval(): float
                    {
                        return $this->interval;
                    }

        public function isPeriodic(): bool
        {
            return false;
        }

                    public function getCallback(): mixed
                    {
                        return $this->cb;
                    }
                };
                $this->live[spl_object_id($timer)] = $timer;

                return $timer;
            },
        );
        $loop->method('cancelTimer')->willReturnCallback(function (TimerInterface $timer): void {
            unset($this->live[spl_object_id($timer)]);
        });

        return $loop;
    }

    private function fireAll(): void
    {
        while ($this->live !== []) {
            $this->step();
        }
    }

    /** Fire the earliest armed timer exactly once (a single window edge). */
    private function step(): void
    {
        $id = array_key_first($this->live);
        $timer = $this->live[$id];
        unset($this->live[$id]);
        ($timer->getCallback())();
    }

    private function outcome(PromiseInterface $promise): mixed
    {
        $seen = 'UNSET';
        $promise->then(static function (mixed $value) use (&$seen): void {
            $seen = $value;
        });
        self::assertNotSame('UNSET', $seen, 'promise expected to be settled already');

        return $seen;
    }

    private function rejectionMessage(PromiseInterface $promise): string
    {
        $message = 'UNSET';
        $promise->then(null, static function (\Throwable $error) use (&$message): void {
            $message = $error->getMessage();
        });
        self::assertNotSame('UNSET', $message, 'promise expected to be rejected already');

        return $message;
    }
}
