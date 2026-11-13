<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use SugarCraft\CellBuffer\Style;
use SugarCraft\DatePicker\DatePicker;
use SugarCraft\DatePicker\Navigation;
use PHPUnit\Framework\TestCase;

final class DatePickerTest extends TestCase
{
    public function testNew(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $this->assertSame(5,  $dp->viewMonth());
        $this->assertSame(2026, $dp->viewYear());
    }

    public function testNewDefaultsToNow(): void
    {
        $dp = DatePicker::new();
        $this->assertGreaterThanOrEqual(1,  $dp->viewMonth());
        $this->assertLessThanOrEqual(12, $dp->viewMonth());
    }

    public function testGoToPreviousMonth(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->goToPreviousMonth();

        $this->assertSame(4,  $dp->viewMonth());
        $this->assertSame(2026, $dp->viewYear());
    }

    public function testGoToPreviousMonthAtJanuary(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-01-15'))
            ->goToPreviousMonth();

        $this->assertSame(12, $dp->viewMonth());
        $this->assertSame(2025, $dp->viewYear());
    }

    public function testGoToNextMonth(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->goToNextMonth();

        $this->assertSame(6, $dp->viewMonth());
    }

    public function testGoToNextMonthAtDecember(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-12-15'))
            ->goToNextMonth();

        $this->assertSame(1,  $dp->viewMonth());
        $this->assertSame(2027, $dp->viewYear());
    }

    public function testGoToPreviousYear(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->goToPreviousYear();

        $this->assertSame(2025, $dp->viewYear());
    }

    public function testGoToNextYear(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->goToNextYear();

        $this->assertSame(2027, $dp->viewYear());
    }

    public function testGoToToday(): void
    {
        // Single clock window: snapshot BEFORE and AFTER the picker's own
        // read, so a midnight/new-year straddle can never produce a monthly
        // false red from three separately-read references.
        $before = new \DateTimeImmutable();
        $dp = DatePicker::new(new \DateTimeImmutable('2020-01-01'))
            ->goToToday();
        $after = new \DateTimeImmutable();

        $actual = [$dp->viewMonth(), $dp->viewYear()];
        $expected = [(int) $before->format('n'), (int) $before->format('Y')];
        $boundary = [(int) $after->format('n'), (int) $after->format('Y')];

        if ($expected === $boundary) {
            $this->assertSame($expected, $actual, 'clock was stable across the call');
        } else {
            $this->assertContains($actual, [$expected, $boundary], 'the picker read the clock between our snapshots');
        }
    }

    public function testGoToTodayAimsTheCursorAtTodaysCell(): void
    {
        // Synthetic today keeps the pin deterministic (sugar-datepicker-037):
        // start focused on some unrelated weekday column, jump to today, and
        // require the cursor to land ON today, not on the carried index.
        $picker = DatePicker::new(new \DateTimeImmutable('2020-01-01'))
            ->withToday(new \DateTimeImmutable('2026-12-31'))
            ->goToToday();

        $this->assertSame(12, $picker->viewMonth());
        $this->assertSame(2026, $picker->viewYear());
        $this->assertSame(
            '2026-12-31',
            $picker->dateAtCursor()?->format('Y-m-d'),
            'goToToday must re-aim the cursor at today\'s cell',
        );
    }

    public function testGoToTodayWithOutOfBoundsTodayAimsTheDimmedCellLikeMoveToDate(): void
    {
        // Shared law with setTime()/moveToDate() (dp-25/31): cursorForDate
        // aims at the target whenever it is inside the viewed month — even a
        // dimmed out-of-bounds day — and only re-aims to the first
        // selectable day when it cannot aim at all. A dimmed cursor is safe:
        // Enter there records a selection_refused (dp-09).
        $picker = DatePicker::new(new \DateTimeImmutable('2020-01-01'))
            ->withToday(new \DateTimeImmutable('2026-01-05'))
            ->withMinDate(new \DateTimeImmutable('2026-01-20'))
            ->goToToday();

        $this->assertSame(1, $picker->viewMonth());
        $this->assertSame('2026-01-05', $picker->dateAtCursor()?->format('Y-m-d'));
    }

    public function testGoToTodayWhenBoundsSuppressTodaysMonthFallsBackToFirstSelectable(): void
    {
        // Today is January 2026 but the window ends 2025-12-31: the view
        // clamps to December and the cursor takes the view's first
        // selectable day instead of an unaimable target.
        $picker = DatePicker::new(new \DateTimeImmutable('2020-01-01'))
            ->withToday(new \DateTimeImmutable('2026-01-05'))
            ->withMaxDate(new \DateTimeImmutable('2025-12-31'))
            ->goToToday();

        $this->assertSame(2025, $picker->viewYear());
        $this->assertSame(12, $picker->viewMonth());
        $this->assertSame('2025-12-01', $picker->dateAtCursor()?->format('Y-m-d'));
    }

    public function testSetTime(): void
    {
        $dp = DatePicker::new()
            ->setTime(new \DateTimeImmutable('2025-12-25'));

        $this->assertSame(12, $dp->viewMonth());
        $this->assertSame(2025, $dp->viewYear());
    }

    public function testCursorLeftBoundary(): void
    {
        // The raw arrow clamp is the grid floor 0 (not the first valid day —
        // arrows deliberately may rest on a lead-in blank; only Home/End snap
        // to a real day). Walk well past the left edge and confirm the clamp.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        for ($i = 0; $i < 8; $i++) {
            $dp = $dp->moveCursorLeft();
        }

        $this->assertSame(0, $dp->cursorIndex());
    }

    public function testCursorRightBoundary(): void
    {
        // MoveCursorRight takes no parameter; index is clamped at 41.
        // The 45-step loop exercises the clamp boundary.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        for ($i = 0; $i < 45; $i++) {
            $dp = $dp->moveCursorRight();
        }

        $this->assertSame(41, $dp->cursorIndex());
    }

    public function testCursorUp(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->moveCursorDown()
            ->moveCursorDown()
            ->moveCursorUp();

        $this->assertGreaterThanOrEqual(0, $dp->cursorIndex());
    }

    public function testSelectDate(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->selectDate();

        $this->assertTrue($dp->isSelecting());
        $this->assertNotNull($dp->selectedDate());
    }

    public function testClearDate(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->selectDate()
            ->clearDate();

        $this->assertFalse($dp->isSelecting());
        $this->assertNull($dp->selectedDate());
    }

    public function testToggleSelection(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        $dp = $dp->toggleSelection();
        $this->assertTrue($dp->isSelecting());

        $dp = $dp->toggleSelection();
        $this->assertFalse($dp->isSelecting());
    }

    public function testDateAtCursorAfterNavigation(): void
    {
        // D9: the constructor now seeds the cursor on the viewed date, so a
        // fresh May-1 picker shows May 1 at the cursor rather than an empty
        // cell. This is the fix for "new(date) opened focus-invisible".
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $date = $dp->dateAtCursor();

        $this->assertNotNull($date); // cursor seeded on the real first day
        $this->assertSame('2026-05-01', $date?->format('Y-m-d'));
    }

    public function testDateAtCursorOnEmptyLeadCellIsNull(): void
    {
        // The empty lead-in cell (before the 1st) is still reachable by arrow
        // and yields null — Enter from it falls back to the first of month.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->moveCursorLeft(); // idx5 (May1) -> idx0 (Sun blank)
        }

        $this->assertSame(0, $dp->cursorIndex());
        $this->assertNull($dp->dateAtCursor());
    }

    public function testViewRendersHeader(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $view = $dp->view();

        $this->assertStringContainsString('2026', $view);
        $this->assertStringContainsString('May', $view);
    }

    public function testViewRendersDayNames(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $view = $dp->view();

        $this->assertStringContainsString('Su', $view);
        $this->assertStringContainsString('Mo', $view);
        $this->assertStringContainsString('Tu', $view);
    }

    public function testImmutability(): void
    {
        $a = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $b = $a->goToNextMonth();

        $this->assertSame(5,  $a->viewMonth());
        $this->assertSame(6,  $b->viewMonth());
    }

    public function testWithStylesReturnNewInstance(): void
    {
        $a = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $b = $a->withHeaderStyle('1;31')
               ->withTodayStyle('1;32')
               ->withSelectedStyle('1;34');

        $this->assertNotSame($a, $b);
    }

    public function testSelectDateAfterNavigating(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->moveCursorDown()
            ->moveCursorRight()
            ->selectDate();

        $this->assertNotNull($dp->selectedDate());
        $this->assertTrue($dp->isSelecting());
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
        // D9: the constructor seeds the cursor on the viewed date (May 1 →
        // grid index 5 for a Friday-start May 2026), no longer on cell 0.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->handleKey('right');
        $this->assertSame(6, $dp->cursorIndex());

        $dp = $dp->handleKey('left');
        $this->assertSame(5, $dp->cursorIndex());
    }

    public function testHandleKeyRight(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->handleKey('right');
        $this->assertSame(6, $dp->cursorIndex());
    }

    public function testHandleKeyUp(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->moveCursorDown()->moveCursorDown();
        $dp = $dp->handleKey('up');
        $this->assertLessThan(14, $dp->cursorIndex());
    }

    public function testHandleKeyDown(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->handleKey('down');
        $this->assertSame(12, $dp->cursorIndex());
    }

    public function testHandleKeyHome(): void
    {
        // D21 + dp-11: Home jumps to the first SELECTABLE day. With no bounds
        // configured that is May 1 (index 5) — the unclamped contract survives
        // only as the bounds-free special case of the scan.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->moveCursorDown()
            ->moveCursorRight();

        $dp = $dp->handleKey('home');
        $this->assertSame(5, $dp->cursorIndex());
    }

    public function testHandleKeyEnd(): void
    {
        // D21 + dp-11: End lands on the last selectable day — May 31 (index 35)
        // unbounded, not the raw grid floor of 41.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $dp = $dp->handleKey('end');
        $this->assertSame(35, $dp->cursorIndex());
    }

    public function testHomeClampsToFirstSelectableDayPastMin(): void
    {
        // dp-11: min 2026-05-10 — Home in May must park on May 10 (index 14),
        // never the dimmed May 1. This rewrites the disclosed old pin that
        // taught the grid-valid landing (the constraints.php trap).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-06-15'))
            ->withMinDate(new \DateTimeImmutable('2026-05-10'))
            ->goToPreviousMonth()
            ->goToPreviousMonth()
            ->handleKey(DatePicker::KEY_HOME);

        $this->assertSame(14, $dp->cursorIndex());
        $this->assertSame('2026-05-10', $dp->dateAtCursor()?->format('Y-m-d'));
    }

    public function testEndClampsToLastSelectableDayBeforeMax(): void
    {
        // dp-11 mirror leg: max 2026-05-20 — End parks on May 20 (index 24).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withMaxDate(new \DateTimeImmutable('2026-05-20'))
            ->handleKey(DatePicker::KEY_END);

        $this->assertSame(24, $dp->cursorIndex());
        $this->assertSame('2026-05-20', $dp->dateAtCursor()?->format('Y-m-d'));
    }

    public function testTightenedBoundFollowsViewIntoBoundMonth(): void
    {
        // dp-11 context pin: withBounds pins the view to the bound month
        // (boundedView), so a fully out-of-window month never reaches Home/End
        // through the public API — the scan fallback is defensive. Seed May 1,
        // min 2026-06-15: the view jumps to June and Home parks on the bound.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withMinDate(new \DateTimeImmutable('2026-06-15'));

        $this->assertSame(6, $dp->viewMonth());
        $home = $dp->handleKey(DatePicker::KEY_HOME);
        $this->assertSame('2026-06-15', $home->dateAtCursor()?->format('Y-m-d'));
    }

    public function testHandleKeyEnterSetsRangeStart(): void
    {
        // D9: the cursor is already on May 1 at construction, so Enter arms the
        // range on the first day directly (no lead-in walk needed).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());
        $this->assertSame('2026-05-01', $dp->rangeStart()->format('Y-m-d'));
        $this->assertNull($dp->rangeEnd());
    }

    public function testHandleKeyEnterSetsRangeEnd(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);

        // Cursor seeds on May 1; arm the start there.
        $dp = $dp->handleKey('enter');

        // Navigate to May 5
        for ($i = 0; $i < 4; $i++) {
            $dp = $dp->moveCursorRight();
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
            $dp = $dp->moveCursorRight();
        }
        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());

        $dp = $dp->handleKey('esc');
        $this->assertNull($dp->rangeStart());
        $this->assertNull($dp->rangeEnd());
    }

    public function testRangeSelectionNormalizesStartEnd(): void
    {
        // May 2026: firstDow = 5 (Fri), so index 5 = May 1, index 14 = May 10.
        // Aim the cursor directly at the intended days (D9 seeds the cursor on
        // the viewed date, so a fixed count of rights would drift).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true)
            ->moveToDate(new \DateTimeImmutable('2026-05-10'));

        $dp = $dp->handleKey('enter'); // rangeStart = May 10

        // Navigate back to May 5 (index 9)
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->moveCursorLeft();
        }

        $dp = $dp->handleKey('enter'); // rangeEnd = May 5, then normalize to start=May 5, end=May 10

        $this->assertSame('2026-05-05', $dp->rangeStart()->format('Y-m-d'));
        $this->assertSame('2026-05-10', $dp->rangeEnd()->format('Y-m-d'));
    }

    // -------------------------------------------------------------------------
    // withRange — programmatic preset (dp-19)
    // -------------------------------------------------------------------------

    public function testWithRangeRestoresASavedPair(): void
    {
        $from = new \DateTimeImmutable('2026-05-04');
        $to = new \DateTimeImmutable('2026-05-08');

        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))->withRange($from, $to);

        $this->assertSame('2026-05-04', $dp->rangeStart()?->format('Y-m-d'));
        $this->assertSame('2026-05-08', $dp->rangeEnd()?->format('Y-m-d'));
        // The receiver is untouched (immutability) and the pair survives navigation.
        $this->assertNull(DatePicker::new(new \DateTimeImmutable('2026-05-01'))->rangeStart());
        $moved = $dp->goToNextMonth();
        $this->assertSame('2026-05-04', $moved->rangeStart()?->format('Y-m-d'));
    }

    public function testWithRangeDoesNotImplyRangeMode(): void
    {
        $single = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRange(new \DateTimeImmutable('2026-05-04'), new \DateTimeImmutable('2026-05-08'));

        $this->assertFalse($single->isRangeMode(), 'the endpoint preset must not own the mode');

        $ranged = $single->withRangeMode(true);
        $this->assertTrue($ranged->isRangeMode());
        $this->assertSame('2026-05-04', $ranged->rangeStart()?->format('Y-m-d'), 'arming mode keeps the preset');

        $off = $ranged->withRangeMode(false);
        $this->assertNull($off->rangeStart(), 'disarming mode still clears endpoints (existing law)');
    }

    public function testWithRangeSingleEndpointArmsJustTheStart(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRange(new \DateTimeImmutable('2026-05-04'));

        $this->assertSame('2026-05-04', $dp->rangeStart()?->format('Y-m-d'));
        $this->assertNull($dp->rangeEnd());
    }

    public function testWithRangeNullClearsBothEndpointsIgnoringEnd(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRange(new \DateTimeImmutable('2026-05-04'), new \DateTimeImmutable('2026-05-08'))
            ->withRange(null, new \DateTimeImmutable('2026-05-20'));

        $this->assertNull($dp->rangeStart());
        $this->assertNull($dp->rangeEnd());
    }

    public function testWithRangeRefusesCrossedPair(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('2026-05-02');

        $dp->withRange(new \DateTimeImmutable('2026-05-10'), new \DateTimeImmutable('2026-05-02'));
    }

    public function testWithRangeRefusesOutOfBoundsEndpoints(): void
    {
        $bounded = DatePicker::new(new \DateTimeImmutable('2026-05-10'))
            ->withMinDate(new \DateTimeImmutable('2026-05-10'))
            ->withMaxDate(new \DateTimeImmutable('2026-05-20'));

        try {
            $bounded->withRange(new \DateTimeImmutable('2026-05-01'), new \DateTimeImmutable('2026-05-15'));
            $this->fail('start outside the window must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('range start 2026-05-01', $e->getMessage());
        }

        try {
            $bounded->withRange(new \DateTimeImmutable('2026-05-15'), new \DateTimeImmutable('2026-06-01'));
            $this->fail('end outside the window must throw');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('range end 2026-06-01', $e->getMessage());
        }

        // Inclusive edges of the window are accepted.
        $ok = $bounded->withRange(new \DateTimeImmutable('2026-05-10'), new \DateTimeImmutable('2026-05-20'));
        $this->assertSame('2026-05-20', $ok->rangeEnd()?->format('Y-m-d'));
    }

    public function testWithRangeCrossedCheckIsTimezoneSafe(): void
    {
        // Same wall day in a later zone must NOT read as crossed (day-key law).
        $start = new \DateTimeImmutable('2026-05-10T09:00:00+00:00');
        $endTokyo = new \DateTimeImmutable('2026-05-10T23:00:00+09:00'); // 14:00Z, same day

        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))->withRange($start, $endTokyo);

        $this->assertNotNull($dp->rangeEnd());
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
            $dp = $dp->moveCursorRight();
        }

        $viewDefault = $dp->view();

        // Underline style (SGR 4) should produce different bytes than default (SGR 7 reverse)
        $viewUnderline = $dp->withCursorStyle('4')->view();

        $this->assertNotSame($viewDefault, $viewUnderline,
            'Different cursor styles must produce different View() output');
    }

    public function testCursorIndexReflectedInView(): void
    {
        // Pin today to May 2026 and move cursor to a real day cell (index 5 = May 1)
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->moveCursorRight();
        }

        $view = $dp->view();

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
        $view0 = $dp->view();

        // Move cursor down (index 12) then right (index 13 = May 9)
        $dp2 = $dp->moveCursorDown()->moveCursorRight();
        $viewMoved = $dp2->view();

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

        // D9: the constructor already seeds the cursor on May 1 (grid index 5
        // for a Friday-start May 2026), so Enter selects it directly.
        $dp = $dp->handleKey('enter');

        $this->assertTrue($dp->isSelecting(),
            'Enter outside range mode must enter selection mode');
        $this->assertNotNull($dp->selectedDate(),
            'Enter outside range mode must set a selected date');
        $this->assertSame('2026-05-01', $dp->selectedDate()->format('Y-m-d'));
    }

    public function testHandleKeyEscapeOutsideRangeModeClearsDate(): void
    {
        // 2026-10-23 (docs-fix-1023 E2 / M1): Escape clears the selection in
        // single mode (it already did in range mode, clearing the range).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'))
            ->handleKey('end')   // D21: last valid day of May (index 35)
            ->handleKey('home'); // D21: first valid day, May 1 (index 5)

        $dp = $dp->selectDate();
        $this->assertTrue($dp->isSelecting());

        $dp = $dp->handleKey('esc');
        $this->assertFalse($dp->isSelecting(),
            'Escape outside range mode must exit selection mode');
        $this->assertNull($dp->selectedDate(),
            'Escape outside range mode must clear the selected date');
    }

    public function testGoToPreviousYearClampsAtYearOne(): void
    {
        // 2026-10-23 (docs-fix-1023 E2 / M4): the calendar floor is January
        // 0001. Decrementing used to underflow into year 0, where the cursor
        // clamp dereferenced a false date and crashed.
        $dp = DatePicker::new(new \DateTimeImmutable('0002-06-15'));

        $dp = $dp->goToPreviousYear();
        $this->assertSame(1, $dp->viewYear());
        $this->assertSame(6, $dp->viewMonth());

        // Further presses stay pinned at the floor — no crash, no year 0.
        $dp = $dp->goToPreviousYear();
        $this->assertSame(1, $dp->viewYear());
        $dp = $dp->goToPreviousMonth();
        $this->assertSame(1, $dp->viewYear());
        $this->assertSame(1, $dp->viewMonth());

        // Queries and rendering all work at the floor.
        $this->assertIsString($dp->view());
        $this->assertIsString($dp->view(30, true));
        $selected = $dp->selectDate();
        $this->assertTrue($selected->isSelecting());
    }

    public function testSteppingPastDecember9999ClampsSymmetrically(): void
    {
        // dp-32 (sugar-datepicker-047): the ceiling mirrors the January-0001
        // floor. This replaces the docs-fix-1023-era pin that TOLERATED a
        // degraded year-10000 view (rendered "10000", null cursor date,
        // refusing Enter) — same safety, now enforced as a clamp by design.
        $dp = DatePicker::new(new \DateTimeImmutable('9999-12-31'))
            ->goToNextYear();

        $this->assertSame(9999, $dp->viewYear());
        $this->assertSame(12, $dp->viewMonth());
        $this->assertSame('9999-12-31', $dp->dateAtCursor()?->format('Y-m-d'));

        $more = $dp->goToNextMonth()->goToNextMonth()->goToNextYear();
        $this->assertSame([9999, 12], [$more->viewYear(), $more->viewMonth()], 'further stepping is a no-op at the ceiling');

        // Enter now succeeds at the edge instead of refusing an unresolvable date.
        $selected = $dp->selectDate();
        $this->assertTrue($selected->isSelecting());
        $this->assertNotNull($selected->selectedDate());

        // Construction past the ceiling is clamped on the way in (symmetric
        // with the zero-date constructor floor pin in DatePickerStyleTest).
        $ctor = DatePicker::new((new \DateTimeImmutable('9999-12-31'))->modify('+1 day'));
        $this->assertSame([9999, 12], [$ctor->viewYear(), $ctor->viewMonth()]);
        $this->assertIsString($ctor->view());
    }

    /**
     * @return array<string, array{0: 'min'|'max', 1: \DateTimeImmutable}>
     */
    public static function unrepresentableBoundProvider(): array
    {
        return [
            'zero-date max (MySQL empty row)' => ['max', \DateTimeImmutable::createFromFormat('Y-m-d', '0000-06-15')],
            'zero-date min' => ['min', \DateTimeImmutable::createFromFormat('Y-m-d', '0000-01-01')],
            'year-10000 max' => ['max', (new \DateTimeImmutable('9999-12-31'))->modify('+1 day')],
            'year-10026 min' => ['min', (new \DateTimeImmutable('2026-05-01'))->modify('+8000 years')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unrepresentableBoundProvider')]
    public function testUnrepresentableBoundsAreRefusedAtTheSetter(string $which, \DateTimeImmutable $bound): void
    {
        // dp-32 (sugar-datepicker-056): a bound outside the representable
        // window used to be ACCEPTED and then floor the view into an all-dim
        // dead month with zero signal. Fail-fast at the boundary instead.
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($bound->format('Y-m-d') . ' lies outside');

        $which === 'max' ? $picker->withMaxDate($bound) : $picker->withMinDate($bound);
    }

    public function testEdgeBoundsInsideTheWindowRemainAccepted(): void
    {
        // The window is inclusive: 0001-01-01 and 9999-12-31 are legal bounds.
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        $tight = $picker
            ->withMinDate(new \DateTimeImmutable('0001-01-01'))
            ->withMaxDate(new \DateTimeImmutable('9999-12-31'));

        $this->assertSame('0001-01-01', $tight->minDate()?->format('Y-m-d'));
        $this->assertSame('9999-12-31', $tight->maxDate()?->format('Y-m-d'));
    }

    public function testViewWidthIsClampedNotRejected(): void
    {
        // 2026-10-23 (docs-fix-1023 E2): the docblock used to claim "must be";
        // the real law is a silent clamp. D20: the clamp is on the TOTAL width
        // into [natural grid (21 without a week column), 63], so a request
        // below the floor still renders the full seven day columns.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        $this->assertSame($dp->view(15), $dp->view(5));
        $this->assertSame($dp->view(15), $dp->view(0));
        $this->assertSame($dp->view(63), $dp->view(100));
        $this->assertSame($dp->view(21), $dp->view());

        // dp-23 (sugar-datepicker-026): renderWidth() is the exact oracle of
        // the clamp — a host never has to shadow the arithmetic.
        $this->assertSame(21, DatePicker::MIN_TOTAL_WIDTH);
        $this->assertSame(63, DatePicker::MAX_TOTAL_WIDTH);
        $this->assertSame(34, $dp->renderWidth(30, true));
        $this->assertSame(63, $dp->renderWidth(64));
        $this->assertSame(21, $dp->renderWidth(0));
        $this->assertSame(25, $dp->renderWidth(21, true), 'the floor rises with the week column');

        // Argless accessor answers "what will my argless view() measure?"
        $configured = $dp->withWidth(40)->withShowWeekNumbers(true);
        $this->assertSame(44, $configured->renderWidth());

        // view() and renderWidth() agree column-for-column on the frame.
        foreach ([[null, null], [30, true]] as [$w, $week]) {
            $want = $dp->renderWidth($w, $week);
            foreach (explode("\n", $dp->view($w, $week)) as $row => $line) {
                $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $line);
                $this->assertSame($want, mb_strlen($plain), "view row $row must span renderWidth()");
            }
        }
    }

    public function testClampCursorAfterNavigatingToShorterMonth(): void
    {
        // D21: End jumps to the LAST VALID day, not the raw grid corner. May
        // 2026 (31 days, firstDow=5) → End = May 31 at index 35, never 41.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'))
            ->handleKey('end');  // cursorIndex = 35 (May 31, last valid day)

        $this->assertSame(35, $dp->cursorIndex());

        // Navigate to April 2026 (30 days, firstDow=3)
        // After navigation, clampCursor should pull index 35 back to 3 + 30 - 1 = 32
        $dp2 = $dp->goToPreviousMonth();

        // April 30 is at index 3 + 29 = 32 (firstDow=3, lastDayIndex=32)
        $this->assertSame(32, $dp2->cursorIndex(),
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
        $dp->view();
        $dp->view(21, true);
        $dp->view(31);
        $after = serialize($dp);

        $this->assertSame($before, $after,
            'View() must not mutate the instance (no internal render cache)');
    }

    public function testViewIsDeterministicAcrossCalls(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));

        $this->assertSame($dp->view(), $dp->view(),
            'Repeated View() calls on the same instance must be identical');
    }

    // -------------------------------------------------------------------------
    // ISO week column boundary (year rollover at the top of the grid).
    // -------------------------------------------------------------------------

    public function testViewWeekNumbersCrossYearBoundary(): void
    {
        // Jan 2026: firstDow=4 (Thu). The first displayed (Sunday-first) week
        // row begins Sun 2025-12-28. Its ISO representative is the row's
        // Wednesday (2025-12-31), which — because 2026-W01 spans Mon 2025-12-29
        // to Sun 2026-01-04 — is week 1 of 2026, not week 52 of 2025. The old
        // Sunday-based read under-labelled Sunday-start rows by one (D-MT10).
        // Regression: a malformed "+-4 days" offset used to shift these forward.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-01-15'))
            ->withToday(new \DateTimeImmutable('2026-01-15'));

        $plain = \preg_replace('/\x1b\[[0-9;]*m/', '', $dp->view(21, true));
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
        $this->assertStringStartsWith('1', \ltrim($firstWeekRow),
            'The Wed-based top row (2025-12-31) is 2026-W01, not the old Sunday read of 52');
        $this->assertStringContainsString(' 2 ', $plain,
            'The following row is 2026-W02 under the Wednesday-representative rule (MT10)');
    }

    // -------------------------------------------------------------------------
    // [DEDUP] dateAtCursor() delegates to Navigation::gridIndexToDate().
    // -------------------------------------------------------------------------

    public function testDateAtCursorDelegatesToNavigation(): void
    {
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
        // D9: the constructor seeds the cursor on May 1 (index 5). Pull it back
        // to grid index 0 so the sweep compares every index against Navigation.
        for ($k = 0; $k < 5; $k++) {
            $dp = $dp->moveCursorLeft();
        }
        for ($i = 0; $i < 41; $i++) {
            $expected = Navigation::gridIndexToDate($i, 5, 2026);
            $actual = $dp->dateAtCursor();

            $this->assertSame(
                $expected?->format('Y-m-d'),
                $actual?->format('Y-m-d'),
                "dateAtCursor() must match Navigation::gridIndexToDate() at index $i",
            );
            $dp = $dp->moveCursorRight();
        }
    }

    public function testClampCursorMarchToFebruary(): void
    {
        // D21: End jumps to the LAST VALID day of March (Mar 31, index 30),
        // not the raw grid corner 41.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-03-10'))
            ->withToday(new \DateTimeImmutable('2026-03-15'))
            ->handleKey('end');
        $this->assertSame(30, $dp->cursorIndex());

        // → Feb 2026 (28 days, firstDow=0): last valid index = 0 + 28 - 1 = 27.
        $feb = $dp->goToPreviousMonth();
        $this->assertSame(2, $feb->viewMonth());
        $this->assertSame(27, $feb->cursorIndex(),
            'Cursor must clamp to Feb 28 (index 27) when leaving a 31-day month');
        $this->assertSame('2026-02-28', $feb->dateAtCursor()?->format('Y-m-d'));
    }

    public function testCursorDateMappingsAgree(): void
    {
        // dateAtCursor() (uses viewMonth/viewYear) and Navigation::gridIndexToDate()
        // must agree for every grid index 0-41 in May 2026.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));
        $month = $dp->viewMonth();
        $year = $dp->viewYear();

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
        // D9: the constructor seeds the cursor on May 1 (index 5). Step back
        // five cells into the empty Sunday lead-in (index 0) so Enter has no
        // real day and must be a no-op.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withRangeMode(true);
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->moveCursorLeft();
        }
        $this->assertSame(0, $dp->cursorIndex(), 'cursor rests on the empty lead-in cell');

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

        // D9: the constructor already seeds the cursor on May 1 (index 5); the
        // old firstDow pre-walk is removed so the first Enter lands there.
        // First Enter: rangeStart = May 1
        $dp = $dp->handleKey('enter');

        // Move to May 5
        for ($i = 0; $i < 4; $i++) {
            $dp = $dp->moveCursorRight();
        }
        // Second Enter: rangeEnd = May 5
        $dp = $dp->handleKey('enter');

        $this->assertNotNull($dp->rangeStart());
        $this->assertNotNull($dp->rangeEnd());

        // Move to May 10
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->moveCursorRight();
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
        for ($i = 0; $i < 14; $i++) {
            $dp = $dp->moveCursorRight(); // D9 seed idx5 + 14 = idx19 = May 15
        }

        // Verify we're on May 15
        $this->assertSame('2026-05-15', $dp->dateAtCursor()?->format('Y-m-d'));

        // Select the date, then step OFF it so the cursor style does not
        // overlay the cell we are pinning (the 15 must show the
        // selectedToday style alone).
        $dp = $dp->selectDate()->moveCursorRight();

        $this->assertTrue($dp->isSelecting());
        $this->assertSame('2026-05-15', $dp->selectedDate()?->format('Y-m-d'));

        // Pin the BYTES of the selected∩today cell: bold yellow (1;33) in
        // cellbuffer's truecolour serialisation. An adjacent plain-today
        // cell from the same day WITHOUT the selection renders bold green
        // (1;32) instead — swapping the two default styles reddens both
        // needles, which is the mutation proof this pin exists for
        // (sugar-datepicker-018).
        $view = $dp->view();
        $this->assertStringContainsString("\x1b[0;38;2;205;205;0;1m15\x1b[0m", $view, 'selected∩today must carry the bold-yellow selectedToday style');

        $plainToday = $dp->clearDate()->view();
        $this->assertStringContainsString("\x1b[0;38;2;0;205;0;1m15\x1b[0m", $plainToday, 'today-only must carry the bold-green today style');
        $this->assertStringNotContainsString("\x1b[0;38;2;205;205;0;1m15\x1b[0m", $plainToday, 'with the selection gone, no yellow cell may remain');
    }

    public function testWithoutTodayDropsThePinnedReference(): void
    {
        // docs-fix-1023 E2 (XSet law): withToday had no counterpart unset.
        $pinned = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-12-25'));

        // With the pin, GoToToday lands on the injected date's month.
        $this->assertSame(12, $pinned->goToToday()->viewMonth());

        $unpinned = $pinned->withoutToday();
        $this->assertNotSame($pinned, $unpinned);

        // Without it, GoToToday follows the real wall clock — prove the
        // reference is gone by comparing against a bare picker's behaviour.
        $bare = DatePicker::new(new \DateTimeImmutable('2026-05-01'))->goToToday();
        $this->assertSame((int) $bare->viewMonth(), $unpinned->goToToday()->viewMonth());

        // Immutability: the pinned instance keeps its injected today.
        $this->assertSame(12, $pinned->goToToday()->viewMonth());
    }

    public function testSelectDateFallsBackToFirstOfMonthOnEmptyCell(): void
    {
        // The empty-cell fallback is now also a BOUND-checked candidate
        // (docs-fix-1023 E2): with no bound set it selects May 1 from index 0.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));
        for ($i = 0; $i < 5; $i++) {
            $dp = $dp->moveCursorLeft(); // D9 seed idx5 -> empty Sunday idx0
        }

        $this->assertNull($dp->dateAtCursor(), 'Index 0 is an empty lead-in cell');

        $selected = $dp->selectDate();
        $this->assertTrue($selected->isSelecting());
        $this->assertSame('2026-05-01', $selected->selectedDate()?->format('Y-m-d'));
    }

    public function testOutOfBoundsSelectedDayRendersFaint(): void
    {
        // dp-01: a selection that falls out of the window (the bound is
        // tightened after selecting) is never retroactively cleared — and its
        // cell must carry faint composed onto the selected style, so the stale
        // selection still reads disabled while keeping its colour cue.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withToday(new \DateTimeImmutable('2026-12-31')) // keep "today" clear of May
            ->moveToDate(new \DateTimeImmutable('2026-05-05'))
            ->selectDate()
            ->withMinDate(new \DateTimeImmutable('2026-05-10'));

        $this->assertNotNull($dp->selectedDate(), 'the selection survives the tightened bound');

        $build  = new \ReflectionMethod(DatePicker::class, 'buildCells');
        $cells  = $build->invoke($dp);

        // May 2026 starts Friday (firstDow 5): day 5 → grid idx 9, selected
        // and now out-of-bounds.
        $this->assertNotNull($cells[9][1], 'the stale selection carries a style');
        $this->assertNotSame(
            0,
            $cells[9][1]->attrs() & Style::ATTR_FAINT,
            'a stale out-of-bounds selection renders faint'
        );
        $this->assertNotNull($cells[9][1]->fg(), 'the stale selection keeps its selected colour');
    }

    public function testWithWidthDrivesArglessView(): void
    {
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))->withWidth(30);

        $this->assertSame($picker->view(30), $picker->view());
    }

    public function testExplicitWidthOverridesRenderState(): void
    {
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))->withWidth(30);
        $plain  = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        $this->assertSame($plain->view(), $picker->view(21));
    }

    public function testWidthStateIsClampedAtRenderNeverRejected(): void
    {
        $base = DatePicker::new(new \DateTimeImmutable('2026-05-01'))->view();

        // Absurd widths are stored as intent and clamped at draw time: below
        // the natural floor the grid renders at full 21-column width again.
        $this->assertSame($base, DatePicker::new(new \DateTimeImmutable('2026-05-01'))->withWidth(0)->view());
        $this->assertSame($base, DatePicker::new(new \DateTimeImmutable('2026-05-01'))->withWidth(-99)->view());
        // Above the ceiling, the 63-column cap applies exactly as before.
        $this->assertSame(
            DatePicker::new(new \DateTimeImmutable('2026-05-01'))->view(63),
            DatePicker::new(new \DateTimeImmutable('2026-05-01'))->withWidth(999_999)->view(),
        );
    }

    public function testShowWeekNumbersStateDrivesArglessView(): void
    {
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))->withShowWeekNumbers(true);
        $plain  = DatePicker::new(new \DateTimeImmutable('2026-05-01'));

        $this->assertSame($picker->view(21, true), $picker->view());
        $this->assertSame($plain->view(), $picker->view(21, false));
    }

    public function testRenderStateSurvivesNavigationWithers(): void
    {
        // Navigation clones preserve the configured render state: the June
        // grid still renders by state, matching its explicit twin.
        $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withWidth(30)
            ->withShowWeekNumbers(true);
        $next = $picker->goToNextMonth();

        $this->assertSame($next->view(30, true), $next->view());
    }

    // ------------------------------------------------------------------
    // dp-18: escape wire vocabulary + loud unknown keys
    // ------------------------------------------------------------------

    /** @return array<string, array{0: string}> */
    public static function escapeSpellingProvider(): array
    {
        return ["BC 'esc'" => ['esc'], "core 'escape'" => ['escape']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('escapeSpellingProvider')]
    public function testBothEscapeSpellingsAct(string $spelling): void
    {
        // Single mode: clears the selection...
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-10'))
            ->handleKey(DatePicker::KEY_ENTER);
        $this->assertNotNull($dp->selectedDate());
        $cleared = $dp->handleKey($spelling);
        $this->assertNull($cleared->selectedDate(), "'$spelling' must clear in single mode");

        // ...range mode: clears the pending range without touching selection.
        $range = DatePicker::new(new \DateTimeImmutable('2026-05-10'))
            ->withRangeMode(true)
            ->handleKey(DatePicker::KEY_ENTER);
        $this->assertNotNull($range->rangeStart());
        $cancelled = $range->handleKey($spelling);
        $this->assertNull($cancelled->rangeStart(), "'$spelling' must drop the armed range");
    }

    public function testConstantsPinBothEscapeSpellings(): void
    {
        $this->assertSame('esc', DatePicker::KEY_ESCAPE);
        $this->assertSame('escape', DatePicker::KEY_ESCAPE_ALT);
        // dp-10: the month-step wire twins of KeyType::PageUp/PageDown.
        $this->assertSame('pgup', DatePicker::KEY_PAGE_UP);
        $this->assertSame('pgdn', DatePicker::KEY_PAGE_DOWN);
    }

    public function testHandleKeyPageStepsMonthOnTheNonTeaSurface(): void
    {
        // dp-10: the Quick Start advertises handleKey() alone — a direct host
        // must be able to leave its month without the Model.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->handleKey(DatePicker::KEY_PAGE_DOWN);
        $this->assertSame(6, $dp->viewMonth());

        $dp = $dp->handleKey(DatePicker::KEY_PAGE_UP)->handleKey(DatePicker::KEY_PAGE_UP);
        $this->assertSame(4, $dp->viewMonth());
    }

    public function testHandleKeyPageStepsRespectBoundMonths(): void
    {
        // With a single-month window both page keys clamp — no wrap, no throw.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->withMinDate(new \DateTimeImmutable('2026-05-10'))
            ->withMaxDate(new \DateTimeImmutable('2026-05-20'));

        $this->assertSame(5, $dp->handleKey(DatePicker::KEY_PAGE_DOWN)->viewMonth());
        $this->assertSame(5, $dp->handleKey(DatePicker::KEY_PAGE_UP)->viewMonth());
        // The programmatic year legs honour the same clamp (no key exists for
        // them — the single-cursor divergence disclosed in the README).
        $this->assertSame(2026, $dp->goToPreviousYear()->viewYear());
        $this->assertSame(2026, $dp->goToNextYear()->viewYear());
    }

    public function testUnknownKeyIsLoudInErrorLogButNeverThrows(): void
    {
        $log = (function (string $key): string {
            $file = \tempnam(\sys_get_temp_dir(), 'dpkey');
            $prev = \ini_set('error_log', $file);
            try {
                $dp = DatePicker::new(new \DateTimeImmutable('2026-05-10'));
                $same = $dp->handleKey($key);
                $this->assertSame($dp, $same, 'an unknown key must not change state');
                $this->assertSame($dp, $same->handleKey(''));

                return (string) \file_get_contents($file);
            } finally {
                if ($prev === false) {
                    \ini_restore('error_log');
                } else {
                    \ini_set('error_log', $prev);
                }
                @\unlink($file);
            }
        })('entr');

        $this->assertStringContainsString('datepicker: unknown key entr', $log);
    }

    // ------------------------------------------------------------------
    // dp-15: header template key
    // ------------------------------------------------------------------

    public function testEnglishHeaderBytesUnchangedByTemplate(): void
    {
        // en value '{month} {year}' must render byte-identically to the
        // pre-template composition (zero golden moves across the suite).
        $header = \SugarCraft\Core\Util\Ansi::strip(
            \explode("\n", DatePicker::new(new \DateTimeImmutable('2026-05-01'))->view(21))[0]
        );
        $this->assertStringStartsWith('May 2026', $header);
    }

    public function testYearFirstLocaleReordersHeaderWithoutCodeChange(): void
    {
        $prevLocale = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::overrideNamespace('datepicker', __DIR__ . '/fixtures/lang');
        \SugarCraft\Core\I18n\T::setLocale('reorder');

        try {
            $header = \SugarCraft\Core\Util\Ansi::strip(
                \explode("\n", DatePicker::new(new \DateTimeImmutable('2026-05-01'))->view(21))[0]
            );
            $this->assertStringStartsWith('2026-May', \rtrim($header),
                'the {year}-{month} fixture template must drive render order');
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($prevLocale);
            \SugarCraft\Core\I18n\T::overrideNamespace('datepicker', __DIR__ . '/../lang');
        }
    }

    public function testMoveToDateOutsideViewedMonthResetsCursorToFirstSelectableDay(): void
    {
        // dp-25: the docblock used to promise "cursor keeps its clamped
        // position" for out-of-month jumps; the implementation (shared with
        // ctor/setTime via cursorForDate -> firstValidIndex) RE-ARMS the
        // cursor on the viewed month's first selectable day. Pin the law.
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->moveCursorRight()
            ->moveCursorRight(); // cursor now idx7 (May 3), well off day 1
        $this->assertSame(7, $dp->cursorIndex());

        $jumped = $dp->moveToDate(new \DateTimeImmutable('2026-07-20'));

        $this->assertSame(5, $jumped->viewMonth(), 'the view must NOT follow an out-of-month date');
        $this->assertSame(2026, $jumped->viewYear());
        $this->assertSame(5, $jumped->cursorIndex(), 'an out-of-month jump resets to the first selectable day, never the stale idx7');
        $this->assertNull($jumped->selectedDate(), 'moveToDate must not touch the selection');
    }

    public function testMoveToDateOutOfBoundsJumpRespectsFirstSelectableDay(): void
    {
        // The reset consults min/max: with May 10 as the floor, the cursor
        // lands on May 10 (idx 14), not May 1 (idx 5).
        $dp = DatePicker::new(new \DateTimeImmutable('2026-05-01'))
            ->withMinDate(new \DateTimeImmutable('2026-05-10'));

        $jumped = $dp->moveToDate(new \DateTimeImmutable('2026-07-20'));

        $this->assertSame(5, $jumped->viewMonth());
        $this->assertSame(14, $jumped->cursorIndex(), 'reset lands on the first SELECTABLE day May 10 (dp-11 clamp law)');
    }
}
