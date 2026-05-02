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
}
