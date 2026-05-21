<?php

declare(strict_types=1);

namespace SugarCraft\Calendar\Tests;

use SugarCraft\Calendar\DateRange;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    public function testWithStart(): void
    {
        $range = new DateRange();
        $range2 = $range->withStart(new \DateTimeImmutable('2026-05-01'));

        $this->assertNull($range->start);
        $this->assertSame('2026-05-01', $range2->start->format('Y-m-d'));
        $this->assertNull($range2->end);
    }

    public function testWithEnd(): void
    {
        $range = new DateRange();
        $range2 = $range->withEnd(new \DateTimeImmutable('2026-05-15'));

        $this->assertNull($range2->start);
        $this->assertSame('2026-05-15', $range2->end->format('Y-m-d'));
    }

    public function testContainsWithNoStart(): void
    {
        $range = new DateRange();
        $this->assertFalse($range->contains(new \DateTimeImmutable('2026-05-01')));
    }

    public function testContainsBeforeStart(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-05-10'),
            new \DateTimeImmutable('2026-05-20')
        );

        $this->assertFalse($range->contains(new \DateTimeImmutable('2026-05-01')));
        $this->assertFalse($range->contains(new \DateTimeImmutable('2026-05-09')));
    }

    public function testContainsAfterEnd(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-05-10'),
            new \DateTimeImmutable('2026-05-20')
        );

        $this->assertFalse($range->contains(new \DateTimeImmutable('2026-05-21')));
        $this->assertFalse($range->contains(new \DateTimeImmutable('2026-06-01')));
    }

    public function testContainsWithinRange(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-05-10'),
            new \DateTimeImmutable('2026-05-20')
        );

        $this->assertTrue($range->contains(new \DateTimeImmutable('2026-05-10')));
        $this->assertTrue($range->contains(new \DateTimeImmutable('2026-05-15')));
        $this->assertTrue($range->contains(new \DateTimeImmutable('2026-05-20')));
    }

    public function testContainsWithNoEnd(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-05-10')
        );

        $this->assertTrue($range->contains(new \DateTimeImmutable('2026-05-15')));
        $this->assertTrue($range->contains(new \DateTimeImmutable('2026-06-01')));
        $this->assertFalse($range->contains(new \DateTimeImmutable('2026-05-09')));
    }

    public function testDurationInDaysWithNoStart(): void
    {
        $range = new DateRange();
        $this->assertNull($range->durationInDays());
    }

    public function testDurationInDaysWithEnd(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-10')
        );

        $this->assertSame(9, $range->durationInDays());
    }

    public function testDurationInDaysWithNoEnd(): void
    {
        $range = new DateRange(
            new \DateTimeImmutable('2026-05-01')
        );

        // Duration should be calculated from start to today
        $this->assertNotNull($range->durationInDays());
        $this->assertGreaterThanOrEqual(0, $range->durationInDays());
    }

    public function testIsComplete(): void
    {
        $empty = new DateRange();
        $this->assertFalse($empty->isComplete());

        $onlyStart = new DateRange(new \DateTimeImmutable('2026-05-01'));
        $this->assertFalse($onlyStart->isComplete());

        $complete = new DateRange(
            new \DateTimeImmutable('2026-05-01'),
            new \DateTimeImmutable('2026-05-10')
        );
        $this->assertTrue($complete->isComplete());
    }
}
