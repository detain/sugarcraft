<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Stopwatch;

use CandyCore\Bits\Stopwatch\Stopwatch;
use CandyCore\Bits\Stopwatch\TickMsg;
use CandyCore\Core\TickRequest;
use PHPUnit\Framework\TestCase;

final class StopwatchTest extends TestCase
{
    public function testInitialState(): void
    {
        $s = Stopwatch::new();
        $this->assertSame(0.0, $s->elapsed);
        $this->assertFalse($s->isRunning());
        $this->assertSame('0:00', $s->view());
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
        [$next, $cmd] = $s->update(new TickMsg($s->id));
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
        [$next, $cmd] = $s->update(new TickMsg($s->id));
        $this->assertSame($s, $next);
        $this->assertNull($cmd);
    }

    public function testResetZeros(): void
    {
        [$s, ] = Stopwatch::new()->start();
        [$s, ] = $s->update(new TickMsg($s->id));
        [$s, ] = $s->update(new TickMsg($s->id));
        $reset = $s->reset();
        $this->assertSame(0.0, $reset->elapsed);
        $this->assertFalse($reset->isRunning());
    }

    public function testCustomInterval(): void
    {
        [$s, ] = Stopwatch::new(0.5)->start();
        [$next, ] = $s->update(new TickMsg($s->id));
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

    public function testElapsedAccessor(): void
    {
        $s = Stopwatch::new(0.5);
        [$s, ] = $s->start();
        [$s, ] = $s->update(new TickMsg($s->id));
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
}
