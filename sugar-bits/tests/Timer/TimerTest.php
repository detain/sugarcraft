<?php

declare(strict_types=1);

namespace CandyCore\Bits\Tests\Timer;

use CandyCore\Bits\Timer\TickMsg;
use CandyCore\Bits\Timer\TimeoutMsg;
use CandyCore\Bits\Timer\Timer;
use CandyCore\Core\TickRequest;
use PHPUnit\Framework\TestCase;

final class TimerTest extends TestCase
{
    public function testInitialState(): void
    {
        $t = Timer::new(10.0);
        $this->assertSame(10.0, $t->remaining);
        $this->assertFalse($t->isRunning());
        $this->assertFalse($t->timedOut());
        $this->assertSame('0:10', $t->view());
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
        [$next, $cmd] = $t->update(new TickMsg($t->id));
        $this->assertSame(2.0, $next->remaining);
        $this->assertTrue($next->isRunning());
        $this->assertNotNull($cmd);
    }

    public function testTimeoutEmitsAndStops(): void
    {
        [$t, ] = Timer::new(1.0, 1.0)->start();
        [$next, $cmd] = $t->update(new TickMsg($t->id));
        $this->assertSame(0.0, $next->remaining);
        $this->assertFalse($next->isRunning());
        $this->assertTrue($next->timedOut());
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(TimeoutMsg::class, $cmd());
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
        [$next, $cmd] = $t->update(new TickMsg($t->id));
        $this->assertSame($t, $next);
        $this->assertNull($cmd);
    }

    public function testResetRestoresDuration(): void
    {
        [$t, ] = Timer::new(5.0)->start();
        [$t, ] = $t->update(new TickMsg($t->id));
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
}
