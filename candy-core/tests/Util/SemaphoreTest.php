<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Exception\SemaphoreClosedException;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\Util\Semaphore;

/**
 * Covers the permit pool itself (cap, FIFO fairness, release idempotency) and
 * the two lifecycle hazards the promotion had to fix: a synchronously throwing
 * task leaking its permit, and a dropped pool leaving waiters pending forever.
 */
final class SemaphoreTest extends TestCase
{
    private string $locale = 'en';

    protected function setUp(): void
    {
        // The exception-message assertions are written in English. Pin the
        // locale so a test that runs before this one and leaves it flipped
        // cannot redden them, and so they stay honest once candy-core's keys
        // are mirrored into the translated locale files.
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
    }

    public function testNewAdmitsLimitsOfOneOrMore(): void
    {
        self::assertSame(1, Semaphore::new(1)->limit());
        self::assertSame(6, Semaphore::new(6)->limit());
        self::assertSame(PHP_INT_MAX, Semaphore::new(PHP_INT_MAX)->limit());
    }

    public function testNewRejectsZeroLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('semaphore limit must be at least 1, got 0');

        Semaphore::new(0);
    }

    public function testNewRejectsNegativeLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('semaphore limit must be at least 1, got -3');

        Semaphore::new(-3);
    }

    public function testFreshPoolIsEntirelyAvailable(): void
    {
        $sem = Semaphore::new(3);

        self::assertSame(3, $sem->limit());
        self::assertSame(0, $sem->waiting());
        self::assertSame(0, $sem->running());
        self::assertSame(3, $sem->available());
    }

    public function testAcquireResolvesImmediatelyWhileCapacityRemains(): void
    {
        $sem = Semaphore::new(2);
        $granted = 0;

        // acquire() resolves synchronously when a slot is open, so the handler
        // has already run by the time the promise comes back.
        $sem->acquire()->then(function (Semaphore $pool) use ($sem, &$granted): void {
            self::assertSame($sem, $pool, 'the permit resolves with the pool that granted it');
            $granted++;
        });
        $sem->acquire()->then(function () use (&$granted): void {
            $granted++;
        });

        self::assertSame(2, $granted);
        self::assertSame(2, $sem->running());
        self::assertSame(0, $sem->available());
        self::assertSame(0, $sem->waiting());
    }

    public function testWaitersBeyondTheLimitParkInFifoOrder(): void
    {
        $sem = Semaphore::new(1);
        $order = [];

        $sem->acquire();
        $sem->acquire()->then(function () use (&$order): void {
            $order[] = 'second';
        });
        $sem->acquire()->then(function () use (&$order): void {
            $order[] = 'third';
        });

        self::assertSame(2, $sem->waiting());
        self::assertSame(1, $sem->running());
        self::assertSame(0, $sem->available());
        self::assertSame([], $order, 'queued waiters must not run ahead of the holder');

        $sem->release();
        self::assertSame(['second'], $order);

        $sem->release();
        self::assertSame(['second', 'third'], $order);
        self::assertSame(0, $sem->waiting());

        // Each release handed the slot to the next waiter instead of opening it,
        // so the last caller still holds the only permit.
        self::assertSame(1, $sem->running());
        self::assertSame(0, $sem->available());

        $sem->release();
        self::assertSame(0, $sem->running());
        self::assertSame(1, $sem->available());
    }

    public function testReleaseIsIdempotentAtTheFloor(): void
    {
        $sem = Semaphore::new(2);

        // A stray release with nothing held must not manufacture capacity,
        // and must not wake anyone.
        $sem->release();
        $sem->release();
        $sem->release();
        self::assertSame(2, $sem->available());
        self::assertSame(0, $sem->running());

        $sem->acquire();
        $sem->acquire();
        self::assertSame(0, $sem->available());

        $sem->release();
        $sem->release();
        $sem->release();

        // Overshooting by two would report three free slots on a cap of two.
        self::assertSame(2, $sem->available());
        self::assertSame(0, $sem->running());
    }

    public function testReleasedPermitIsReusedByTheNextWaiter(): void
    {
        $sem = Semaphore::new(1);
        $secondRan = false;

        $sem->acquire();
        $sem->acquire()->then(function () use (&$secondRan): void {
            $secondRan = true;
        });
        $sem->release();

        self::assertTrue($secondRan);
        self::assertSame(1, $sem->running(), 'the slot moved to the waiter, it did not open up');
    }

    public function testWithLimitDerivesAFreshEmptyPool(): void
    {
        $sem = Semaphore::new(2);
        $wider = $sem->withLimit(5);

        self::assertNotSame($sem, $wider, 'withLimit() is immutable');
        self::assertSame(5, $wider->limit());
        self::assertSame(2, $sem->limit(), 'the receiver keeps its cap');
        self::assertSame(5, $wider->available());
    }

    public function testWithLimitValidatesTheNewLimit(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('semaphore limit must be at least 1, got 0');

        Semaphore::new(4)->withLimit(0);
    }

    public function testWithLimitRefusesToStrandLivePermits(): void
    {
        $sem = Semaphore::new(1);
        $failure = null;

        $sem->acquire();
        $sem->acquire()->then(null, function (\Throwable $reason) use (&$failure): void {
            $failure = $reason;
        });

        try {
            $sem->withLimit(8);
            self::fail('a pool with permits outstanding must refuse to derive a new limit');
        } catch (\LogicException $e) {
            self::assertSame(
                'semaphore is busy: 1 running, 1 waiting',
                $e->getMessage(),
            );
        }

        // Refusing is not the same as breaking: the parked waiter is still parked.
        self::assertSame(1, $sem->waiting());
        self::assertNull($failure);

        $sem->release();
        self::assertNull($failure, 'the waiter must still be served normally after the refused derive');
        self::assertSame(0, $sem->waiting());
    }

    public function testRunWithPendingTasksHoldsTheCeiling(): void
    {
        $sem = Semaphore::new(2);
        $started = [];
        /** @var list<Deferred<string>> $gates */
        $gates = [];

        $promises = [];
        foreach (['a', 'b', 'c', 'd'] as $label) {
            $gates[$label] = new Deferred();
            $promises[$label] = $sem->run(function () use ($label, &$started, $gates): PromiseInterface {
                $started[] = $label;

                return $gates[$label]->promise();
            });
        }

        self::assertSame(['a', 'b'], $started, 'only limit() tasks may be in flight');
        self::assertSame(2, $sem->running());
        self::assertSame(2, $sem->waiting());

        $seen = [];
        foreach ($promises as $label => $promise) {
            $promise->then(function (string $value) use (&$seen, $label): void {
                $seen[] = $label . '=' . $value;
            });
        }

        $gates['a']->resolve('A');
        self::assertSame(['a', 'b', 'c'], $started, 'settling a task must admit the next waiter');
        self::assertSame(2, $sem->running(), 'the permit passed straight to the waiter');
        self::assertSame(['a=A'], $seen);

        $gates['b']->resolve('B');
        self::assertSame(['a', 'b', 'c', 'd'], $started);

        $gates['c']->resolve('C');
        $gates['d']->resolve('D');

        self::assertSame(['a=A', 'b=B', 'c=C', 'd=D'], $seen);
        self::assertSame(0, $sem->running());
        self::assertSame(2, $sem->available());
    }

    public function testRunResolvesWithValueFromAPendingPromise(): void
    {
        $sem = Semaphore::new(1);
        $gate = new Deferred();
        $resolved = null;

        $sem->run(fn (): PromiseInterface => $gate->promise())->then(
            function (string $value) use (&$resolved): void {
                $resolved = $value;
            },
        );

        self::assertNull($resolved);
        $gate->resolve('payload');
        self::assertSame('payload', $resolved);
    }

    public function testRunPropagatesRejectionAndFreesThePermit(): void
    {
        $sem = Semaphore::new(1);
        $gate = new Deferred();
        $failure = null;

        $sem->run(fn (): PromiseInterface => $gate->promise())->then(
            null,
            function (\Throwable $reason) use (&$failure): void {
                $failure = $reason;
            },
        );

        $boom = new \RuntimeException('upstream 500');
        $gate->reject($boom);

        self::assertSame($boom, $failure);
        self::assertSame(1, $sem->available(), 'a rejected task must not keep its permit');
    }

    public function testRunReturnsPermitWhenTaskThrowsSynchronously(): void
    {
        $sem = Semaphore::new(1);
        $failure = null;

        $sem->run(function (): string {
            throw new \LogicException('bad arguments');
        })->then(null, function (\Throwable $reason) use (&$failure): void {
            $failure = $reason;
        });

        self::assertInstanceOf(\LogicException::class, $failure);
        self::assertSame('bad arguments', $failure?->getMessage());
        self::assertSame(1, $sem->available(), 'the client original leaked the permit here');
        self::assertSame(0, $sem->waiting());
    }

    public function testRunAcceptsAPlainValueFromTheTask(): void
    {
        $sem = Semaphore::new(1);
        $resolved = null;

        $sem->run(fn (): int => 42)->then(function (int $value) use (&$resolved): void {
            $resolved = $value;
        });

        self::assertSame(42, $resolved);
        self::assertSame(1, $sem->available());
    }

    public function testRunOfASinglePermitPoolSerialisesEverything(): void
    {
        $sem = Semaphore::new(1);
        $log = [];

        foreach (range(1, 4) as $n) {
            $sem->run(function () use ($n, &$log): string {
                $log[] = 'start' . $n;
                $log[] = 'end' . $n;

                return (string) $n;
            });
        }

        self::assertSame(['start1', 'end1', 'start2', 'end2', 'start3', 'end3', 'start4', 'end4'], $log);
    }

    public function testDestructFailsQueuedWaitersInsteadOfHangingThem(): void
    {
        $sem = Semaphore::new(1);
        $sem->acquire();

        $failure = null;
        $sem->acquire()->then(null, function (\Throwable $reason) use (&$failure): void {
            $failure = $reason;
        });

        unset($sem);

        self::assertInstanceOf(SemaphoreClosedException::class, $failure);
        self::assertSame('semaphore closed before a permit could be granted', $failure?->getMessage());
    }

    public function testCloseFailsParkedRunWorkWithoutWaitingForGc(): void
    {
        $sem = Semaphore::new(1);
        $sem->acquire();

        $failure = null;
        $sem->run(fn (): string => 'never starts')->then(null, function (\Throwable $r) use (&$failure): void {
            $failure = $r;
        });

        self::assertSame(1, $sem->waiting());

        // The parked run() installs a handler that references the pool, so
        // unsetting it alone leaves the destructor waiting on the cycle
        // collector — close() is what makes the failure deterministic.
        $sem->close();

        self::assertInstanceOf(SemaphoreClosedException::class, $failure);
        self::assertSame(0, $sem->waiting());
        self::assertTrue($sem->closed());
    }

    public function testCloseRefusesFurtherPermitsAndIsIdempotent(): void
    {
        $sem = Semaphore::new(2);
        $sem->close();
        $sem->close();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('semaphore is closed and cannot grant another permit');

        $sem->acquire();
    }

    public function testRunOnAClosedPoolThrowsInsteadOfReturningAHangingPromise(): void
    {
        $sem = Semaphore::new(1);
        $sem->close();

        $this->expectException(\LogicException::class);

        $sem->run(fn (): string => 'too late');
    }

    public function testCloseLeavesInFlightWorkToSettleNormally(): void
    {
        $sem = Semaphore::new(1);
        $gate = new Deferred();
        $resolved = null;

        $sem->run(fn (): PromiseInterface => $gate->promise())->then(
            function (string $value) use (&$resolved): void {
                $resolved = $value;
            },
        );

        $sem->close();
        self::assertNull($resolved, 'closing stops new work, it does not cancel running work');

        $gate->resolve('finished anyway');
        self::assertSame('finished anyway', $resolved);
    }

    public function testAHandlerThrowingDuringCloseCannotHalfRejectTheQueue(): void
    {
        $sem = Semaphore::new(1);
        $sem->acquire(); // saturate so both waiters below park

        // The first parked waiter's rejection handler explodes; react/promise
        // catches handler throws into the derived promise, so the close()
        // cascade must keep going and the second waiter must still be failed.
        $sem->acquire()->then(null, function (): void {
            throw new \DomainException('handler exploded');
        });

        $secondFailure = null;
        $sem->acquire()->then(null, function (\Throwable $r) use (&$secondFailure): void {
            $secondFailure = $r;
        });

        $sem->close();

        self::assertInstanceOf(
            SemaphoreClosedException::class,
            $secondFailure,
            'one bad handler must not strand the rest of the queue',
        );
        self::assertSame(0, $sem->waiting());
        self::assertTrue($sem->closed());
    }

    public function testReEnteringThePoolFromAWaiterRejectionHandlerIsRefusedCleanly(): void
    {
        $sem = Semaphore::new(1);
        $sem->acquire(); // saturate

        $reentrantError = null;
        $secondFailure = null;

        // Woken by close(), this handler re-enters the pool: a fresh acquire()
        // must be refused synchronously (closed is already set before the reject
        // loop starts) and a redundant close() must be a no-op — neither may
        // interrupt the cascade feeding the waiter behind it.
        $sem->acquire()->then(null, function (\Throwable $r) use ($sem, &$reentrantError): void {
            try {
                $sem->acquire();
            } catch (\LogicException $e) {
                $reentrantError = $e;
            }
            $sem->close();
        });
        $sem->acquire()->then(null, function (\Throwable $r) use (&$secondFailure): void {
            $secondFailure = $r;
        });

        $sem->close();

        self::assertInstanceOf(\LogicException::class, $reentrantError);
        self::assertSame('semaphore is closed and cannot grant another permit', $reentrantError?->getMessage());
        self::assertInstanceOf(SemaphoreClosedException::class, $secondFailure);
        self::assertSame(0, $sem->waiting());
    }

    public function testASpuriousReleaseMidDrainCannotOversellTheCeiling(): void
    {
        $sem = Semaphore::new(1);
        $sem->acquire(); // one permit outstanding

        $order = [];
        $availableInsideHandler = null;

        $sem->acquire()->then(function () use ($sem, &$order, &$availableInsideHandler): void {
            $order[] = 'b';
            // Return our own permit while the dispatch loop is still iterating
            // (the re-entrancy flag parks the nested dispatch), then release a
            // second time with the pool already at floor.
            $sem->release();
            $sem->release();
            $availableInsideHandler = $sem->available();
        });
        $sem->acquire()->then(function () use (&$order): void {
            $order[] = 'c';
        });

        $sem->release(); // free the original permit: drain runs b, then grants c

        self::assertSame(['b', 'c'], $order, 'the freed permit went to the next waiter exactly once');
        self::assertSame(1, $availableInsideHandler, 'the floor guard keeps available() <= limit() mid-drain');
        self::assertSame(1, $sem->running(), 'the extra release must not have minted a second grantable slot');
        self::assertSame(0, $sem->available());
        self::assertSame(0, $sem->waiting());
    }

    public function testThePoolCannotBeClonedIntoASecondBudget(): void
    {
        $reflect = new \ReflectionMethod(Semaphore::class, '__clone');
        self::assertTrue($reflect->isPrivate(), 'two copies of a lock would each carry a full budget');

        // Behavioural, not just declarative: a private __clone makes `clone` a
        // runtime Error, so this catches any future widening of visibility that
        // would quietly hand out a second permit budget.
        $sem = Semaphore::new(2);
        $this->expectException(\Error::class);
        clone $sem;
    }

    public function testBadLimitIsReportedAsBadInputEvenOnABusyPool(): void
    {
        $sem = Semaphore::new(1);
        $sem->acquire();

        // Validation comes before the busy check, so a typo reads as a typo.
        $this->expectException(\InvalidArgumentException::class);

        $sem->withLimit(-1);
    }

    public function testWithLimitRefusesToReviveAClosedPool(): void
    {
        $sem = Semaphore::new(2);
        $sem->close();

        // A drained-then-closed pool passes the "busy" check, so the closed guard
        // is what stops it minting a live budget through the side door.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot derive a new pool from a closed semaphore');

        $sem->withLimit(8);
    }

    public function testAShutdownRejectionIsDistinguishableFromATaskRejection(): void
    {
        $sem = Semaphore::new(1);
        $sem->acquire(); // hold the only permit so the run() below parks

        $shutdownFailure = null;
        $sem->run(fn (): string => 'never runs')->then(null, function (\Throwable $r) use (&$shutdownFailure): void {
            $shutdownFailure = $r;
        });

        $sem->close();

        self::assertInstanceOf(SemaphoreClosedException::class, $shutdownFailure);

        // A task that fails on its own must NOT arrive as a shutdown signal, or a
        // caller written to swallow teardown errors during shutdown would also
        // swallow real failures.
        $taskFailure = null;
        $sem2 = Semaphore::new(1);
        $sem2->run(static fn () => throw new \DomainException('task said no'))
            ->then(null, function (\Throwable $r) use (&$taskFailure): void {
                $taskFailure = $r;
            });

        self::assertInstanceOf(\DomainException::class, $taskFailure);
        self::assertNotInstanceOf(SemaphoreClosedException::class, $taskFailure);
    }

    public function testThePermitIsBackBeforeTheCompletionHandlerRuns(): void
    {
        $sem = Semaphore::new(1);
        $observed = [];

        $first = $sem->run(fn (): string => 'one');
        $first->then(function () use ($sem, &$observed): void {
            $observed['running'] = $sem->running();
            $observed['available'] = $sem->available();
            // Retuning from a completion handler must not see a phantom permit.
            $observed['derived'] = $sem->withLimit(3)->limit();
        });

        self::assertSame(['running' => 0, 'available' => 1, 'derived' => 3], $observed);
    }

    public function testDestructLeavesInFlightWorkAlone(): void
    {
        $sem = Semaphore::new(1);
        $gate = new Deferred();
        $resolved = null;

        $sem->run(fn (): PromiseInterface => $gate->promise())->then(
            function (string $value) use (&$resolved): void {
                $resolved = $value;
            },
        );
        $sem->acquire()->then(null, function () use (&$resolved): void {
            $resolved = 'should not happen';
        });

        // The in-flight task's closure keeps the pool alive, so dropping the
        // local variable here destroys nothing yet.
        unset($sem);
        self::assertNull($resolved);

        $gate->resolve('done');
        self::assertSame('done', $resolved);
    }

    public function testDrainingADeepQueueCostsConstantStackDepth(): void
    {
        $sem = Semaphore::new(1);
        $sem->acquire(); // park the only permit before anyone else arrives
        $order = [];
        $depth = 0;
        $peakDepth = 0;

        // The real regression this guards is the dispatch loop recursing per
        // hand-off (see Semaphore::dispatch()'s non-re-entrancy flag): react
        // settles synchronously, so a nested dispatch() would burn PHP frames —
        // not nested TASK bodies — and dumped core somewhere around 5k tasks.
        // Task-body nesting stays 1 either way, so `peakDepth` alone cannot see
        // it. Measure the actual call-frame depth at the first and last grant.
        $frames = [];

        foreach (range(1, 3000) as $n) {
            $sem->run(function () use ($n, &$order, &$depth, &$peakDepth, &$frames): int {
                $depth++;
                $peakDepth = max($peakDepth, $depth);
                $order[] = $n;

                // Sampled at a fixed point in the body so both readings see the
                // same lexical depth; only the drain's recursion would differ.
                if ($n === 1 || $n === 3000) {
                    $frames[$n] = count(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS));
                }

                $depth--;

                return $n;
            });
        }

        self::assertSame(3000, $sem->waiting());

        // One release has to drain the whole queue.
        $sem->release();

        self::assertSame(range(1, 3000), $order, 'the flat drain must still be strictly FIFO');
        self::assertSame(1, $peakDepth, 'a nested task body would mean two permits were live on a limit of 1');
        self::assertSame(0, $sem->waiting());
        self::assertSame(0, $sem->running());

        self::assertArrayHasKey(1, $frames);
        self::assertArrayHasKey(3000, $frames);
        // Constant stack means the last grant is no deeper in PHP frames than the
        // first. A recursive drain grows by one frame-set per hand-off, so this
        // delta would be in the thousands, not a handful.
        self::assertLessThanOrEqual(
            2,
            abs($frames[3000] - $frames[1]),
            "drain depth grew from {$frames[1]} to {$frames[3000]} frames — the grant loop is recursing again",
        );
    }

    public function testReentrantAcquireAndReleaseNeverBreachTheCeiling(): void
    {
        $sem = Semaphore::new(2);
        $peak = 0;
        $started = 0;

        foreach (range(1, 50) as $n) {
            $sem->run(function () use ($sem, $n, &$peak, &$started): int {
                $started++;
                $peak = max($peak, $sem->running());

                // Grab and return a second permit from inside a running task, so
                // a grant happens in the middle of the drain loop.
                $sem->acquire()->then(function () use ($sem, &$peak): void {
                    $peak = max($peak, $sem->running());
                    $sem->release();
                });

                return $n;
            });
        }

        self::assertSame(50, $started);
        self::assertLessThanOrEqual(2, $peak, 'limit() must hold even against re-entrant grants');
        self::assertSame(0, $sem->running());
        self::assertSame(0, $sem->waiting());
    }

    public function testAQueuedTaskThatRejectsStillFreesTheSlotForTheRest(): void
    {
        $sem = Semaphore::new(1);
        $gate = new Deferred();
        $seen = [];

        $sem->run(fn (): PromiseInterface => $gate->promise())->then(
            null,
            function () use (&$seen): void {
                $seen[] = 'first rejected';
            },
        );
        $sem->run(fn (): string => 'second')->then(function (string $v) use (&$seen): void {
            $seen[] = $v;
        });

        self::assertSame(1, $sem->waiting());

        $gate->reject(new \RuntimeException('upstream died'));

        // The freed slot is handed to the next waiter before the failure is
        // reported to this task's consumer — see the ordering note on run().
        self::assertSame(['second', 'first rejected'], $seen);
        self::assertSame(1, $sem->available());
        self::assertSame(0, $sem->waiting());
    }

    public function testOrderingHoldsWhenTasksSettleOnTheEventLoop(): void
    {
        $loop = new StreamSelectLoop();
        $sem = Semaphore::new(2);
        $started = [];
        $finished = [];

        $this->settleOnLoop($loop, $sem, $started, $finished);

        $loop->run();

        self::assertSame(['t1', 't2', 't3', 't4'], $started, 'waiters were admitted strictly FIFO');
        self::assertSame(['t1', 't2', 't3', 't4'], $finished);
        self::assertSame(0, $sem->running());
        self::assertSame(2, $sem->available());
        self::assertSame(0, $sem->waiting());
    }

    /**
     * Arm four tasks that each hand their permit back from a loop timer, so the
     * dispatch path is exercised across real loop iterations rather than only
     * through synchronous resolution.
     */
    private function settleOnLoop(LoopInterface $loop, Semaphore $sem, array &$started, array &$finished): void
    {
        foreach (['t1', 't2', 't3', 't4'] as $i => $label) {
            $sem->run(function () use ($loop, $label, $i, &$started, &$finished): PromiseInterface {
                $started[] = $label;
                $gate = new Deferred();

                // Staggered deadlines make a cap breach observable: t3 and t4
                // can only settle on time if they never start before a slot frees.
                $loop->addTimer(0.001 * ($i + 1), function () use ($gate, $label, &$finished): void {
                    $finished[] = $label;
                    $gate->resolve($label);
                });

                return $gate->promise();
            });
        }
    }
}
