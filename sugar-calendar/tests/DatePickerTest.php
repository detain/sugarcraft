<?php

declare(strict_types=1);

namespace SugarCraft\Calendar\Tests;

use SugarCraft\Calendar\DatePicker;
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

    // -------------------------------------------------------------------------
    // Range selection + keyboard navigation
    // -------------------------------------------------------------------------

    public function testWithRangeModeEnablesRangeSelection(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        $this->assertTrue($dp->isRangeMode());
    }

    public function testWithRangeModeFalseClearsRange(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true)
            ->withRangeMode(false);

        $this->assertFalse($dp->isRangeMode());
        $this->assertNull($dp->rangeStart());
        $this->assertNull($dp->rangeEnd());
    }

    public function testHandleKeyLeft(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        // cursor starts at 0, move right first then left
        $dp = $dp->handleKey('right');
        $this->assertSame(1, $dp->CursorIndex());

        $dp = $dp->handleKey('left');
        $this->assertSame(0, $dp->CursorIndex());
    }

    public function testHandleKeyRight(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->handleKey('right');
        $this->assertSame(1, $dp->CursorIndex());
    }

    public function testHandleKeyUp(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->MoveCursorDown()->MoveCursorDown();
        $dp = $dp->handleKey('up');
        $this->assertLessThan(14, $dp->CursorIndex());
    }

    public function testHandleKeyDown(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->handleKey('down');
        $this->assertSame(7, $dp->CursorIndex());
    }

    public function testHandleKeyHome(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->MoveCursorDown()
            ->MoveCursorRight();

        $dp = $dp->handleKey('home');
        $this->assertSame(0, $dp->CursorIndex());
    }

    public function testHandleKeyEnd(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->handleKey('end');
        $this->assertSame(41, $dp->CursorIndex());
    }

    public function testHandleKeyEnterSetsRangeStart(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        // Navigate to a valid day cell
        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->MoveCursorRight();
        }

        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());
        $this->assertSame('2026-05-01', $dp->rangeStart()->format('Y-m-d'));
        $this->assertNull($dp->rangeEnd());
    }

    public function testHandleKeyEnterSetsRangeEnd(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        // Navigate to May 1
        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        // Navigate to May 5
        for ($i = 0; $i < 4; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());
        $this->assertNotNull($dp->rangeEnd());
        $this->assertSame('2026-05-01', $dp->rangeStart()->format('Y-m-d'));
        $this->assertSame('2026-05-05', $dp->rangeEnd()->format('Y-m-d'));
    }

    public function testHandleKeyEscapeClearsRange(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());

        $dp = $dp->handleKey('esc');
        $this->assertNull($dp->rangeStart());
        $this->assertNull($dp->rangeEnd());
    }

    public function testRangeSelectionNormalizesStartEnd(): void
    {
        // May 2026: firstDow = 5 (Fri), so index 5 = May 1, index 14 = May 10
        // Start at index 5 (May 1) and navigate to May 10
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        for ($i = 0; $i < 14; $i++) {
            $dp = $dp->MoveCursorRight();
        }

        $dp = $dp->withRangeMode(true);
        $dp = $dp->handleKey('enter'); // rangeStart = May 10

        // Navigate back to May 5 (index 9)
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->MoveCursorLeft();
        }

        $dp = $dp->handleKey('enter'); // rangeEnd = May 5, then normalize to start=May 5, end=May 10

        $this->assertSame('2026-05-05', $dp->rangeStart()->format('Y-m-d'));
        $this->assertSame('2026-05-10', $dp->rangeEnd()->format('Y-m-d'));
    }

    // -------------------------------------------------------------------------
    // Cursor rendering
    // -------------------------------------------------------------------------

    public function testWithCursorStyleAffectsView(): void
    {
        // May 2026: firstDow=5 (Fri), index 5 = May 1
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));

        // Move cursor to index 5 (May 1, a real day cell) to ensure cursor renders
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->MoveCursorRight();
        }

        $viewDefault = $dp->View();

        // Underline style (SGR 4) should produce different bytes than default (SGR 7 reverse)
        $viewUnderline = $dp->WithCursorStyle('4')->View();

        $this->assertNotSame($viewDefault, $viewUnderline,
            'Different cursor styles must produce different View() output');
    }

    public function testCursorIndexReflectedInView(): void
    {
        // Pin today to May 2026 and move cursor to a real day cell (index 5 = May 1)
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->MoveCursorRight();
        }

        $view = $dp->View();

        // The View() output must contain SGR [0;7m (Buffer emits reset+attrs)
        // at the cursor cell (May 1 at index 5)
        $this->assertStringContainsString("\x1b[0;7m", $view,
            'View() must contain SGR 0;7 (reverse) at the cursor cell');
    }

    public function testCursorMovesHighlight(): void
    {
        // May 2026: firstDow=5 (Fri), index 5 = May 1, index 12 = May 8, index 13 = May 9
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));

        // Cursor at index 5 (May 1)
        $view0 = $dp->View();

        // Move cursor down (index 12) then right (index 13 = May 9)
        $dp2 = $dp->MoveCursorDown()->MoveCursorRight();
        $viewMoved = $dp2->View();

        $this->assertNotSame($view0, $viewMoved,
            'Moving the cursor must change the View() output');

        // The moved-cursor output must contain the reverse SGR at the new cell
        $this->assertStringContainsString("\x1b[0;7m", $viewMoved,
            'Moved cursor position must carry SGR 0;7 in output');
    }
}
