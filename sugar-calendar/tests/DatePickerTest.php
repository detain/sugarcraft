<?php

declare(strict_types=1);

namespace CandyCore\Calendar\Tests;

use CandyCore\Calendar\DatePicker;
use PHPUnit\Framework\TestCase;

final class DatePickerTest extends TestCase
{
    public function testNew(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $this->assertSame(5,  $dp->ViewMonth());
        $this->assertSame(2026, $dp->ViewYear());
    }

    public function testNewDefaultsToNow(): void
    {
        $dp = DatePicker::new();
        $this->assertGreaterThanOrEqual(1,  $dp->ViewMonth());
        $this->assertLessThanOrEqual(12, $dp->ViewMonth());
    }

    public function testGoToPreviousMonth(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->GoToPreviousMonth();

        $this->assertSame(4,  $dp->ViewMonth());
        $this->assertSame(2026, $dp->ViewYear());
    }

    public function testGoToPreviousMonthAtJanuary(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-01-15'))
            ->GoToPreviousMonth();

        $this->assertSame(12, $dp->ViewMonth());
        $this->assertSame(2025, $dp->ViewYear());
    }

    public function testGoToNextMonth(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->GoToNextMonth();

        $this->assertSame(6, $dp->ViewMonth());
    }

    public function testGoToNextMonthAtDecember(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-12-15'))
            ->GoToNextMonth();

        $this->assertSame(1,  $dp->ViewMonth());
        $this->assertSame(2027, $dp->ViewYear());
    }

    public function testGoToPreviousYear(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->GoToPreviousYear();

        $this->assertSame(2025, $dp->ViewYear());
    }

    public function testGoToNextYear(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->GoToNextYear();

        $this->assertSame(2027, $dp->ViewYear());
    }

    public function testGoToToday(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2020-01-01'))
            ->GoToToday();

        $this->assertSame((int) (new \DateTimeImmutable())->format('n'),  $dp->ViewMonth());
        $this->assertSame((int) (new \DateTimeImmutable())->format('Y'),  $dp->ViewYear());
    }

    public function testSetTime(): void
    {
        $dp = DatePicker::new()
            ->SetTime(new \DateTimeImmutable('2025-12-25'));

        $this->assertSame(12, $dp->ViewMonth());
        $this->assertSame(2025, $dp->ViewYear());
    }

    public function testCursorLeftBoundary(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->MoveCursorLeft()
            ->MoveCursorLeft();

        $this->assertSame(0, $dp->CursorIndex());
    }

    public function testCursorRightBoundary(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->MoveCursorRight(41);  // 42 steps = clamped to 41

        for ($i = 0; $i < 45; $i++) {
            $dp = $dp->MoveCursorRight();
        }

        $this->assertSame(41, $dp->CursorIndex());
    }

    public function testCursorUp(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->MoveCursorDown()
            ->MoveCursorDown()
            ->MoveCursorUp();

        $this->assertGreaterThanOrEqual(0, $dp->CursorIndex());
    }

    public function testSelectDate(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->SelectDate();

        $this->assertTrue($dp->IsSelecting());
        $this->assertNotNull($dp->SelectedDate());
    }

    public function testClearDate(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->SelectDate()
            ->ClearDate();

        $this->assertFalse($dp->IsSelecting());
        $this->assertNull($dp->SelectedDate());
    }

    public function testToggleSelection(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        $dp = $dp->ToggleSelection();
        $this->assertTrue($dp->IsSelecting());

        $dp = $dp->ToggleSelection();
        $this->assertFalse($dp->IsSelecting());
    }

    public function testDateAtCursorAfterNavigation(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $date = $dp->dateAtCursor();

        $this->assertNull($date); // cursor is at index 0 (outside month)
    }

    public function testDateAtCursorAfterMovingIn(): void
    {
        // Move cursor to first day of May 2026 (offset depends on first day of week)
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $firstOfMay2026 = \DateTimeImmutable::createFromFormat('Y-m-d', '2026-05-01');
        $firstDow = (int) $firstOfMay2026->format('w'); // day of week offset

        // Move cursor to the first day cell
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->MoveCursorRight();
        }

        $date = $dp->dateAtCursor();
        $this->assertSame('2026-05-01', $date?->format('Y-m-d'));
    }

    public function testViewRendersHeader(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $view = $dp->View();

        $this->assertStringContainsString('2026', $view);
        $this->assertStringContainsString('May', $view);
    }

    public function testViewRendersDayNames(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $view = $dp->View();

        $this->assertStringContainsString('Su', $view);
        $this->assertStringContainsString('Mo', $view);
        $this->assertStringContainsString('Tu', $view);
    }

    public function testImmutability(): void
    {
        $a = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $b = $a->GoToNextMonth();

        $this->assertSame(5,  $a->ViewMonth());
        $this->assertSame(6,  $b->ViewMonth());
    }

    public function testWithStylesReturnNewInstance(): void
    {
        $a = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $b = $a->WithHeaderStyle('1;31')
               ->WithTodayStyle('1;32')
               ->WithSelectedStyle('1;34');

        $this->assertNotSame($a, $b);
    }

    public function testSelectDateAfterNavigating(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->MoveCursorDown()
            ->MoveCursorRight()
            ->SelectDate();

        $this->assertNotNull($dp->SelectedDate());
        $this->assertTrue($dp->IsSelecting());
    }
}
