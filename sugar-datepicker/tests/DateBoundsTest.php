<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\DatePicker\DatePicker;

/**
 * min/max selection-bound coverage (docs-fix-1023 E2 / M2).
 *
 * The lib's own README-era promise and the root docs feature table both
 * advertised date constraints; the API is implemented here: withMinDate/
 * withMaxDate clamp navigation to the containing months, refuse selection
 * outside the window, and re-clamp the current view when set.
 */
final class DateBoundsTest extends TestCase
{
    private static function may(): DatePicker
    {
        return DatePicker::new(new \DateTimeImmutable('2026-05-15'))
            ->withToday(new \DateTimeImmutable('2026-05-15'));
    }

    public function testNavigationIsClampedAtMinMonth(): void
    {
        $dp = self::may()->withMinDate(new \DateTimeImmutable('2026-03-10'));

        $dp = $dp->goToPreviousMonth(); // May → April (inside the window)
        $this->assertSame(4, $dp->viewMonth());

        $dp = $dp->goToPreviousMonth(); // April → March (the floor month)
        $this->assertSame(3, $dp->viewMonth());
        $this->assertSame(2026, $dp->viewYear());

        // Further stepping stays at the floor month.
        $dp = $dp->goToPreviousMonth();
        $this->assertSame(3, $dp->viewMonth());

        // Year steps clamp too.
        $dp = $dp->goToPreviousYear();
        $this->assertSame(3, $dp->viewMonth());
        $this->assertSame(2026, $dp->viewYear());
    }

    public function testNavigationIsClampedAtMaxMonth(): void
    {
        $dp = self::may()->withMaxDate(new \DateTimeImmutable('2026-07-20'));

        $dp = $dp->goToNextMonth(); // May → June (inside the window)
        $this->assertSame(6, $dp->viewMonth());
        $dp = $dp->goToNextMonth(); // June → July (the ceiling month)
        $this->assertSame(7, $dp->viewMonth());
        $dp = $dp->goToNextYear();
        $this->assertSame(7, $dp->viewMonth());
        $this->assertSame(2026, $dp->viewYear());
    }

    public function testSelectingDateBeforeMinIsRefused(): void
    {
        // View window allows March (whole month visible); cells before the
        // bound itself are unselectable.
        $dp = self::may()
            ->withMinDate(new \DateTimeImmutable('2026-03-10'))
            ->goToPreviousMonth()   // May → April
            ->goToPreviousMonth();  // April → March 2026
        $this->assertSame(3, $dp->viewMonth());

        // dp-11: Home now CLAMPS to the first selectable day — Mar 10, never
        // the dimmed Mar 1 (grid index 9; Mar 2026 starts Sunday, firstDow=0).
        $dp = $dp->handleKey('home');
        $this->assertSame(9, $dp->cursorIndex());
        $this->assertSame('2026-03-10', $dp->dateAtCursor()?->format('Y-m-d'));

        // The arrows deliberately may rest on dimmed cells: one left parks on
        // Mar 9, before the bound → selection refusal, receiver unchanged.
        $refused = $dp->handleKey('left')->handleKey('enter');
        $this->assertFalse($refused->isSelecting());
        $this->assertNull($refused->selectedDate());

        // And acceptance still lands exactly on the bound (Home parked us there).
        $ok = $dp->handleKey('enter');
        $this->assertTrue($ok->isSelecting());
        $this->assertSame('2026-03-10', $ok->selectedDate()?->format('Y-m-d'));
    }

    public function testSelectingDateAfterMaxIsRefused(): void
    {
        $dp = self::may()
            ->withMaxDate(new \DateTimeImmutable('2026-05-20'));

        // May 2026 starts Friday (firstDow=5): May 20 is grid index 24,
        // May 21 is index 25. Home lands on May 1 (index 5); 20 rights reach
        // the first out-of-bound day.
        $dp = $dp->handleKey('home');
        for ($i = 0; $i < 20; $i++) {
            $dp = $dp->moveCursorRight();
        }
        $this->assertSame('2026-05-21', $dp->dateAtCursor()?->format('Y-m-d'));
        $this->assertFalse($dp->handleKey('enter')->isSelecting());

        $dp = $dp->moveCursorLeft();
        $this->assertSame('2026-05-20', $dp->dateAtCursor()?->format('Y-m-d'));
        $ok = $dp->handleKey('enter');
        $this->assertTrue($ok->isSelecting());
        $this->assertSame('2026-05-20', $ok->selectedDate()?->format('Y-m-d'));
    }

    public function testInBoundsReflectsWindow(): void
    {
        $dp = self::may()
            ->withMinDate(new \DateTimeImmutable('2026-05-10'))
            ->withMaxDate(new \DateTimeImmutable('2026-05-20'));

        $this->assertTrue($dp->inBounds(new \DateTimeImmutable('2026-05-10')));
        $this->assertTrue($dp->inBounds(new \DateTimeImmutable('2026-05-15')));
        $this->assertTrue($dp->inBounds(new \DateTimeImmutable('2026-05-20')));
        $this->assertFalse($dp->inBounds(new \DateTimeImmutable('2026-05-09')));
        $this->assertFalse($dp->inBounds(new \DateTimeImmutable('2026-05-21')));

        // Time-of-day on the probe does not matter (midnight-normalized),
        // nor does the bound's own time-of-day.
        $this->assertTrue($dp->inBounds(new \DateTimeImmutable('2026-05-20 23:59:59')));
        $this->assertFalse($dp->inBounds(new \DateTimeImmutable('2026-05-09 23:59:59')));
    }

    public function testUnsettingBoundsRestoresFreeNavigation(): void
    {
        $dp = self::may()
            ->withMinDate(new \DateTimeImmutable('2026-03-10'))
            ->withMaxDate(new \DateTimeImmutable('2026-07-20'));

        $this->assertNotNull($dp->minDate());
        $this->assertNotNull($dp->maxDate());

        $free = $dp->withoutMinDate()->withoutMaxDate();
        $this->assertNull($free->minDate());
        $this->assertNull($free->maxDate());

        // Unbounded again: the calendar floor (not the removed bound) governs.
        $wayBack = $free->goToPreviousMonth();
        $this->assertSame(4, $wayBack->viewMonth());
    }

    public function testMinAfterMaxThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::may()
            ->withMaxDate(new \DateTimeImmutable('2026-07-20'))
            ->withMinDate(new \DateTimeImmutable('2026-08-01'));
    }

    public function testMaxBeforeMinThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::may()
            ->withMinDate(new \DateTimeImmutable('2026-03-10'))
            ->withMaxDate(new \DateTimeImmutable('2026-02-01'));
    }

    public function testSettingBoundPullsOutOfWindowViewBack(): void
    {
        // Currently viewing May; a min of July must drag the view forward.
        $dp = self::may()->withMinDate(new \DateTimeImmutable('2026-07-01'));
        $this->assertSame(7, $dp->viewMonth());
        $this->assertSame(2026, $dp->viewYear());

        // A max of the previous month drags it back.
        $dp = self::may()->withMaxDate(new \DateTimeImmutable('2026-04-30'));
        $this->assertSame(4, $dp->viewMonth());
    }

    public function testSetTimeAndGoToTodayRespectWindow(): void
    {
        $dp = self::may()->withMinDate(new \DateTimeImmutable('2026-08-01'));

        $moved = $dp->setTime(new \DateTimeImmutable('2026-01-05'));
        $this->assertSame(8, $moved->viewMonth());
        $this->assertSame(2026, $moved->viewYear());

        // GoToToday: today is May 15 but the window starts in August.
        $t = $dp->goToToday();
        $this->assertSame(8, $t->viewMonth());
    }

    public function testRangeEndpointsRespectBounds(): void
    {
        // Window: May 10-20. The grid window shows the whole May month;
        // endpoints outside the date window are refused.
        $dp = self::may()
            ->withMinDate(new \DateTimeImmutable('2026-05-10'))
            ->withMaxDate(new \DateTimeImmutable('2026-05-20'))
            ->withRangeMode(true)
            ->handleKey('home'); // dp-11: clamps to May 10 — the bound itself

        // Parked ON the bound, Enter would arm the range. The refusal leg
        // reaches an out-of-bound day the way arrows allow it: one left onto
        // the dimmed May 9 (grid index 13, May 2026 starts Friday firstDow=5).
        $dp = $dp->handleKey('left');
        $this->assertSame('2026-05-09', $dp->dateAtCursor()?->format('Y-m-d'));

        $dp = $dp->handleKey('enter');
        $this->assertNull($dp->rangeStart(),
            'Out-of-bound day cannot anchor a range even in range mode');

        $dp = $dp->moveCursorRight();
        $this->assertSame('2026-05-10', $dp->dateAtCursor()?->format('Y-m-d'));
        $dp = $dp->handleKey('enter');
        $this->assertNotNull($dp->rangeStart());
        $this->assertSame('2026-05-10', $dp->rangeStart()?->format('Y-m-d'));
    }

    public function testBoundsAreImmutableAndDefaultUnbounded(): void
    {
        $dp = self::may();
        $this->assertNull($dp->minDate());
        $this->assertNull($dp->maxDate());

        $withMin = $dp->withMinDate(new \DateTimeImmutable('2026-01-01'));
        $this->assertNotSame($dp, $withMin);
        $this->assertNull($dp->minDate(), 'Receiver must be untouched (immutability law)');
    }

    public function testBoundsDoNotRetroactivelyClearSelection(): void
    {
        // Documented law: existing selections survive a tightened bound —
        // bounds govern FUTURE navigation/selection only.
        $dp = self::may()->handleKey('home'); // lands on May 1 (first valid day)
        $dp = $dp->handleKey('enter'); // selects May 1
        $this->assertSame('2026-05-01', $dp->selectedDate()?->format('Y-m-d'));

        $tightened = $dp->withMinDate(new \DateTimeImmutable('2026-05-10'));
        $this->assertSame('2026-05-01', $tightened->selectedDate()?->format('Y-m-d'));
    }
}
