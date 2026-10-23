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

        $dp = $dp->GoToPreviousMonth(); // May → April (inside the window)
        $this->assertSame(4, $dp->ViewMonth());

        $dp = $dp->GoToPreviousMonth(); // April → March (the floor month)
        $this->assertSame(3, $dp->ViewMonth());
        $this->assertSame(2026, $dp->ViewYear());

        // Further stepping stays at the floor month.
        $dp = $dp->GoToPreviousMonth();
        $this->assertSame(3, $dp->ViewMonth());

        // Year steps clamp too.
        $dp = $dp->GoToPreviousYear();
        $this->assertSame(3, $dp->ViewMonth());
        $this->assertSame(2026, $dp->ViewYear());
    }

    public function testNavigationIsClampedAtMaxMonth(): void
    {
        $dp = self::may()->withMaxDate(new \DateTimeImmutable('2026-07-20'));

        $dp = $dp->GoToNextMonth(); // May → June (inside the window)
        $this->assertSame(6, $dp->ViewMonth());
        $dp = $dp->GoToNextMonth(); // June → July (the ceiling month)
        $this->assertSame(7, $dp->ViewMonth());
        $dp = $dp->GoToNextYear();
        $this->assertSame(7, $dp->ViewMonth());
        $this->assertSame(2026, $dp->ViewYear());
    }

    public function testSelectingDateBeforeMinIsRefused(): void
    {
        // View window allows March (whole month visible); cells before the
        // bound itself are unselectable.
        $dp = self::may()
            ->withMinDate(new \DateTimeImmutable('2026-03-10'))
            ->GoToPreviousMonth()   // May → April
            ->GoToPreviousMonth();  // April → March 2026
        $this->assertSame(3, $dp->ViewMonth());

        // Home puts the cursor on grid cell 0 (empty lead-in) → Enter falls
        // back to first-of-month (Mar 1), which is BEFORE the bound →
        // refusal, receiver unchanged.
        $dp = $dp->handleKey('home');
        $refused = $dp->handleKey('enter');
        $this->assertFalse($refused->IsSelecting());
        $this->assertNull($refused->SelectedDate());

        // Mar 10 sits at grid index 9 (Mar 2026 starts Sunday, firstDow=0).
        for ($i = 0; $i < 9; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $this->assertSame('2026-03-10', $dp->dateAtCursor()?->format('Y-m-d'));

        $ok = $dp->handleKey('enter');
        $this->assertTrue($ok->IsSelecting());
        $this->assertSame('2026-03-10', $ok->SelectedDate()?->format('Y-m-d'));
    }

    public function testSelectingDateAfterMaxIsRefused(): void
    {
        $dp = self::may()
            ->withMaxDate(new \DateTimeImmutable('2026-05-20'));

        // May 2026 starts Friday (firstDow=5): May 20 is grid index 24,
        // May 21 is index 25.
        $dp = $dp->handleKey('home');
        for ($i = 0; $i < 25; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $this->assertSame('2026-05-21', $dp->dateAtCursor()?->format('Y-m-d'));
        $this->assertFalse($dp->handleKey('enter')->IsSelecting());

        $dp = $dp->MoveCursorLeft();
        $this->assertSame('2026-05-20', $dp->dateAtCursor()?->format('Y-m-d'));
        $ok = $dp->handleKey('enter');
        $this->assertTrue($ok->IsSelecting());
        $this->assertSame('2026-05-20', $ok->SelectedDate()?->format('Y-m-d'));
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
        $wayBack = $free->GoToPreviousMonth();
        $this->assertSame(4, $wayBack->ViewMonth());
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
        $this->assertSame(7, $dp->ViewMonth());
        $this->assertSame(2026, $dp->ViewYear());

        // A max of the previous month drags it back.
        $dp = self::may()->withMaxDate(new \DateTimeImmutable('2026-04-30'));
        $this->assertSame(4, $dp->ViewMonth());
    }

    public function testSetTimeAndGoToTodayRespectWindow(): void
    {
        $dp = self::may()->withMinDate(new \DateTimeImmutable('2026-08-01'));

        $moved = $dp->SetTime(new \DateTimeImmutable('2026-01-05'));
        $this->assertSame(8, $moved->ViewMonth());
        $this->assertSame(2026, $moved->ViewYear());

        // GoToToday: today is May 15 but the window starts in August.
        $t = $dp->GoToToday();
        $this->assertSame(8, $t->ViewMonth());
    }

    public function testRangeEndpointsRespectBounds(): void
    {
        // Window: May 10-20. The grid window shows the whole May month;
        // endpoints outside the date window are refused.
        $dp = self::may()
            ->withMinDate(new \DateTimeImmutable('2026-05-10'))
            ->withMaxDate(new \DateTimeImmutable('2026-05-20'))
            ->withRangeMode(true)
            ->handleKey('home'); // cursor 0 → empty cell → null date

        $dp = $dp->handleKey('enter');
        $this->assertNull($dp->rangeStart(),
            'Empty cell cannot anchor a range even in range mode');

        // May 2026 starts Friday (firstDow=5): May 9 is grid index 13.
        for ($i = 0; $i < 13; $i++) {
            $dp = $dp->MoveCursorRight();
        }
        $this->assertSame('2026-05-09', $dp->dateAtCursor()?->format('Y-m-d'));
        $this->assertNull($dp->handleKey('enter')->rangeStart(),
            'Out-of-bound date cannot anchor a range');

        $dp = $dp->MoveCursorRight();
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
        $dp = self::may()->handleKey('home');
        $dp = $dp->handleKey('enter'); // selects May 1 (empty cell fallback)
        $this->assertSame('2026-05-01', $dp->SelectedDate()?->format('Y-m-d'));

        $tightened = $dp->withMinDate(new \DateTimeImmutable('2026-05-10'));
        $this->assertSame('2026-05-01', $tightened->SelectedDate()?->format('Y-m-d'));
    }
}
