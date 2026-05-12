<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Clock;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testClockImplementsSizer(): void
    {
        $clock = Clock::new();
        $this->assertInstanceOf(Sizer::class, $clock);
    }

    public function testClockImplementsItem(): void
    {
        $clock = Clock::new();
        $this->assertInstanceOf(Item::class, $clock);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $clock = Clock::new();
        $rendered = $clock->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTimeFormat(): void
    {
        $clock = Clock::new();
        $rendered = $clock->render();

        // Should contain colons for time format
        $this->assertStringContainsString(':', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Time format
    // ═══════════════════════════════════════════════════════════════

    public function testTwelveHourFormat(): void
    {
        $clock = Clock::new()->with24Hour(false);
        $rendered = $clock->render();

        // 12-hour format should have AM/PM
        $this->assertMatchesRegularExpression('/(AM|PM)/', $rendered);
    }

    public function testTwentyFourHourFormat(): void
    {
        $clock = Clock::new()->with24Hour(true);
        $rendered = $clock->render();

        // 24-hour format should NOT have AM/PM
        $this->assertStringNotContainsString('AM', $rendered);
        $this->assertStringNotContainsString('PM', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Seconds display
    // ═══════════════════════════════════════════════════════════════

    public function testShowSecondsByDefault(): void
    {
        $clock = Clock::new();
        $rendered = $clock->render();

        // Default shows seconds (has two colons)
        $colonCount = substr_count($rendered, ':');
        $this->assertGreaterThanOrEqual(2, $colonCount);
    }

    public function testHideSeconds(): void
    {
        $clock = Clock::new()->withSeconds(false);
        $rendered = $clock->render();

        // Should only have one colon (HH:MM)
        $colonCount = substr_count($rendered, ':');
        $this->assertSame(1, $colonCount);
    }

    // ═══════════════════════════════════════════════════════════════
    // Date display
    // ═══════════════════════════════════════════════════════════════

    public function testShowDate(): void
    {
        $clock = Clock::new()->withDate(true);
        $rendered = $clock->render();

        // Should contain month abbreviation
        $this->assertMatchesRegularExpression('/[A-Z][a-z]{2},?/', $rendered);
    }

    public function testHideDateByDefault(): void
    {
        $clock = Clock::new()->withDate(false);
        $rendered = $clock->render();

        // Should not contain day names
        $this->assertStringNotContainsString('Mon', $rendered);
        $this->assertStringNotContainsString('Sun', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $clock = Clock::new()->withColor(Color::ansi(9));
        $rendered = $clock->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $clock = Clock::new()->withColor(Color::ansi(9));
        $rendered = $clock->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testNullColorNoAnsi(): void
    {
        $clock = new Clock(false, true, false, null);
        $rendered = $clock->render();

        // Should not contain ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $clock = Clock::new();
        [$w, $h] = $clock->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithDate(): void
    {
        $clock = Clock::new()->withDate(true);
        [$w, $h] = $clock->getInnerSize();

        // With date should be wider
        $this->assertGreaterThan(10, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWith24HourReturnsNewInstance(): void
    {
        $original = Clock::new();
        $updated = $original->with24Hour(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithSecondsReturnsNewInstance(): void
    {
        $original = Clock::new();
        $updated = $original->withSeconds(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithDateReturnsNewInstance(): void
    {
        $original = Clock::new();
        $updated = $original->withDate(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Clock::new();
        $updated = $original->withColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Clock::new();
        $resized = $original->setSize(20, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static factories
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesInstance(): void
    {
        $clock = Clock::new();
        $this->assertInstanceOf(Clock::class, $clock);
    }

    public function testTwentyFourHourCreatesInstance(): void
    {
        $clock = Clock::twentyFourHour();
        $this->assertInstanceOf(Clock::class, $clock);
    }
}
