<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Select;

use SugarCraft\Dash\Components\Select\DatePicker;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class DatePickerTest extends TestCase
{
    // Helper to strip ANSI codes for string comparison
    private function stripAnsi(string $output): string
    {
        return preg_replace('/\x1b\[[0-9;]*m/', '', $output);
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testDatePickerImplementsSizer(): void
    {
        $picker = DatePicker::new(2024, 1);
        $this->assertInstanceOf(Sizer::class, $picker);
    }

    public function testDatePickerImplementsItem(): void
    {
        $picker = DatePicker::new(2024, 1);
        $this->assertInstanceOf(Item::class, $picker);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $picker = DatePicker::new(2024, 1);
        $rendered = $picker->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsMonthName(): void
    {
        $picker = DatePicker::new(2024, 6);
        $rendered = $picker->render();

        $this->assertStringContainsString('June', $rendered);
        $this->assertStringContainsString('2024', $rendered);
    }

    public function testRenderContainsDayHeaders(): void
    {
        $picker = DatePicker::new(2024, 1);
        $rendered = $picker->render();

        $this->assertStringContainsString('Su', $rendered);
        $this->assertStringContainsString('Mo', $rendered);
        $this->assertStringContainsString('Tu', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Month rendering
    // ═══════════════════════════════════════════════════════════════

    public function testJanuaryHas31Days(): void
    {
        $picker = DatePicker::new(2024, 1);
        $rendered = $picker->render();

        // Should contain day 31
        $this->assertStringContainsString('31', $rendered);
    }

    public function testFebruaryNonLeapYear(): void
    {
        $picker = DatePicker::new(2023, 2);
        $rendered = $picker->render();

        // Should contain day 28 but not 29
        $this->assertStringContainsString('28', $rendered);
        $this->assertStringNotContainsString('29', $rendered);
    }

    public function testFebruaryLeapYear(): void
    {
        $picker = DatePicker::new(2024, 2);
        $rendered = $picker->render();

        // 2024 is a leap year
        $this->assertStringContainsString('29', $rendered);
    }

    public function testAprilHas30Days(): void
    {
        $picker = DatePicker::new(2024, 4);
        $rendered = $picker->render();

        $this->assertStringContainsString('30', $rendered);
        $this->assertStringNotContainsString('31', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedDayIsHighlighted(): void
    {
        $picker = DatePicker::new(2024, 1, 15);
        $rendered = $picker->render();

        // Selected day should be wrapped in brackets
        $this->assertStringContainsString('[15]', $rendered);
    }

    public function testSwitchingSelectedDay(): void
    {
        $picker = DatePicker::new(2024, 1, 10);
        $picker2 = $picker->withSelectedDay(20);

        $rendered = $picker2->render();
        $this->assertStringContainsString('[20]', $rendered);
    }

    public function testWithDateChangesMonth(): void
    {
        $picker = DatePicker::new(2024, 1)->withDate(2024, 3);
        $rendered = $picker->render();

        $this->assertStringContainsString('March', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Navigation
    // ═══════════════════════════════════════════════════════════════

    public function testPreviousMonth(): void
    {
        $picker = DatePicker::new(2024, 3)->withPreviousMonth();
        $rendered = $picker->render();

        $this->assertStringContainsString('February', $rendered);
        $this->assertStringContainsString('2024', $rendered);
    }

    public function testNextMonth(): void
    {
        $picker = DatePicker::new(2024, 5)->withNextMonth();
        $rendered = $picker->render();

        $this->assertStringContainsString('June', $rendered);
    }

    public function testPreviousMonthFromJanuary(): void
    {
        $picker = DatePicker::new(2024, 1)->withPreviousMonth();
        $rendered = $picker->render();

        $this->assertStringContainsString('December', $rendered);
        $this->assertStringContainsString('2023', $rendered);
    }

    public function testNextMonthFromDecember(): void
    {
        $picker = DatePicker::new(2024, 12)->withNextMonth();
        $rendered = $picker->render();

        $this->assertStringContainsString('January', $rendered);
        $this->assertStringContainsString('2025', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Start day of week
    // ═══════════════════════════════════════════════════════════════

    public function testStartDayOfWeekMonday(): void
    {
        $picker = DatePicker::new(2024, 1)->withStartDayOfWeek(DatePicker::DAYS_MONDAY);
        $rendered = $picker->render();

        // Should show Mo first (second line has day names)
        $lines = explode("\n", $this->stripAnsi($rendered));
        $firstContent = trim($lines[1] ?? '');
        $this->assertStringStartsWith('Mo', $firstContent);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testHeaderColorAddsAnsiCodes(): void
    {
        $picker = DatePicker::new(2024, 1)->withHeaderColor(Color::ansi(9));
        $rendered = $picker->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSelectedColorAddsAnsiCodes(): void
    {
        $picker = DatePicker::new(2024, 1, 15)->withSelectedColor(Color::ansi(9));
        $rendered = $picker->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithDateReturnsNewInstance(): void
    {
        $original = DatePicker::new(2024, 1);
        $updated = $original->withDate(2025, 6);

        $this->assertNotSame($original, $updated);
    }

    public function testWithSelectedDayReturnsNewInstance(): void
    {
        $original = DatePicker::new(2024, 1);
        $updated = $original->withSelectedDay(15);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithSelectedDay(): void
    {
        $original = DatePicker::new(2024, 1, 10);
        $original->withSelectedDay(20);

        $rendered = $original->render();
        $this->assertStringContainsString('[10]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = DatePicker::new(2024, 1);
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $picker = DatePicker::new(2024, 1);
        [$w, $h] = $picker->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testDaySelectionClampedToValidRange(): void
    {
        $picker = DatePicker::new(2024, 2, 30); // Feb doesn't have 30 days
        $rendered = $picker->render();

        // Should not crash and should have valid content
        $this->assertNotSame('', $rendered);
    }

    public function testInvalidMonthClamped(): void
    {
        $picker = DatePicker::new(2024, 1)->withDate(2024, 15);
        $rendered = $picker->render();

        // Should still render
        $this->assertNotSame('', $rendered);
    }

    public function testTodayFactory(): void
    {
        $picker = DatePicker::today();
        $rendered = $picker->render();

        $this->assertNotSame('', $rendered);
    }
}
