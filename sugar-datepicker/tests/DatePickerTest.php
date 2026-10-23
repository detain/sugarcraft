<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use SugarCraft\DatePicker\DatePicker;
use SugarCraft\DatePicker\Navigation;
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
        // MoveCursorRight takes no parameter; index is clamped at 41.
        // The 45-step loop exercises the clamp boundary.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

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
        // May 2026: firstDow=5 (Fri), index 5 = May 1, index 13 = May 9
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

    // -------------------------------------------------------------------------
    // Edge-case coverage
    // -------------------------------------------------------------------------

    public function testHandleKeyEnterOutsideRangeModeSelectsDate(): void
    {
        // 2026-10-23 (docs-fix-1023 E2 / M1): this pin used to assert the OLD
        // broken polarity — 'enter' outside range mode was a no-op, which made
        // Model.php's documented Enter→select contract (and the README
        // promise) unreachable through the keyboard path. The fix makes Enter
        // select the highlighted date in single mode (same semantics, and the
        // same min/max refusal, as SelectDate()).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));

        // Navigate to a valid day first: index 5 = May 1 (May 2026 starts Friday)
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->MoveCursorRight();
        }

        $dp = $dp->handleKey('enter');

        $this->assertTrue($dp->IsSelecting(),
            'Enter outside range mode must enter selection mode');
        $this->assertNotNull($dp->SelectedDate(),
            'Enter outside range mode must set a selected date');
        $this->assertSame('2026-05-01', $dp->SelectedDate()->format('Y-m-d'));
    }

    public function testHandleKeyEscapeOutsideRangeModeClearsDate(): void
    {
        // 2026-10-23 (docs-fix-1023 E2 / M1): Escape clears the selection in
        // single mode (it already did in range mode, clearing the range).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'))
            ->handleKey('end')   // index 41
            ->handleKey('home'); // index 0

        $dp = $dp->SelectDate();
        $this->assertTrue($dp->IsSelecting());

        $dp = $dp->handleKey('esc');
        $this->assertFalse($dp->IsSelecting(),
            'Escape outside range mode must exit selection mode');
        $this->assertNull($dp->SelectedDate(),
            'Escape outside range mode must clear the selected date');
    }

    public function testGoToPreviousYearClampsAtYearOne(): void
    {
        // 2026-10-23 (docs-fix-1023 E2 / M4): the calendar floor is January
        // 0001. Decrementing used to underflow into year 0, where the cursor
        // clamp dereferenced a false date and crashed.
        $dp = DatePicker::new(new \DateTimeImmutable('0002-06-15'));

        $dp = $dp->GoToPreviousYear();
        $this->assertSame(1, $dp->ViewYear());
        $this->assertSame(6, $dp->ViewMonth());

        // Further presses stay pinned at the floor — no crash, no year 0.
        $dp = $dp->GoToPreviousYear();
        $this->assertSame(1, $dp->ViewYear());
        $dp = $dp->GoToPreviousMonth();
        $this->assertSame(1, $dp->ViewYear());
        $this->assertSame(1, $dp->ViewMonth());

        // Queries and rendering all work at the floor.
        $this->assertIsString($dp->View());
        $this->assertIsString($dp->View(30, true));
        $selected = $dp->SelectDate();
        $this->assertTrue($selected->IsSelecting());
    }

    public function testNavigatingPastYear9999DoesNotCrash(): void
    {
        // 2026-10-23 (docs-fix-1023 E2 / M4): year 10000 cannot be written as
        // %04d, so createFromFormat returns false there. The clamp path must
        // survive it (5-digit years only arise from unbounded GoToNextYear in
        // an unbounded picker — this pin documents the tolerated upper edge).
        $dp = DatePicker::new(new \DateTimeImmutable('9999-12-31'));
        $dp = $dp->GoToNextYear();

        $this->assertSame(10000, $dp->ViewYear());
        // Rendering must not throw and must produce a string.
        $this->assertIsString($dp->View());
        // The cursor date is unresolvable, so selection falls back to the
        // first-of-month probe which is also null → SelectDate REFUSES.
        $this->assertNull($dp->dateAtCursor());
        $refused = $dp->SelectDate();
        $this->assertFalse($refused->IsSelecting());
        $this->assertNull($refused->SelectedDate());
    }

    public function testViewWidthIsClampedNotRejected(): void
    {
        // 2026-10-23 (docs-fix-1023 E2): the docblock used to claim "must be";
        // the real law is a silent clamp to 15-63.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        $this->assertSame($dp->View(15), $dp->View(5));
        $this->assertSame($dp->View(15), $dp->View(0));
        $this->assertSame($dp->View(63), $dp->View(100));
        $this->assertSame($dp->View(21), $dp->View());
    }

    public function testClampCursorAfterNavigatingToShorterMonth(): void
    {
        // Start on May 2026 (31 days, firstDow=5), cursor at index 41 (End)
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'))
            ->handleKey('end');  // cursorIndex = 41

        $this->assertSame(41, $dp->CursorIndex());

        // Navigate to April 2026 (30 days, firstDow=3)
        // After navigation, clampCursor should pull index 41 back to 3 + 30 - 1 = 32
        $dp2 = $dp->GoToPreviousMonth();

        // April 30 is at index 3 + 29 = 32 (firstDow=3, lastDayIndex=32)
        $this->assertSame(32, $dp2->CursorIndex(),
            'Cursor must be clamped to last valid index of shorter month');
    }

    // -------------------------------------------------------------------------
    // [BUG] View() immutability — rendering must not mutate $this.
    // -------------------------------------------------------------------------

    public function testViewDoesNotMutateInstance(): void
    {
        // Regression: View() used to memoise cachedView/cachedCells/cacheValid
        // ONTO $this, violating immutability. serialize() captures every private
        // field, so a pure View() leaves the snapshot byte-identical.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));

        $before = serialize($dp);
        $dp->View();
        $dp->View(21, true);
        $dp->View(31);
        $after = serialize($dp);

        $this->assertSame($before, $after,
            'View() must not mutate the instance (no internal render cache)');
    }

    public function testViewIsDeterministicAcrossCalls(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));

        $this->assertSame($dp->View(), $dp->View(),
            'Repeated View() calls on the same instance must be identical');
    }

    // -------------------------------------------------------------------------
    // ISO week column boundary (year rollover at the top of the grid).
    // -------------------------------------------------------------------------

    public function testViewWeekNumbersCrossYearBoundary(): void
    {
        // Jan 2026: firstDow=4 (Thu). The first displayed (Sunday-first) week
        // row begins Sun 2025-12-28 → ISO week 52 of 2025, then week 1 of 2026.
        // Regression: a malformed "+-4 days" offset used to shift these forward.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-01-15'))
            ->withToday(new \DateTimeImmutable('2026-01-15'));

        $plain = \preg_replace('/\x1b\[[0-9;]*m/', '', $dp->View(21, true));
        $lines = \array_values(\array_filter(
            \explode("\n", $plain),
            static fn (string $l): bool => \trim($l) !== '',
        ));

        // Locate the week rows: the first day-grid row contains "1  2  3".
        $firstWeekRow = null;
        foreach ($lines as $line) {
            if (\str_contains($line, ' 1  2  3')) {
                $firstWeekRow = $line;
                break;
            }
        }
        $this->assertNotNull($firstWeekRow, 'first week grid row must be present');
        $this->assertStringStartsWith('52', \ltrim($firstWeekRow),
            'Week row crossing the year boundary must be ISO week 52, not shifted');
        $this->assertStringContainsString('52', $plain,
            'Boundary ISO week 52 must appear in the week-number column');
    }

    // -------------------------------------------------------------------------
    // [DEDUP] dateAtCursor() delegates to Navigation::gridIndexToDate().
    // -------------------------------------------------------------------------

    public function testDateAtCursorDelegatesToNavigation(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        for ($i = 0; $i < 41; $i++) {
            $expected = Navigation::gridIndexToDate($i, 5, 2026);
            $actual = $dp->dateAtCursor();

            $this->assertSame(
                $expected?->format('Y-m-d'),
                $actual?->format('Y-m-d'),
                "dateAtCursor() must match Navigation::gridIndexToDate() at index $i",
            );
            $dp = $dp->MoveCursorRight();
        }
    }

    public function testClampCursorMarchToFebruary(): void
    {
        // March 2026 (31 days, firstDow=0), cursor at End (index 41).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-03-10'))
            ->withToday(new \DateTimeImmutable('2026-03-15'))
            ->handleKey('end');
        $this->assertSame(41, $dp->CursorIndex());

        // → Feb 2026 (28 days, firstDow=0): last valid index = 0 + 28 - 1 = 27.
        $feb = $dp->GoToPreviousMonth();
        $this->assertSame(2, $feb->ViewMonth());
        $this->assertSame(27, $feb->CursorIndex(),
            'Cursor must clamp to Feb 28 (index 27) when leaving a 31-day month');
        $this->assertSame('2026-02-28', $feb->dateAtCursor()?->format('Y-m-d'));
    }

    public function testCursorDateMappingsAgree(): void
    {
        // dateAtCursor() (uses viewMonth/viewYear) and Navigation::gridIndexToDate()
        // must agree for every grid index 0-41 in May 2026.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));
        $month = $dp->ViewMonth();
        $year = $dp->ViewYear();

        for ($idx = 0; $idx <= 41; $idx++) {
            // Simulate what dateAtCursor does for this index
            $firstOfMonth = new \DateTimeImmutable("$year-$month-01");
            $firstDow = (int) $firstOfMonth->format('w');
            $daysInMonth = (int) $firstOfMonth->format('t');
            $dayNum = $idx - $firstDow + 1;

            if ($dayNum < 1 || $dayNum > $daysInMonth) {
                $expected = null;
            } else {
                $expected = $firstOfMonth->modify('+' . ($dayNum - 1) . ' days');
            }

            $actual = Navigation::gridIndexToDate($idx, $month, $year);

            if ($expected === null) {
                $this->assertNull($actual,
                    "Index $idx: expected null but gridIndexToDate returned a date");
            } else {
                $this->assertSame(
                    $expected->format('Y-m-d'),
                    $actual?->format('Y-m-d'),
                    "Index $idx: dateAtCursor formula and gridIndexToDate must agree"
                );
            }
        }
    }

    // -------------------------------------------------------------------------
    // handleRangeEnter edge cases
    // -------------------------------------------------------------------------

    public function testHandleRangeEnterWithNullDateIsNoOp(): void
    {
        // Cursor at index 0 (empty cell before May 1, 2026)
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        // Cursor is at 0 (empty cell), Enter should be a no-op
        $dp = $dp->handleKey('enter');

        $this->assertNull($dp->rangeStart(),
            'Enter on empty cell must not set rangeStart');
        $this->assertNull($dp->rangeEnd());
    }

    public function testHandleRangeEnterWhenBothSetStartsFresh(): void
    {
        // Set up a complete range first
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        $firstDow = (int) (new \DateTimeImmutable('2026-05-01'))->format('w');
        for ($i = 0; $i < $firstDow; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        // First Enter: rangeStart = May 1
        $dp = $dp->handleKey('enter');

        // Move to May 5
        for ($i = 0; $i < 4; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        // Second Enter: rangeEnd = May 5
        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());
        $this->assertNotNull($dp->rangeEnd());

        // Move to May 10
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        // Third Enter: both were set, so start fresh with May 10
        $dp = $dp->handleKey('enter');

        $this->assertSame('2026-05-10', $dp->rangeStart()->format('Y-m-d'),
            'Third Enter when both set must start fresh');
        $this->assertNull($dp->rangeEnd(),
            'Third Enter when both set must clear rangeEnd');
    }

    // -------------------------------------------------------------------------
    // selectedTodayStyle: day is both today AND selected
    // -------------------------------------------------------------------------

    public function testSelectedTodayStyleWhenDateIsBothTodayAndSelected(): void
    {
        // Pin "today" to May 15, 2026 and move cursor to May 15
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));

        // Navigate to May 15 (firstDow=5 for May 1, so May 15 is at index 5+14=19)
        for ($i = 0; $i < 19; $i++) {
            $dp = $dp->MoveCursorRight();
        }

        // Verify we're on May 15
        $this->assertSame('2026-05-15', $dp->dateAtCursor()?->format('Y-m-d'));

        // Select the date
        $dp = $dp->SelectDate();

        $this->assertTrue($dp->IsSelecting());
        $this->assertSame('2026-05-15', $dp->SelectedDate()?->format('Y-m-d'));

        // View must render (the selectedTodayStyle branch applies)
        $view = $dp->View();
        $this->assertIsString($view);
    }

    public function testWithoutTodayDropsThePinnedReference(): void
    {
        // docs-fix-1023 E2 (XSet law): withToday had no counterpart unset.
        $pinned = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-12-25'));

        // With the pin, GoToToday lands on the injected date's month.
        $this->assertSame(12, $pinned->GoToToday()->ViewMonth());

        $unpinned = $pinned->withoutToday();
        $this->assertNotSame($pinned, $unpinned);

        // Without it, GoToToday follows the real wall clock — prove the
        // reference is gone by comparing against a bare picker's behaviour.
        $bare = DatePicker::new(new \DateTimeImmutable('2026-05-01'))->GoToToday();
        $this->assertSame((int) $bare->ViewMonth(), $unpinned->GoToToday()->ViewMonth());

        // Immutability: the pinned instance keeps its injected today.
        $this->assertSame(12, $pinned->GoToToday()->ViewMonth());
    }

    public function testSelectDateFallsBackToFirstOfMonthOnEmptyCell(): void
    {
        // The empty-cell fallback is now also a BOUND-checked candidate
        // (docs-fix-1023 E2): with no bound set it selects May 1 from index 0.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'))
            ->handleKey('home');

        $this->assertNull($dp->dateAtCursor(), 'Index 0 is an empty lead-in cell');

        $selected = $dp->SelectDate();
        $this->assertTrue($selected->IsSelecting());
        $this->assertSame('2026-05-01', $selected->SelectedDate()?->format('Y-m-d'));
    }
}
