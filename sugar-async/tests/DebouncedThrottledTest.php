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
            $id = array_key_first($this->live);
            $timer = $this->live[$id];
            unset($this->live[$id]);
            ($timer->getCallback())();
        }
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
