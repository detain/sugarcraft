<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tui;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tui\AgentSnapshot;

final class AgentSnapshotTest extends TestCase
{
    public function testPreviousFieldsDefaultToZero(): void
    {
        $s = new AgentSnapshot(100, 12.5, 3.0);

        self::assertSame(100, $s->lastTokenCount);
        self::assertSame(12.5, $s->lastTimestamp);
        self::assertSame(3.0, $s->slowPeriodSeconds);
        self::assertSame(0, $s->previousTokenCount);
        self::assertSame(0.0, $s->previousTimestamp);
    }

    public function testWithPreviousSnapshotRollsTheCurrentIntoPrevious(): void
    {
        $s = new AgentSnapshot(100, 12.5, 3.0, 40, 5.0);

        $next = $s->withPreviousSnapshot(250, 20.0, 4.0);

        self::assertSame(250, $next->lastTokenCount);
        self::assertSame(20.0, $next->lastTimestamp);
        self::assertSame(4.0, $next->slowPeriodSeconds);
        self::assertSame(100, $next->previousTokenCount);
        self::assertSame(12.5, $next->previousTimestamp);
    }

    public function testWithPreviousSnapshotLeavesTheOriginalUntouched(): void
    {
        $s = new AgentSnapshot(100, 12.5, 3.0, 40, 5.0);
        $s->withPreviousSnapshot(1, 1.0, 1.0);

        self::assertSame(100, $s->lastTokenCount);
        self::assertSame(40, $s->previousTokenCount);
    }
}
