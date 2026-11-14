<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\System;

use SugarCraft\Dashboard\Components\System\Clock;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
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
        [$w, $h] = $clock->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithDate(): void
    {
        $clock = Clock::new()->withDate(true);
        [$w, $h] = $clock->innerSize();

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

    // ═══════════════════════════════════════════════════════════════
    // D-80: default matches the factory promise; render() is golden-able
    // ═══════════════════════════════════════════════════════════════

    /**
     * An unambiguous afternoon instant, built in the same default timezone
     * the component's date() leg reads - so the expected faces hold on any
     * host. (13:47:22)
     */
    private static function promiseNoon(): int
    {
        return (int) strtotime('2026-05-13 13:47:22');
    }

    /** The midnight-hour leg of the 12-hour converter. (00:05:09) */
    private static function promiseMidnight(): int
    {
        return (int) strtotime('2026-05-13 00:05:09');
    }

    public function testDefaultFaceIsTwentyFourHourLikeTheFactoryClaims(): void
    {
        // Clock::new() docblock promises "24-hour with seconds"; the knob
        // to get AM/PM is with24Hour(false), not a surprise default.
        $rendered = (new Clock(true, true, false, null))->render(self::promiseNoon());

        $this->assertSame('13:47:22', $rendered);
    }

    public function testRenderIsDeterministicThroughTheNowSeam(): void
    {
        // Two renders of the same fixed instant cannot disagree, and the
        // factory default now agrees with the docblock (finding 075).
        $first = Clock::new()->render(self::promiseNoon());
        $second = Clock::new()->render(self::promiseNoon());

        $this->assertSame($first, $second);
        $this->assertStringContainsString('13:47:22', $first);
        $this->assertDoesNotMatchRegularExpression('/(AM|PM)/', $first);
    }

    public function testSeamPaintsTwelveHourAmPmFaceAtTheSameInstant(): void
    {
        // The seam drives BOTH faces: same instant, honest 12-hour form,
        // including the 0 -> 12 AM boundary no live clock test can hit.
        $pm = (new Clock(false, true, false, null))->render(self::promiseNoon());
        $midnight = (new Clock(false, true, false, null))->render(self::promiseMidnight());

        $this->assertSame('01:47:22 PM', $pm);
        $this->assertSame('12:05:09 AM', $midnight);
    }

    public function testSeamDrivesTheDateLegToo(): void
    {
        // The date column used to read the wall clock even when render()
        // was handed an instant - the whole row now comes from $now.
        $clock = (new Clock(true, true, true, null));

        $this->assertSame('13:47:22  Wed, May 13', $clock->render(self::promiseNoon()));
    }

    public function testFactoryPromiseProbe(): void
    {
        // Probe of acceptance: the twentyFourHour() factory and the default
        // face are the same output for the same instant.
        $this->assertSame(
            Clock::twentyFourHour()->render(self::promiseNoon()),
            Clock::new()->render(self::promiseNoon()),
        );
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