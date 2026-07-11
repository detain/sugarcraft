<?php

declare(strict_types=1);

namespace SugarCraft\Calendar\Tests;

use SugarCraft\Calendar\Navigation;
use PHPUnit\Framework\TestCase;

final class NavigationTest extends TestCase
{
    public function testMoveLeft(): void
    {
        $this->assertSame(5, Navigation::move(6, 'left'));
    }

    public function testMoveLeftAtBoundary(): void
    {
        $this->assertSame(0, Navigation::move(0, 'left'));
    }

    public function testMoveRight(): void
    {
        $this->assertSame(10, Navigation::move(9, 'right'));
    }

    public function testMoveRightAtBoundary(): void
    {
        $this->assertSame(41, Navigation::move(41, 'right'));
    }

    public function testMoveUp(): void
    {
        $this->assertSame(7, Navigation::move(14, 'up'));
    }

    public function testMoveUpAtBoundary(): void
    {
        $this->assertSame(0, Navigation::move(3, 'up'));
    }

    public function testMoveDown(): void
    {
        $this->assertSame(21, Navigation::move(14, 'down'));
    }

    public function testMoveDownAtBoundary(): void
    {
        $this->assertSame(41, Navigation::move(38, 'down'));
    }

    public function testMoveHome(): void
    {
        $this->assertSame(0, Navigation::move(25, 'home'));
    }

    public function testMoveEnd(): void
    {
        $this->assertSame(41, Navigation::move(10, 'end'));
    }

    public function testMoveUnknownKeyReturnsUnchanged(): void
    {
        $this->assertSame(15, Navigation::move(15, 'unknown'));
    }

    public function testGridIndexToDate(): void
    {
        // May 2026: first day is a Friday (index 5)
        $date = Navigation::gridIndexToDate(5, 5, 2026);
        $this->assertSame('2026-05-01', $date->format('Y-m-d'));
    }

    public function testGridIndexToDateMidMonth(): void
    {
        // May 2026: first day is index 5, so index 12 = May 8
        $date = Navigation::gridIndexToDate(12, 5, 2026);
        $this->assertSame('2026-05-08', $date->format('Y-m-d'));
    }

    public function testGridIndexToDateReturnsNullForOutOfMonthIndex(): void
    {
        // January 2026: firstDow=4 (Thu), index 0 maps to dayNum=-3 (Dec 28 prev month)
        // which is outside the valid range 1-31 → return null.
        $this->assertNull(Navigation::gridIndexToDate(0, 1, 2026),
            'gridIndexToDate(0) must return null for Jan 2026 (out-of-month index)');
    }

    public function testGridIndexToDateReturnsValidDateForInMonthIndex(): void
    {
        // Index 4 maps to dayNum=1 (January 1, 2026) — in range.
        $date = Navigation::gridIndexToDate(4, 1, 2026);
        $this->assertSame('2026-01-01', $date?->format('Y-m-d'));
    }

    // -------------------------------------------------------------------------
    // [SEC] Month/year validation — an out-of-range month must be REJECTED,
    // never silently rolled into an adjacent period.
    // -------------------------------------------------------------------------

    public function testGridIndexToDateRejectsMonthAbove12(): void
    {
        // Regression: month 13 must throw, not silently produce Jan 2027
        // (createFromFormat('!Y-m-d','2026-13-01') would yield 2027-01-01).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Month must be in 1-12, got: 13');
        Navigation::gridIndexToDate(0, 13, 2026);
    }

    public function testGridIndexToDateRejectsMonthBelow1(): void
    {
        // Regression: month 0 must throw, not silently produce Dec 2025.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Month must be in 1-12, got: 0');
        Navigation::gridIndexToDate(0, 0, 2026);
    }

    public function testGridIndexToDateRejectsNonPositiveYear(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Year must be >= 1, got: 0');
        Navigation::gridIndexToDate(0, 5, 0);
    }

    public function testGridIndexToDateMonth12IsAccepted(): void
    {
        // Boundary: month 12 is valid. Dec 2026 firstDow=2 (Tue), index 2 = Dec 1.
        $date = Navigation::gridIndexToDate(2, 12, 2026);
        $this->assertSame('2026-12-01', $date?->format('Y-m-d'));
    }

    // -------------------------------------------------------------------------
    // Leap-year Feb 29 boundary.
    // -------------------------------------------------------------------------

    public function testGridIndexToDateLeapYearFeb29(): void
    {
        // Feb 2024 (leap): firstDow=4 (Thu), 29 days. Day 29 sits at index 32.
        $date = Navigation::gridIndexToDate(32, 2, 2024);
        $this->assertSame('2024-02-29', $date?->format('Y-m-d'),
            'Feb 29 must render in a leap year');
    }

    public function testGridIndexToDateNonLeapYearHasNoFeb29(): void
    {
        // Feb 2026 (non-leap): firstDow=0 (Sun), 28 days. Index 28 maps to
        // dayNum=29 which exceeds the 28-day month → null (no Feb 29).
        $this->assertNull(Navigation::gridIndexToDate(28, 2, 2026),
            'Feb 29 must not exist in a non-leap year');
    }

    public function testGridIndexToDateReturnsMidnight(): void
    {
        // The "!" format anchor zeroes the time-of-day so range/isToday
        // comparisons are not skewed by an inherited wall clock.
        $date = Navigation::gridIndexToDate(5, 5, 2026);
        $this->assertSame('2026-05-01 00:00:00', $date?->format('Y-m-d H:i:s'));
    }
}
