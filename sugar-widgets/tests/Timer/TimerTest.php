<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Timer;

use SugarCraft\Widgets\Timer\TickMsg;
use SugarCraft\Widgets\Timer\TimeoutMsg;
use SugarCraft\Widgets\Timer\Timer;
use SugarCraft\Core\TickRequest;
use PHPUnit\Framework\TestCase;

final class TimerTest extends TestCase
{
    protected function tearDown(): void
    {
        $reflection = new \ReflectionClass(Timer::class);
        $prop = $reflection->getProperty('nextId');
        $prop->setAccessible(true);
        $prop->setValue(null, 0);
    }

    public function testInitialState(): void
    {
        $t = Timer::new(10.0);
        $this->assertSame(10.0, $t->remaining);
        $this->assertFalse($t->isRunning());
        $this->assertFalse($t->timedOut());
        // Fresh-and-stopped renders the visible paused tag (W-19) — the
        // RUNNING bytes are the zero-move contract, tested separately.
        $this->assertSame("0:10 \x1b[2mpaused\x1b[0m", $t->view());
    }

    public function testStartReturnsTickCmd(): void
    {
        $t = Timer::new(10.0);
        [$next, $cmd] = $t->start();
        $this->assertTrue($next->isRunning());
        $this->assertNotNull($cmd);
        $req = $cmd();
        $this->assertInstanceOf(TickRequest::class, $req);
        $this->assertEqualsWithDelta(1.0, $req->seconds, 1e-6);
    }

    public function testTickDecrements(): void
    {
        [$t, ] = Timer::new(3.0)->start();
        [$next, $cmd] = $t->update(new TickMsg($t->id, $t->epoch));
        $this->assertSame(2.0, $next->remaining);
        $this->assertTrue($next->isRunning());
        $this->assertNotNull($cmd);
    }

    public function testTimeoutEmitsAndStops(): void
    {
        [$t, ] = Timer::new(1.0, 1.0)->start();
        [$next, $cmd] = $t->update(new TickMsg($t->id, $t->epoch));
        $this->assertSame(0.0, $next->remaining);
        $this->assertFalse($next->isRunning());
        $this->assertTrue($next->timedOut());
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(TimeoutMsg::class, $cmd());
    }

    public function testStartIsIdempotentWhenAlreadyRunning(): void
    {
        [$a, $cmd1] = Timer::new(5.0)->start();
        $this->assertNotNull($cmd1);

        [$b, $cmd2] = $a->start();
        $this->assertSame($a, $b, 'second start() must be a no-op so duplicate tick chains do not run');
        $this->assertNull($cmd2);
    }

    public function testIgnoresTickForOtherTimer(): void
    {
        [$a, ] = Timer::new(5.0)->start();
        $b     = Timer::new(5.0);
        [$next, $cmd] = $a->update(new TickMsg($b->id));
        $this->assertSame($a, $next);
        $this->assertNull($cmd);
    }

    public function testStopHaltsTicking(): void
    {
        [$t, ] = Timer::new(5.0)->start();
        $t = $t->stop();
        $this->assertFalse($t->isRunning());
        [$next, $cmd] = $t->update(new TickMsg($t->id, $t->epoch));
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testResetRestoresDuration(): void
    {
        [$t, ] = Timer::new(5.0)->start();
        [$t, ] = $t->update(new TickMsg($t->id, $t->epoch));
        $reset = $t->reset(10.0);
        $this->assertSame(10.0, $reset->remaining);
        $this->assertFalse($reset->isRunning());
        $this->assertFalse($reset->timedOut());
    }

    public function testFormatHoursMinutesSeconds(): void
    {
        $this->assertSame('0:00',    Timer::format(0));
        $this->assertSame('0:05',    Timer::format(5));
        $this->assertSame('1:30',    Timer::format(90));
        $this->assertSame('1:00:00', Timer::format(3600));
        $this->assertSame('2:05:30', Timer::format(2 * 3600 + 5 * 60 + 30));
    }

    public function testNegativeDurationRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::new(-1.0);
    }

    public function testZeroIntervalRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::new(5.0, 0.0);
    }

    public function testIdAccessor(): void
    {
        $t = Timer::new(3.0);
        $this->assertSame($t->id, $t->id());
    }

    public function testIdsAreMonotonicAcrossInstances(): void
    {
        $t1 = Timer::new(3.0);
        $t2 = Timer::new(3.0);
        $t3 = Timer::new(3.0);
        $this->assertSame(1, $t1->id);
        $this->assertSame(2, $t2->id);
        $this->assertSame(3, $t3->id);
    }

    public function testToggleStartsWhenStopped(): void
    {
        $t = Timer::new(3.0);
        [$t, $cmd] = $t->toggle();
        $this->assertTrue($t->isRunning());
        $this->assertNotNull($cmd);
    }

    public function testToggleStopsWhenRunning(): void
    {
        $t = Timer::new(3.0);
        [$t, ] = $t->start();
        [$t, $cmd] = $t->toggle();
        $this->assertFalse($t->isRunning());
        $this->assertNull($cmd);
    }

    public function testIntervalAndTimeoutGetters(): void
    {
        $t = Timer::new(5.0, 0.5);
        $this->assertSame(0.5, $t->interval());
        $this->assertSame(5.0, $t->timeout());
    }

    public function testWithIntervalReplacesIntervalOnly(): void
    {
        $t = Timer::new(5.0, 1.0);
        $t2 = $t->withInterval(0.25);
        $this->assertSame(0.25, $t2->interval());
        $this->assertSame(5.0, $t2->timeout());
        $this->assertFalse($t2->isRunning());
        $this->assertSame($t->id(), $t2->id());
    }

    public function testWithIntervalRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::new(1.0)->withInterval(0.0);
    }

    public function testInitReturnsNull(): void
    {
        $t = Timer::new(5.0);
        $this->assertNull($t->init());
    }

    public function testSubscriptionsReturnsNull(): void
    {
        $t = Timer::new(5.0);
        $this->assertNull($t->subscriptions());
    }

    public function testViewRendersRemainingTime(): void
    {
        $t = Timer::new(5.0);
        $this->assertSame("0:05 \x1b[2mpaused\x1b[0m", $t->view());
    }

    // ─── finite guards + reset validation + negative format (008/063) ──

    public function testNonFiniteDurationThrowsAtNew(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::new(NAN);
    }

    public function testInfiniteDurationThrowsAtNew(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::new(INF);
    }

    public function testNonFiniteIntervalThrowsAtNew(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::new(5.0, NAN);
    }

    public function testNonFiniteIntervalThrowsAtWithInterval(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Timer::new(5.0)->withInterval(INF);
    }

    public function testResetValidatesTheSameDoorAsTheFactory(): void
    {
        $t = Timer::new(5.0);
        $this->expectException(\InvalidArgumentException::class);
        $t->reset(NAN);
    }

    public function testResetRejectsNegativeDuration(): void
    {
        $t = Timer::new(5.0);
        $this->expectException(\InvalidArgumentException::class);
        $t->reset(-1.0);
    }

    public function testFormatRendersSaneZeroForNegativeAndNonFinite(): void
    {
        $this->assertSame('0:00', Timer::format(-5.0));
        $this->assertSame('0:00', Timer::format(-0.4));
        $this->assertSame('0:00', Timer::format(NAN));
        $this->assertSame('0:00', Timer::format(INF));
        $this->assertSame('0:05', Timer::format(5.0), 'positive rendering unchanged');
        $this->assertSame('1:02:03', Timer::format(3723.0));
    }

    public function testStateTagsArePairwiseVisibleAcrossTheMatrix(): void
    {
        $fresh = Timer::new(3.0, 1.0);
        [$running, ] = $fresh->start();
        $t = $running;
        for ($i = 0; $i < 10 && !$t->timedOut(); $i++) {
            [$t, ] = $t->update(new TickMsg($t->id, $t->epoch));
        }
        $this->assertTrue($t->timedOut(), 'ticks drove the timer to timeout');
        // Running bytes are the zero-move contract: no tag, no dim SGR.
        $this->assertSame('', $running->stateLabel());
        $this->assertStringNotContainsString("\x1b[2m", $running->view());
        $this->assertSame('paused', $fresh->stateLabel());
        $this->assertSame('timeout', $t->stateLabel());
        $paused  = $fresh->view();
        $timeout = $t->view();
        $this->assertNotSame($paused, $timeout, 'paused vs timeout visible in the frame');
        $this->assertStringContainsString(" \x1b[2mpaused\x1b[0m", $paused);
        $this->assertStringContainsString(" \x1b[2mtimeout\x1b[0m", $timeout);
        $this->assertStringEndsWith("timeout\x1b[0m", $timeout);
    }

    public function testTickRenamedClassResolvesThroughTheDeprecatedAlias(): void
    {
        $legacy = new TickMsg(3, 1);
        $this->assertInstanceOf(\SugarCraft\Widgets\Timer\TimerTick::class, $legacy);
        $this->assertSame(3, $legacy->id);
        $this->assertSame(1, $legacy->epoch);
        // A bare TimerTick passes the update() gate the same way (whole
        // suite still drives the alias import — BC coverage by volume).
        [$next, $cmd] = Timer::new(5.0)->update(new \SugarCraft\Widgets\Timer\TimerTick(0));
        $this->assertInstanceOf(Timer::class, $next);
    }

    public function testCrossWidgetTickIsFatalNotSilent(): void
    {
        $this->expectException(\TypeError::class);
        Timer::new(5.0)->update(new \SugarCraft\Widgets\Stopwatch\StopwatchTick(0));
    }
}
