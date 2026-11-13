<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Stopwatch;

use SugarCraft\Widgets\Stopwatch\Stopwatch;
use SugarCraft\Widgets\Stopwatch\TickMsg;
use SugarCraft\Core\TickRequest;
use PHPUnit\Framework\TestCase;

final class StopwatchTest extends TestCase
{
    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(Stopwatch::class);
        $prop = $reflection->getProperty('nextId');
        $prop->setAccessible(true);
        $prop->setValue(null, 0);
        Stopwatch::setClock(null);
    }

    public function testInitialState(): void
    {
        $s = Stopwatch::new();
        $this->assertSame(0.0, $s->elapsed());
        $this->assertFalse($s->isRunning());
        $this->assertSame("0:00 \x1b[2mnot started\x1b[0m", $s->view());
    }

    public function testStartTicks(): void
    {
        [$s, $cmd] = Stopwatch::new()->start();
        $this->assertTrue($s->isRunning());
        $this->assertInstanceOf(TickRequest::class, $cmd());
    }

    public function testTickIncrements(): void
    {
        // W-37 semantics: a tick folds in REAL scripted seconds (clock
        // seam), not one interval per tick. At scripted 1.0 s/tick the
        // numbers coincide with the old tick-count world exactly.
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        [$s, ] = Stopwatch::new()->start();
        $now = 1001.0;
        [$next, $cmd] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(1.0, $next->elapsed());
        $this->assertNotNull($cmd);
    }

    public function testStartIsIdempotentWhenAlreadyRunning(): void
    {
        [$s, $cmd1] = Stopwatch::new()->start();
        $this->assertNotNull($cmd1);
        [$s2, $cmd2] = $s->start();
        $this->assertSame($s, $s2);
        $this->assertNull($cmd2);
    }

    public function testStopHalts(): void
    {
        [$s, ] = Stopwatch::new()->start();
        $s = $s->stop();
        [$next, $cmd] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame($s, $next);
        $this->assertNull($cmd);
    }

    public function testResetZeros(): void
    {
        [$s, ] = Stopwatch::new()->start();
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $reset = $s->reset();
        $this->assertSame(0.0, $reset->elapsed());
        $this->assertFalse($reset->isRunning());
    }

    public function testCustomInterval(): void
    {
        // interval is the REPAINT cadence; elapsed is real time (W-37) —
        // scripted 0.5 s between start and the first tick.
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        [$s, ] = Stopwatch::new(0.5)->start();
        $now += 0.5;
        [$next, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(0.5, $next->elapsed());
    }

    public function testZeroIntervalRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Stopwatch::new(0.0);
    }

    public function testIdAccessor(): void
    {
        $s = Stopwatch::new();
        $this->assertSame($s->id, $s->id());
    }

    public function testIdsAreMonotonicAcrossInstances(): void
    {
        $s1 = Stopwatch::new();
        $s2 = Stopwatch::new();
        $s3 = Stopwatch::new();
        $this->assertSame(1, $s1->id);
        $this->assertSame(2, $s2->id);
        $this->assertSame(3, $s3->id);
    }

    public function testElapsedAccessor(): void
    {
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        $s = Stopwatch::new(0.5);
        [$s, ] = $s->start();
        $now += 0.5;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(0.5, $s->elapsed());
    }

    public function testToggleStartsWhenStopped(): void
    {
        $s = Stopwatch::new();
        [$s2, $cmd] = $s->toggle();
        $this->assertTrue($s2->isRunning());
        $this->assertNotNull($cmd);
    }

    public function testToggleStopsWhenRunning(): void
    {
        $s = Stopwatch::new();
        [$s, ] = $s->start();
        [$s2, $cmd] = $s->toggle();
        $this->assertFalse($s2->isRunning());
        $this->assertNull($cmd);
    }

    public function testIntervalGetter(): void
    {
        $s = Stopwatch::new(0.5);
        $this->assertSame(0.5, $s->interval());
    }

    public function testWithIntervalPreservesElapsedAndId(): void
    {
        $s = Stopwatch::new(1.0);
        [$s, ] = $s->start();
        $s2 = $s->withInterval(0.25);
        $this->assertSame(0.25, $s2->interval());
        $this->assertTrue($s2->isRunning());
        $this->assertSame($s->id(), $s2->id());
    }

    public function testInitReturnsNull(): void
    {
        $s = Stopwatch::new();
        $this->assertNull($s->init());
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $s = Stopwatch::new();
        $this->assertNull($s->subscriptions());
    }

    public function testViewFormatsElapsedTime(): void
    {
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        $s = Stopwatch::new(1.0);
        [$s, ] = $s->start();
        $now += 1.0;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $now += 1.0;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $view = $s->view();
        // Scripted 2.0 true seconds elapsed; running carries no state tag
        // (W-19 zero-move contract) — the exact bytes pin.
        $this->assertSame('0:02', $view);
    }

    public function testNegativeIntervalRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Stopwatch::new(-1.0);
    }

    public function testNonFiniteIntervalThrowsAtBothDoors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Stopwatch::new(INF);
    }

    public function testNonFiniteIntervalThrowsAtWithInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Stopwatch::new(1.0)->withInterval(NAN);
    }

    public function testStateTagsArePairwiseVisibleAcrossTheMatrix(): void
    {
        $fresh = Stopwatch::new(1.0);
        [$running, ] = $fresh->start();
        [$moved, ] = $running->update(new TickMsg($running->id, $running->epoch));
        $stopped = $moved->stop();
        $this->assertSame('', $running->stateLabel());
        $this->assertStringNotContainsString("\x1b[2m", $running->view(), 'running bytes are a zero-move');
        $this->assertSame('not started', $fresh->stateLabel());
        $this->assertSame('paused', $stopped->stateLabel());
        $this->assertNotSame($fresh->view(), $stopped->view(), 'never-started vs paused are distinct');
        $this->assertStringContainsString(" \x1b[2mnot started\x1b[0m", $fresh->view());
        $this->assertStringContainsString(" \x1b[2mpaused\x1b[0m", $stopped->view());
    }

    public function testTickRenamedClassResolvesThroughTheDeprecatedAlias(): void
    {
        $legacy = new TickMsg(3, 1);
        $this->assertInstanceOf(\SugarCraft\Widgets\Stopwatch\StopwatchTick::class, $legacy);
        $this->assertSame(3, $legacy->id);
    }

    // ─── W-37 wall-clock truth ──────────────────────────────────────

    public function testIrregularCadenceMeasuresTrueTimeNotTickCount(): void
    {
        // Binary-exact scripted advances (0.5+2.25+0.125+1.875+7.25 = 12.0);
        // the old tick-count accumulator would have reported 5 × interval
        // = 5.0 for the SAME five ticks.
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        [$s, ] = Stopwatch::new(1.0)->start();
        foreach ([0.5, 2.25, 0.125, 1.875, 7.25] as $step) {
            $now += $step;
            [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        }
        $this->assertSame(12.0, $s->elapsed());
    }

    public function testStalledLoopGapCountsFully(): void
    {
        // Five seconds of real time pass between two ticks — the whole
        // gap counts (a tick-count world charged exactly one interval).
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        [$s, ] = Stopwatch::new(1.0)->start();
        $now += 5.0;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(5.0, $s->elapsed());
    }

    public function testStopFreezesTheReadingAndHidesTheGap(): void
    {
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        [$s, ] = Stopwatch::new(1.0)->start();
        $now += 2.0;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $stopped = $s->stop();
        $now += 100.0; // a long idle stretch while stopped
        $this->assertSame(2.0, $stopped->elapsed());
        $this->assertSame("0:02 \x1b[2mpaused\x1b[0m", $stopped->view());
        [$same, $cmd] = $stopped->update(new TickMsg($stopped->id, $stopped->epoch));
        $this->assertSame($stopped, $same);
        $this->assertNull($cmd);
    }

    public function testResumeExcludesTheStoppedGap(): void
    {
        // start@1000, one tick at 1001 (1.0), stop freezes the base; the
        // resume happens at 1101 — the 100s gap must NOT count; one tick
        // one second after resume reads 2.0.
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        [$s, ] = Stopwatch::new(1.0)->start();
        $now = 1001.0;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $s = $s->stop();
        $now = 1101.0;                        // 100s spent while stopped
        [$s, ] = $s->start();                 // resume re-baselines at 1101
        $now = 1102.0;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(2.0, $s->elapsed(), 'the stopped gap is excluded; live time after resume counts');
        $frozen = $s->stop();
        $now += 50.0;
        $this->assertSame(2.0, $frozen->elapsed());
    }

    public function testCrossWidgetTickIsFatalNotSilent(): void
    {
        $this->expectException(\TypeError::class);
        Stopwatch::new()->update(new \SugarCraft\Widgets\Timer\TimerTick(0));
    }

    public function testLateTicksNeverCompoundIntoExtraSeconds(): void
    {
        // The old drift class: re-arming after consumption made every
        // late tick silently worth MORE than it measured. Under the
        // wall-clock law two scripted seconds charged is two seconds,
        // no matter how many extra repaints happen in between.
        $now = 1000.0;
        Stopwatch::setClock(static function () use (&$now): float { return $now; });
        [$s, ] = Stopwatch::new(1.0)->start();
        $now += 1.0;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $now += 0.0;                          // immediate repaint, +0 real time
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(1.0, $s->elapsed(), 'a zero-time repaint adds zero seconds');
        $now += 1.0;
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(2.0, $s->elapsed());
    }
}
