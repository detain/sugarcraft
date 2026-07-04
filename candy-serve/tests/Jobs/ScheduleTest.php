<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Jobs;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Jobs\Schedule;

/**
 * Schedule parsing for background jobs — plan item 7.5.
 *
 * @covers \SugarCraft\Serve\Jobs\Schedule
 */
final class ScheduleTest extends TestCase
{
    public function testParseEveryMinutes(): void
    {
        $s = Schedule::parse('@every 10m');

        $this->assertSame(600, $s->intervalSeconds);
        $this->assertSame('@every 10m', $s->expression);
    }

    public function testParseEveryHours(): void
    {
        $this->assertSame(8 * 3600, Schedule::parse('@every 8h')->intervalSeconds);
    }

    public function testParseEverySeconds(): void
    {
        $this->assertSame(90, Schedule::parse('@every 90s')->intervalSeconds);
    }

    public function testParseEveryCompoundDuration(): void
    {
        $this->assertSame(5400, Schedule::parse('@every 1h30m')->intervalSeconds);
        $this->assertSame(3661, Schedule::parse('@every 1h1m1s')->intervalSeconds);
    }

    public function testParseTrimsWhitespace(): void
    {
        $this->assertSame(600, Schedule::parse("  @every 10m \n")->intervalSeconds);
    }

    public function testParseAliases(): void
    {
        $this->assertSame(3600, Schedule::parse('@hourly')->intervalSeconds);
        $this->assertSame(86400, Schedule::parse('@daily')->intervalSeconds);
        $this->assertSame(86400, Schedule::parse('@midnight')->intervalSeconds);
        $this->assertSame(604800, Schedule::parse('@weekly')->intervalSeconds);
        $this->assertSame(2592000, Schedule::parse('@monthly')->intervalSeconds);
        $this->assertSame(31536000, Schedule::parse('@yearly')->intervalSeconds);
        $this->assertSame(31536000, Schedule::parse('@annually')->intervalSeconds);
    }

    public function testParseRejectsCronExpressions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cron expressions are not supported');

        Schedule::parse('*/10 * * * *');
    }

    public function testParseRejectsGarbageDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Schedule::parse('@every soon');
    }

    public function testParseRejectsZeroDuration(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Schedule::parse('@every 0s');
    }

    public function testParseRejectsEmptyString(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Schedule::parse('');
    }

    public function testIsDue(): void
    {
        $s = Schedule::parse('@every 10m');

        $this->assertTrue($s->isDue(null, 1000), 'never-run jobs are always due');
        $this->assertFalse($s->isDue(1000, 1599));
        $this->assertTrue($s->isDue(1000, 1600));
        $this->assertTrue($s->isDue(1000, 5000));
    }
}
