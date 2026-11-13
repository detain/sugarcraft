<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Stopwatch;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\TickRequest;
use SugarCraft\Widgets\Stopwatch\Stopwatch;
use SugarCraft\Widgets\Stopwatch\TickMsg;

/**
 * Pins for the 2026-11 review findings on Stopwatch: M12 (restart forked a
 * second counting chain via an in-flight tick), M13 (public
 * resetIdCounter() removed) and M14 (elapsed() is a tick accumulator, not
 * wall-clock — docblock truth pinned).
 */
final class StopwatchReviewPinsTest extends TestCase
{
    protected function setUp(): void
    {
        $r = new \ReflectionClass(Stopwatch::class);
        $p = $r->getProperty('nextId');
        $p->setAccessible(true);
        $p->setValue(null, 0);
        Stopwatch::setClock(null);
    }

    public function testTickFromStoppedGenerationIsRefused(): void
    {
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        [$running, $cmd] = Stopwatch::new(1.0)->start();
        self::assertNotNull($cmd);

        // In-flight tick captured before a stop/start flip (same id, older
        // epoch) must not advance the restarted chain — and the settled
        // reading is now DERIVED wall time (W-37), scripted here at the
        // start instant => 0.0.
        $stale = self::unwrap($cmd);
        [$afterFlip, $again] = $running->stop()->start();
        [$settled] = $afterFlip->update($stale);
        self::assertSame(0.0, $settled->elapsed());

        $fresh = self::unwrap($again);
        $now += 1.0;
        [$counted] = $afterFlip->update($fresh);
        self::assertSame(1.0, $counted->elapsed());
    }

    public function testEpochAdvancesOncePerFlipIntoRunning(): void
    {
        $sw = Stopwatch::new();
        self::assertSame(0, $sw->epoch);
        [$started] = $sw->start();
        self::assertSame(1, $started->epoch);
        self::assertSame(1, $started->stop()->epoch);
        [$restarted] = $started->stop()->start();
        self::assertSame(2, $restarted->epoch);
    }

    public function testResetIdCounterIsGone(): void
    {
        self::assertFalse(
            method_exists(Stopwatch::class, 'resetIdCounter'),
            'M13: the public resetIdCounter() escape hatch must not come back',
        );
    }

    public function testElapsedDocblockTellsTheTickAccumulatorTruth(): void
    {
        // M14: the old docblock promised "wall-clock seconds" while the
        // value only advances when update() consumes ticks.
        $doc = (new \ReflectionMethod(Stopwatch::class, 'elapsed'))->getDocComment();
        self::assertIsString($doc);
        self::assertStringContainsString('tick-count accumulator', $doc);
        self::assertStringNotContainsString('wall-clock seconds since', $doc);
    }

    private static function unwrap(\Closure $cmd): TickMsg
    {
        $req = $cmd();
        self::assertInstanceOf(TickRequest::class, $req);
        $msg = ($req->produce)();
        self::assertInstanceOf(TickMsg::class, $msg);
        return $msg;
    }
}
