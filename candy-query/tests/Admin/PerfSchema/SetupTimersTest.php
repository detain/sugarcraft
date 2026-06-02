<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\SetupTimers;

final class SetupTimersTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $timer = SetupTimers::new(
            name: 'NANOSECOND',
            timerName: 'sql_timer_nanosecond',
            scaleFactor: 1000.0,
        );

        $this->assertSame('NANOSECOND', $timer->name);
        $this->assertSame('sql_timer_nanosecond', $timer->timerName);
        $this->assertSame(1000.0, $timer->scaleFactor);
    }

    public function testNameUpper(): void
    {
        $lower = SetupTimers::new(name: 'nanosecond');
        $upper = SetupTimers::new(name: 'NANOSECOND');

        $this->assertSame('NANOSECOND', $lower->nameUpper());
        $this->assertSame('NANOSECOND', $upper->nameUpper());
    }

    public function testIsCycle(): void
    {
        $cycle = SetupTimers::new(name: 'CYCLE');
        $nonCycle = SetupTimers::new(name: 'NANOSECOND');

        $this->assertTrue($cycle->isCycle());
        $this->assertFalse($nonCycle->isCycle());
    }

    public function testIsCycleCaseInsensitive(): void
    {
        $cycle = SetupTimers::new(name: 'cycle');

        $this->assertTrue($cycle->isCycle());
    }
}
