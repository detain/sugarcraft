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
}
