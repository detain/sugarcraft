<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Runtime;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\ScriptUi\Runtime\TimeoutGuard;

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

    public function testTryParseSecondsAcceptsValidValues(): void
    {
        foreach ([null, 0, 5, 12.5, '0', '5', '12.5', 1e3] as $raw) {
            $seconds = -1.0;
            $this->assertTrue(TimeoutGuard::tryParseSeconds($raw, $seconds), 'must accept ' . var_export($raw, true));
            $this->assertGreaterThanOrEqual(0.0, $seconds);
        }

        $seconds = -1.0;
        $this->assertTrue(TimeoutGuard::tryParseSeconds(null, $seconds));
        $this->assertSame(0.0, $seconds, 'an absent flag means no deadline');

        $seconds = -1.0;
        $this->assertTrue(TimeoutGuard::tryParseSeconds('12.5', $seconds));
        $this->assertSame(12.5, $seconds);
    }

    public function testTryParseSecondsRefusesGarbage(): void
    {
        // m-a (2026-11-03): the old `(float)` cast turned 'abc' into 0.0,
        // silently DISABLING the requested deadline. Fail-open numerics are
        // exactly what the strict parser exists to refuse.
        $garbage = ['abc', '', '1e999', -1, '-5.5', true, false, NAN, INF, [1], 'abc5'];
        foreach ($garbage as $raw) {
            $seconds = -1.0;
            $this->assertFalse(
                TimeoutGuard::tryParseSeconds($raw, $seconds),
                'must refuse ' . (is_scalar($raw) ? var_export($raw, true) : get_debug_type($raw)),
            );
        }
    }
}
