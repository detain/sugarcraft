<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\CancellationToken;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Async\TimeoutException;

/**
 * M1/M19: the wait wrappers must honor consumer cancellation.
 *
 * withTimeout/withDeadline/retry wrappers used to build their Deferreds
 * WITHOUT a canceller, so $promise->cancel() on the wrapper was a silent
 * no-op — the armed timer kept holding the loop and the caller's abandon
 * changed nothing. These pins exercise the abandonment paths end-to-end:
 * rejection shape, timer release (proved on a fresh loop with zero armed
 * timers), inner-cancel policy per helper, and a driver whose cancel()
 * explodes (must not escape the wrapper or poison the loop tick).
 *
 * @covers \SugarCraft\Async\AsyncOps
 */
final class AsyncOpsAbandonmentTest extends TestCase
{
    /**
     * Number of timers still armed on a fresh StreamSelectLoop. react/event-loop
     * 1.x keeps them in a private queue; an empty loop is the release proof.
     */
    private static function armedTimers(LoopInterface $loop): int
    {
        $property = new \ReflectionProperty(StreamSelectLoop::class, 'timers');
        $property->setAccessible(true);
        $timers = $property->getValue($loop);
        if (is_object($timers) && method_exists($timers, 'isEmpty')) {
            // react/event-loop 1.x Timers exposes isEmpty(); "armed" is the
            // load-bearing state — the ===0 release assertion stays exact.
            return $timers->isEmpty() ? 0 : 1;
        }
        return is_countable($timers) ? count($timers) : 0;
    }

    public function testAbandoningWithTimeoutReleasesTimerAndRejects(): void
    {
        $loop = new StreamSelectLoop();
        $innerCancelled = false;
        $inner = new Deferred(static function () use (&$innerCancelled): void {
            $innerCancelled = true;
        });

        $wrapped = AsyncOps::withTimeout($loop, $inner->promise(), 30.0);
        $this->assertSame(1, self::armedTimers($loop), 'the timeout timer is armed');

        $error = null;
        $wrapped->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $wrapped->cancel();

        $this->assertInstanceOf(OperationCancelledException::class, $error);
        $this->assertSame(0, self::armedTimers($loop), 'abandoning releases the timer');
        $this->assertFalse($innerCancelled, 'withTimeout abandons, it does not abort');
    }

    public function testLateInnerResultAfterAbandonIsDropped(): void
    {
        $loop = new StreamSelectLoop();
        $inner = new Deferred();
        $wrapped = AsyncOps::withTimeout($loop, $inner->promise(), 30.0);

        $resolved = null;
        $error = null;
        $wrapped->then(
            static function (mixed $v) use (&$resolved): void {
                $resolved = $v;
            },
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $wrapped->cancel();
        $inner->resolve('late');

        $this->assertNull($resolved, 'a late inner result must not resurrect the abandoned wait');
        $this->assertInstanceOf(OperationCancelledException::class, $error);
    }

    public function testAbandoningWithDeadlineRunsFullTeardown(): void
    {
        $loop = new StreamSelectLoop();
        $innerCancelled = false;
        $hookFired = 0;
        $inner = new Deferred(static function () use (&$innerCancelled): void {
            $innerCancelled = true;
        });

        $wrapped = AsyncOps::withDeadline($loop, $inner->promise(), 30.0, static function () use (&$hookFired): void {
            $hookFired++;
        });
        $this->assertSame(1, self::armedTimers($loop));

        $error = null;
        $wrapped->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $wrapped->cancel();

        $this->assertInstanceOf(OperationCancelledException::class, $error);
        $this->assertSame(1, $hookFired, 'abandon fires the abort hook — no work outlives the wait');
        $this->assertTrue($innerCancelled, 'withDeadline cancels the inner on abandon too');
        $this->assertSame(0, self::armedTimers($loop));
    }

    public function testExplodingInnerCancelDoesNotPoisonDeadlineTimerTick(): void
    {
        $loop = new StreamSelectLoop();
        // A driver that never settles and whose cancel() explodes. The mock
        // is generated from the REAL interface at runtime, so it cannot drift
        // the way the old hand-rolled PromiseInterface implementation could;
        // a react/promise Promise with a throwing canceller would NOT model
        // the scenario (v3 converts canceller throws into self-rejections, so
        // cancel() would never explode outward to the wrapper).
        $inner = $this->createMock(PromiseInterface::class);
        $inner->method('then')->willReturnSelf();
        $inner->method('cancel')->willThrowException(new \RuntimeException('driver abort exploded'));

        $error = null;
        AsyncOps::withDeadline($loop, $inner, 0.005)
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });

        // The exploding cancel lands INSIDE the timer callback. If it escaped,
        // run() would raise and this test would error instead of assert.
        $loop->addTimer(0.1, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertInstanceOf(TimeoutException::class, $error, 'the timeout rejection is still delivered');
    }

    public function testExplodingInnerCancelStillAbandonsWrappers(): void
    {
        // A driver that never settles and whose cancel() explodes. The mock
        // is generated from the REAL interface at runtime, so it cannot drift
        // the way the old hand-rolled PromiseInterface implementation could;
        // a react/promise Promise with a throwing canceller would NOT model
        // the scenario (v3 converts canceller throws into self-rejections, so
        // cancel() would never explode outward to the wrapper).
        $inner = $this->createMock(PromiseInterface::class);
        $inner->method('then')->willReturnSelf();
        $inner->method('cancel')->willThrowException(new \RuntimeException('driver abort exploded'));

        $deadlineError = null;
        $deadline = AsyncOps::withDeadline(new StreamSelectLoop(), $inner, 30.0);
        $deadline->then(null, static function (\Throwable $e) use (&$deadlineError): void {
            $deadlineError = $e;
        });
        $deadline->cancel();
        $this->assertInstanceOf(OperationCancelledException::class, $deadlineError);

        $source = CancellationSource::new();
        $cancError = null;
        $canc = AsyncOps::cancellable($inner, $source->token());
        $canc->then(null, static function (\Throwable $e) use (&$cancError): void {
            $cancError = $e;
        });
        $canc->cancel();
        $this->assertInstanceOf(OperationCancelledException::class, $cancError, 'cancellable survives a throwing driver abort on the wrapper path');

        $source2 = CancellationSource::new();
        $cancError2 = null;
        $canc2 = AsyncOps::cancellable($inner, $source2->token());
        $canc2->then(null, static function (\Throwable $e) use (&$cancError2): void {
            $cancError2 = $e;
        });
        $source2->cancel();
        $this->assertInstanceOf(OperationCancelledException::class, $cancError2, 'and on the token path');
    }

    public function testAbandoningRetryStopsWholeCycle(): void
    {
        $loop = new StreamSelectLoop();
        $calls = 0;
        $cancelledOps = [];

        $operation = static function () use (&$calls, &$cancelledOps): PromiseInterface {
            $calls++;
            $n = $calls;
            return (new Deferred(static function () use (&$cancelledOps, $n): void {
                $cancelledOps[$n] = true;
            }))->promise();
        };

        $wrapped = AsyncOps::retry($operation, attempts: 5, baseBackoffSeconds: 10.0, loop: $loop);

        $error = null;
        $wrapped->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $wrapped->cancel();

        $this->assertInstanceOf(OperationCancelledException::class, $error);
        $this->assertSame(1, $calls, 'no further attempt is scheduled after abandonment');
        $this->assertTrue($cancelledOps[1] ?? false, 'the in-flight attempt is cancel()ed');
        $this->assertSame(0, self::armedTimers($loop), 'nothing keeps the loop');
    }

    public function testTokenCancelDuringBackoffReleasesLoopAndStops(): void
    {
        $loop = new StreamSelectLoop();
        $calls = 0;
        $source = CancellationSource::new();

        $operation = static function () use (&$calls): PromiseInterface {
            $calls++;
            $d = new Deferred();
            $d->reject(new \RuntimeException('attempt failed'));
            return $d->promise();
        };

        $wrapped = AsyncOps::retry(
            $operation,
            attempts: 2,
            baseBackoffSeconds: 30.0,
            token: $source->token(),
            loop: $loop,
        );

        $this->assertSame(1, $calls, 'first attempt failed synchronously, backoff armed');
        $this->assertSame(1, self::armedTimers($loop));

        $error = null;
        $wrapped->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $source->cancel();

        $this->assertInstanceOf(OperationCancelledException::class, $error);
        $this->assertSame(0, self::armedTimers($loop), 'the external token reaches the backoff timer via the stop source');
        $this->assertSame(1, $calls);
    }

    public function testRetryStillResolvesThroughTheAbandonmentWrapper(): void
    {
        $loop = new StreamSelectLoop();
        $calls = 0;

        $operation = static function () use (&$calls): PromiseInterface {
            $calls++;
            $d = new Deferred();
            if ($calls === 1) {
                $d->reject(new \RuntimeException('first attempt failed'));
            } else {
                $d->resolve('second wins');
            }
            return $d->promise();
        };

        $resolved = null;
        AsyncOps::retry($operation, attempts: 3, baseBackoffSeconds: 0.01, loop: $loop)
            ->then(static function (mixed $v) use (&$resolved): void {
                $resolved = $v;
            });

        $loop->addTimer(0.5, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        $this->assertSame('second wins', $resolved);
        $this->assertSame(2, $calls);
    }

    public function testSharedFlightCancelIsADocumentedNoOp(): void
    {
        $worker = new Deferred();
        $flighted = AsyncOps::singleFlight(
            static fn (): string => 'k',
            static fn (): PromiseInterface => $worker->promise(),
        );

        $resolved = null;
        $error = null;
        $flight = $flighted();
        $flight->then(
            static function (mixed $v) use (&$resolved): void {
                $resolved = $v;
            },
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        // One caller abandoning must not reject every coalesced caller.
        $flight->cancel();
        $worker->resolve('survives');

        $this->assertSame('survives', $resolved);
        $this->assertNull($error);
    }

    /**
     * Number of callbacks still registered on a token — the registration-count
     * probe (the callback list is private; reflection is the measurement
     * seam, mirroring armedTimers() on the loop side).
     */
    private static function pendingRegistrations(CancellationToken $token): int
    {
        $property = new \ReflectionProperty(CancellationToken::class, 'callbacks');
        $property->setAccessible(true);
        /** @var array<int, callable(): void> $callbacks */
        $callbacks = $property->getValue($token);

        return count($callbacks);
    }

    public function testAbandoningCancellableDropsItsTokenRegistration(): void
    {
        // Leak census (five abandons against a driver that never settles):
        // pre-fix each wrapper left its full closure chain on the token for
        // the token's life, because only the settle handlers unsubscribed —
        // and a hung inner never reaches them.
        $source = CancellationSource::new();
        $token = $source->token();

        for ($i = 0; $i < 5; $i++) {
            $inner = new Deferred(); // no canceller: ignores cancel(), never settles
            $wrapped = AsyncOps::cancellable($inner->promise(), $token);
            $wrapped->then(null, static function (\Throwable $e): void {
                // The abandonment rejection is expected; nothing to observe.
            });
            $wrapped->cancel();
        }

        $this->assertSame(0, self::pendingRegistrations($token), 'abandoned wrappers must not accumulate on a long-lived token');
    }

    public function testCooperativeCancellableSettlementDropsItsTokenRegistration(): void
    {
        // Control for the same census: a driver that settles normally.
        $source = CancellationSource::new();
        $token = $source->token();

        for ($i = 0; $i < 5; $i++) {
            $inner = new Deferred();
            $wrapped = AsyncOps::cancellable($inner->promise(), $token);
            $wrapped->then(null, static function (\Throwable $e): void {
            });
            $inner->resolve('done');
            unset($wrapped);
        }

        $this->assertSame(0, self::pendingRegistrations($token));
    }

    public function testCancellableLabelRidesTheRejectionAsData(): void
    {
        $source = CancellationSource::new();
        $inner = new Deferred();

        $error = null;
        AsyncOps::cancellable($inner->promise(), $source->token(), label: 'user-sync')
            ->then(null, static function (\Throwable $e) use (&$error): void {
                $error = $e;
            });

        $source->cancel();

        $this->assertInstanceOf(OperationCancelledException::class, $error);
        $this->assertSame('user-sync', $error->label(), 'the label arrives as data, not message archaeology');
        $this->assertStringContainsString('user-sync', $error->getMessage());
    }
}
