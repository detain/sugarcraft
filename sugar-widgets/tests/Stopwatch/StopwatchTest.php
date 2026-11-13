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
    }

    public function testInitialState(): void
    {
        $s = Stopwatch::new();
        $this->assertSame(0.0, $s->elapsed);
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
        [$s, ] = Stopwatch::new()->start();
        [$next, $cmd] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(1.0, $next->elapsed);
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
        $this->assertSame(0.0, $reset->elapsed);
        $this->assertFalse($reset->isRunning());
    }

    public function testCustomInterval(): void
    {
        [$s, ] = Stopwatch::new(0.5)->start();
        [$next, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $this->assertSame(0.5, $next->elapsed);
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
        $s = Stopwatch::new(0.5);
        [$s, ] = $s->start();
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
        $s = Stopwatch::new(1.0);
        [$s, ] = $s->start();
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        [$s, ] = $s->update(new TickMsg($s->id, $s->epoch));
        $view = $s->view();
        // After 2 ticks at 1.0 interval = 2.0 seconds elapsed
        $this->assertNotSame('0:00', $view);
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

    public function testCrossWidgetTickIsFatalNotSilent(): void
    {
        $this->expectException(\TypeError::class);
        Stopwatch::new()->update(new \SugarCraft\Widgets\Timer\TimerTick(0));
    }
}
