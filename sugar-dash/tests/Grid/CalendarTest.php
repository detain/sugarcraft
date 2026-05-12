<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Calendar;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class CalendarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testCalendarImplementsSizer(): void
    {
        $calendar = Calendar::new();
        $this->assertInstanceOf(Sizer::class, $calendar);
    }

    public function testCalendarImplementsItem(): void
    {
        $calendar = Calendar::new();
        $this->assertInstanceOf(Item::class, $calendar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $calendar = Calendar::new();
        $rendered = $calendar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsMonthName(): void
    {
        $calendar = Calendar::new();
        $rendered = $calendar->render();

        // Should contain a month name
        $monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December'];
        $found = false;
        foreach ($monthNames as $month) {
            if (str_contains($rendered, $month)) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found);
    }

    public function testRenderContainsDayNames(): void
    {
        $calendar = Calendar::new();
        $rendered = $calendar->render();

        // Should contain day name abbreviations
        $this->assertStringContainsString('Su', $rendered);
        $this->assertStringContainsString('Mo', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Date handling
    // ═══════════════════════════════════════════════════════════════

    public function testForDateCreatesCorrectCalendar(): void
    {
        $calendar = Calendar::forDate(2024, 6);
        $rendered = $calendar->render();

        // Should contain June and 2024
        $this->assertStringContainsString('June', $rendered);
        $this->assertStringContainsString('2024', $rendered);
    }

    public function testGetDaysInMonth(): void
    {
        $calendar31 = Calendar::forDate(2024, 1);
        $calendar30 = Calendar::forDate(2024, 4);
        $calendarFeb = Calendar::forDate(2024, 2);

        $this->assertSame(31, $calendar31->getDaysInMonth());
        $this->assertSame(30, $calendar30->getDaysInMonth());
        $this->assertSame(29, $calendarFeb->getDaysInMonth()); // 2024 is leap year
    }

    public function testGetDaysInMonthFebruaryNonLeap(): void
    {
        $calendar = Calendar::forDate(2023, 2);
        $this->assertSame(28, $calendar->getDaysInMonth());
    }

    // ═══════════════════════════════════════════════════════════════
    // Week start day
    // ═══════════════════════════════════════════════════════════════

    public function testStartOnMonday(): void
    {
        $calendarMon = Calendar::new()->withStartOnMonday(true);
        $rendered = $calendarMon->render();

        // Should start with Mo instead of Su
        $this->assertStringStartsWith('Mo', trim($rendered));
    }

    public function testStartOnSunday(): void
    {
        $calendarSun = Calendar::new()->withStartOnMonday(false);
        $rendered = $calendarSun->render();

        // Should start with Su
        $firstContent = trim(explode("\n", $rendered)[1] ?? '');
        $this->assertStringStartsWith('Su', $firstContent);
    }

    // ═══════════════════════════════════════════════════════════════
    // Highlight day
    // ═══════════════════════════════════════════════════════════════

    public function testWithHighlight(): void
    {
        $calendar = Calendar::forDate(2024, 5)->withHighlight(15);
        $rendered = $calendar->render();

        // Should contain "15" somewhere
        $this->assertStringContainsString('15', $rendered);
    }

    public function testIsToday(): void
    {
        $calendar = Calendar::new();
        $today = (int) date('j');

        $this->assertTrue($calendar->isToday($today));
        $this->assertFalse($calendar->isToday(1));
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testHeaderColorAddsAnsiCodes(): void
    {
        $calendar = Calendar::new()->withHeaderColor(Color::ansi(9));
        $rendered = $calendar->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNullHeaderColorNoAnsi(): void
    {
        $calendar = new Calendar(2024, 5, 0, false, null, null, null);
        $rendered = $calendar->render();

        // Should not contain ANSI codes in header area
        $lines = explode("\n", $rendered);
        $this->assertNotMatchesRegularExpression('/\x1b\[/', $lines[0] ?? '');
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $calendar = Calendar::new();
        [$w, $h] = $calendar->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeHeightAtLeastSix(): void
    {
        $calendar = Calendar::new();
        [, $h] = $calendar->getInnerSize();

        // Calendar should be at least 6 rows (header + days + 4 week rows)
        $this->assertGreaterThanOrEqual(6, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithHighlightReturnsNewInstance(): void
    {
        $original = Calendar::new();
        $updated = $original->withHighlight(15);

        $this->assertNotSame($original, $updated);
    }

    public function testWithStartOnMondayReturnsNewInstance(): void
    {
        $original = Calendar::new();
        $updated = $original->withStartOnMonday(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeaderColorReturnsNewInstance(): void
    {
        $original = Calendar::new();
        $updated = $original->withHeaderColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTodayColorReturnsNewInstance(): void
    {
        $original = Calendar::new();
        $updated = $original->withTodayColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Calendar::new();
        $resized = $original->setSize(30, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static factories
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesInstance(): void
    {
        $calendar = Calendar::new();
        $this->assertInstanceOf(Calendar::class, $calendar);
    }

    public function testForDateCreatesInstance(): void
    {
        $calendar = Calendar::forDate(2024, 1);
        $this->assertInstanceOf(Calendar::class, $calendar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMonthBounds(): void
    {
        // Month should be clamped to 1-12
        $calendar = Calendar::forDate(2024, 0);
        $this->assertGreaterThanOrEqual(1, $calendar->getDaysInMonth());

        $calendar2 = Calendar::forDate(2024, 13);
        $this->assertGreaterThanOrEqual(1, $calendar2->getDaysInMonth());
    }

    public function testFebruaryLeapYear(): void
    {
        // 2024 is a leap year
        $calendar = Calendar::forDate(2024, 2);
        $this->assertSame(29, $calendar->getDaysInMonth());
    }

    public function testMultiLineOutput(): void
    {
        $calendar = Calendar::forDate(2024, 5);
        $rendered = $calendar->render();
        $lines = explode("\n", $rendered);

        // Should have multiple lines
        $this->assertGreaterThan(2, count($lines));
    }
}
