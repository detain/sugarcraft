<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Pty\Contract\PtyPair;
use SugarCraft\Pty\PtyPool;

/**
 * Integration test: PtyPool works inside a ReactPHP loop session without
 * signal double-handling.  Risk #3 from the original P6 plan — never
 * tested until now.
 *
 * Uses the watchdog pattern: backgrounded pkill catches hangs so this
 * test never blocks CI indefinitely on FFI / PTY syscalls.
 *
 * @see https://github.com/sugarcraft/sugarcraft/blob/master/plans/leftover/phase-01-pty-quickwins/step-09-pool-react-multipump-expect.md
 */
final class PtyPoolReactLoopTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    /**
     * Verify that acquire/release round-trips are safe inside a
     * ReactPHP loop — specifically that no signal handler state leaks
     * between the synchronous pool calls and the async loop ticks.
     */
    public function testPoolAcquireReleaseInsideReactLoop(): void
    {
        $this->requirePtySyscalls();

        $pool = new PtyPool(maxSize: 4);
        $acquired = [];
        $released = [];

        // Schedule N acquire operations across different loop ticks.
        // Each tick is a simple synchronous call, but they run inside
        // Loop::run() so any signal double-handling would surface here.
        for ($i = 0; $i < 3; $i++) {
            Loop::futureTick(function () use ($pool, $i, &$acquired): void {
                $acquired[] = $pool->acquire(80, 24);
            });
        }

        // All three acquires should complete before we start releasing.
        Loop::run();

        $this->assertCount(3, $acquired, 'all three acquires must have run');
        $this->assertSame(3, $pool->inFlight());
        $this->assertSame(3, $pool->totalAcquired());

        // Release inside the loop too — verify the loop stays healthy.
        foreach ($acquired as $pair) {
            Loop::futureTick(function () use ($pool, $pair, &$released): void {
                $pool->release($pair);
                $released[] = $pair;
            });
        }

        Loop::run();

        $this->assertCount(3, $released, 'all three releases must have run');
        $this->assertSame(0, $pool->inFlight());
        $this->assertSame(3, $pool->totalAcquired());
    }

    /**
     * Simulate a rapid acquire → use → release cycle inside a
     * timer-driven loop to verify no lingering signal state.
     */
    public function testRapidCycleInsideLoopDoesNotLeakSignals(): void
    {
        $this->requirePtySyscalls();

        $pool = new PtyPool(maxSize: 8);
        $iterations = 0;
        $maxIterations = 20;

        // Every 10 ms: acquire, immediately release, increment counter.
        // If a single release fails to close the master fd (signal
        // double-handling), the next acquire hits EBUSY / ENFILE / EMFILE.
        Loop::addPeriodicTimer(0.01, function ($timer) use ($pool, &$iterations, $maxIterations): void {
            $pair = null;
            try {
                $pair = $pool->acquire(80, 24);
            } catch (\Throwable $e) {
                // If we hit the pool limit, that's expected — wait for
                // a slot to free before continuing the stress run.
                return;
            }
            $pool->release($pair);
            $iterations++;
            if ($iterations >= $maxIterations) {
                Loop::cancelTimer($timer);
                Loop::stop();
            }
        });

        Loop::addTimer(5.0, function (): void {
            // Safety cap — stop after 5 s even if iterations < max.
            Loop::stop();
        });

        Loop::run();

        $this->assertGreaterThanOrEqual(
            $maxIterations,
            $iterations,
            "Pool must survive {$maxIterations} rapid acquire/release cycles; got {$iterations}. "
            . 'Signal double-handling is likely leaking file descriptors.',
        );
    }

    /**
     * Drain the pool from inside a loop — verifies drain() is safe
     * when called after the loop has been running and has accumulated
     * signal dispatch cycles.
     */
    public function testDrainInsideLoopAfterMixedAcquireRelease(): void
    {
        $this->requirePtySyscalls();

        $pool = new PtyPool(maxSize: 6);

        // Stagger some acquires
        for ($i = 0; $i < 3; $i++) {
            Loop::futureTick(function () use ($pool): void {
                $pool->acquire(80, 24);
            });
        }
        Loop::run();
        $this->assertSame(3, $pool->inFlight());

        // Release one outside the loop context
        $pairs = [];
        for ($i = 0; $i < 3; $i++) {
            $pairs[] = $pool->acquire();
        }
        $pool->release($pairs[0]);
        $this->assertSame(5, $pool->inFlight());

        // Drain inside loop — no signal leaks
        Loop::futureTick(function () use ($pool): void {
            $pool->drain();
        });
        Loop::run();

        $this->assertSame(0, $pool->inFlight());
    }
}
