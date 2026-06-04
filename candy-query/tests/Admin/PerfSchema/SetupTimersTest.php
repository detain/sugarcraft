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
        );

        $this->assertSame('NANOSECOND', $timer->name);
        $this->assertSame('sql_timer_nanosecond', $timer->timerName);
        $this->assertFalse($timer->isDirty());
        $this->assertSame(SetupTimers::CHANGE_NONE, $timer->getChangeType());
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

    public function testWithTimerNameReturnsNewInstance(): void
    {
        $original = SetupTimers::new(name: 'CYCLE', timerName: 'cycle');
        $modified = $original->withTimerName('nanosecond');

        $this->assertSame('cycle', $original->timerName);
        $this->assertSame('nanosecond', $modified->timerName);
        $this->assertFalse($original->isDirty());
        $this->assertTrue($modified->isDirty());
        $this->assertSame(SetupTimers::CHANGE_UPDATE, $modified->getChangeType());
    }

    public function testWithTimerNameNoOpWhenSame(): void
    {
        $original = SetupTimers::new(name: 'CYCLE', timerName: 'cycle');
        $modified = $original->withTimerName('cycle');

        $this->assertSame($original, $modified);
        $this->assertFalse($modified->isDirty());
    }

    public function testCommitStatementsReturnsUpdateWhenDirty(): void
    {
        $timer = SetupTimers::new(name: 'CYCLE', timerName: 'cycle');
        $modified = $timer->withTimerName('nanosecond');

        $statements = $modified->commitStatements();

        $this->assertCount(1, $statements);
        $this->assertSame(
            "UPDATE `performance_schema`.`setup_timers` SET `TIMER_NAME` = 'nanosecond' WHERE `NAME` = 'CYCLE'",
            $statements[0]
        );
    }

    public function testCommitStatementsReturnsEmptyWhenClean(): void
    {
        $timer = SetupTimers::new(name: 'CYCLE', timerName: 'cycle');

        $this->assertSame([], $timer->commitStatements());
    }
}
