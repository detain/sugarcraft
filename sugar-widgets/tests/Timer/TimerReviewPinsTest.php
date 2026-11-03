<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Timer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Timer\TickMsg;
use SugarCraft\Widgets\Timer\Timer;
use SugarCraft\Core\TickRequest;

/**
 * Pins for the 2026-11 review findings on Timer:
 * M12 (stop→start within one tick forked two counting chains),
 * M13 (public resetIdCounter() was a cross-test ID-routing hazard with
 * zero callers), and the epoch field that carries M12's cure.
 */
final class TimerReviewPinsTest extends TestCase
{
    public function testTickFromStoppedGenerationIsRefused(): void
    {
        $timer = Timer::new(10.0, 1.0);
        [$running, $cmd] = $timer->start();
        self::assertNotNull($cmd);

        // Capture the produced TickMsg BEFORE the stop/start flip: this is
        // the in-flight tick a real event loop may deliver after a restart.
        $stale = self::closureOf($cmd);

        [$afterFlip, $again] = $running->stop()->start();
        self::assertNotNull($again);

        // The stale tick must not decrement the restarted timer's remaining.
        [$settled] = $afterFlip->update($stale);
        self::assertSame(10.0, $settled->remaining);

        // The current generation's tick does count.
        $fresh = self::closureOf($again);
        [$counted] = $afterFlip->update($fresh);
        self::assertSame(9.0, $counted->remaining);
    }

    public function testEpochAdvancesOncePerFlipIntoRunning(): void
    {
        $timer = Timer::new(5.0, 1.0);
        self::assertSame(0, $timer->epoch);

        [$started] = $timer->start();
        self::assertSame(1, $started->epoch);

        $stopped = $started->stop();
        self::assertSame(1, $stopped->epoch, 'stop carries the epoch unchanged');

        [$restarted] = $stopped->start();
        self::assertSame(2, $restarted->epoch);
    }

    public function testLegacyZeroEpochTickStillCountsForZeroEpochChain(): void
    {
        // A host that constructed TickMsg by hand (epoch default 0) against a
        // never-started-but-running=false timer sees no change; against a
        // generation-0 running state it must count (BC with pre-fix callers).
        $tick = new TickMsg(7);
        self::assertSame(0, $tick->epoch);
    }

    public function testResetIdCounterIsGone(): void
    {
        self::assertFalse(
            method_exists(Timer::class, 'resetIdCounter'),
            'M13: the public resetIdCounter() escape hatch must not come back',
        );
    }

    /** Fire a Cmd::tick closure and unwrap the TickRequest into its Msg. */
    private static function closureOf(\Closure $cmd): TickMsg
    {
        $req = $cmd();
        self::assertInstanceOf(TickRequest::class, $req);
        $msg = ($req->produce)();
        self::assertInstanceOf(TickMsg::class, $msg);
        return $msg;
    }
}
