<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Shell\Runtime\TimeoutGuard;

/**
 * Deterministic timing tests for {@see TimeoutGuard} driven by a real
 * {@see StreamSelectLoop}. Delays are kept tiny (20-50ms) so the suite stays
 * fast without racing the scheduler.
 */
final class TimeoutGuardTest extends TestCase
{
    public function testExitTimeoutMatchesGnuConvention(): void
    {
        // GNU timeout(1) uses 124 for "the deadline elapsed"; callers depend
        // on this value to distinguish a timeout from a user abort.
        $this->assertSame(124, TimeoutGuard::EXIT_TIMEOUT);
    }

    public function testFiresAfterDeadline(): void
    {
        $loop = new StreamSelectLoop();
        $ran  = false;
        $guard = TimeoutGuard::arm($loop, 0.02, function () use (&$ran): void {
            $ran = true;
        });

        $loop->run();

        $this->assertTrue($guard->fired());
        $this->assertTrue($ran, 'onFire must run when the deadline elapses');
    }

    public function testZeroSecondsIsInert(): void
    {
        $loop = new StreamSelectLoop();
        $ran  = false;
        $guard = TimeoutGuard::arm($loop, 0.0, function () use (&$ran): void {
            $ran = true;
        });

        // The guard scheduled nothing (0 = no limit), so give the loop its own
        // reason to stop and confirm the deadline callback never runs.
        $loop->addTimer(0.02, static fn () => null);
        $loop->run();

        $this->assertFalse($guard->fired());
        $this->assertFalse($ran, 'onFire must never run for a zero-second guard');
    }

    public function testDisarmBeforeDeadlineCancelsTimer(): void
    {
        $loop = new StreamSelectLoop();
        $ran  = false;
        $guard = TimeoutGuard::arm($loop, 0.05, function () use (&$ran): void {
            $ran = true;
        });

        $guard->disarm();

        // With the deadline cancelled the loop has nothing pending, so run()
        // returns immediately and the callback must not have fired.
        $loop->run();

        $this->assertFalse($guard->fired());
        $this->assertFalse($ran, 'disarm() must cancel a still-pending deadline');
    }

    public function testDisarmIsIdempotentAfterFire(): void
    {
        $loop  = new StreamSelectLoop();
        $guard = TimeoutGuard::arm($loop, 0.01, static fn () => null);
        $loop->run();

        $this->assertTrue($guard->fired());
        // Disarming a spent one-shot (and doing it twice) must be a harmless
        // no-op — never a double-cancel error.
        $guard->disarm();
        $guard->disarm();
        $this->assertTrue($guard->fired());
    }
}
