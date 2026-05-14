<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\System;

use SugarCraft\Dash\Components\System\Timer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class TimerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTimerImplementsSizer(): void
    {
        $timer = Timer::new(60);
        $this->assertInstanceOf(Sizer::class, $timer);
    }

    public function testTimerImplementsItem(): void
    {
        $timer = Timer::new(60);
        $this->assertInstanceOf(Item::class, $timer);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $timer = Timer::new(60);
        $rendered = $timer->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTimeFormat(): void
    {
        $timer = Timer::new(60);
        $rendered = $timer->render();

        // Should contain colon for MM:SS format
        $this->assertStringContainsString(':', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Timer values
    // ═══════════════════════════════════════════════════════════════

    public function testOneMinuteTimer(): void
    {
        $timer = Timer::new(60);
        $rendered = $timer->render();

        // Should show 01:00 or similar
        $this->assertMatchesRegularExpression('/0[01]:\d{2}/', $rendered);
    }

    public function testOneHourTimer(): void
    {
        $timer = Timer::new(3600);
        $rendered = $timer->render();

        // Should show 1:00:00 or similar
        $this->assertMatchesRegularExpression('/\d:\d{2}:\d{2}/', $rendered);
    }

    public function testExpiredTimer(): void
    {
        $timer = Timer::new(60)->withElapsed(120);
        $rendered = $timer->render();

        // Should show 00:00 or 0:00
        $this->assertMatchesRegularExpression('/0*:0*0/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Elapsed tracking
    // ═══════════════════════════════════════════════════════════════

    public function testWithElapsedUpdatesDisplay(): void
    {
        $timer1 = Timer::new(60)->withElapsed(0);
        $timer2 = Timer::new(60)->withElapsed(30);

        $rendered1 = $timer1->render();
        $rendered2 = $timer2->render();

        // Different elapsed times should produce different output
        $this->assertNotSame($rendered1, $rendered2);
    }

    public function testNegativeElapsedClampedToZero(): void
    {
        $timer = Timer::new(60)->withElapsed(-10);
        $rendered = $timer->render();

        // Should not show negative time
        $this->assertStringNotContainsString('-', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    public function testGetRemainingSeconds(): void
    {
        $timer = Timer::new(60)->withElapsed(25);
        $remaining = $timer->getRemainingSeconds();

        $this->assertSame(35, $remaining);
    }

    public function testGetRemainingSecondsAtZero(): void
    {
        $timer = Timer::new(60)->withElapsed(100);
        $remaining = $timer->getRemainingSeconds();

        $this->assertSame(0, $remaining);
    }

    public function testIsExpired(): void
    {
        $timer1 = Timer::new(60)->withElapsed(30);
        $timer2 = Timer::new(60)->withElapsed(60);
        $timer3 = Timer::new(60)->withElapsed(120);

        $this->assertFalse($timer1->isExpired());
        $this->assertTrue($timer2->isExpired());
        $this->assertTrue($timer3->isExpired());
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $timer = Timer::new(60)->withColor(Color::ansi(9));
        $rendered = $timer->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $timer = Timer::new(60)->withColor(Color::ansi(9));
        $rendered = $timer->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testWarningColorWhenLowTime(): void
    {
        $timer = Timer::new(120)->withElapsed(100)->withWarningThreshold(30);
        $rendered = $timer->render();

        // With 20 seconds remaining and threshold of 30, should use warning color
        // The render should still produce valid output
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $timer = Timer::new(60);
        [$w, $h] = $timer->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeLongerTimer(): void
    {
        $timer = Timer::new(3600);
        [$w, $h] = $timer->getInnerSize();

        // Hour timer is wider
        $this->assertSame(8, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithElapsedReturnsNewInstance(): void
    {
        $original = Timer::new(60);
        $updated = $original->withElapsed(30);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRunningReturnsNewInstance(): void
    {
        $original = Timer::new(60);
        $updated = $original->withRunning(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Timer::new(60);
        $updated = $original->withColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithWarningColorReturnsNewInstance(): void
    {
        $original = Timer::new(60);
        $updated = $original->withWarningColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testWithWarningThresholdReturnsNewInstance(): void
    {
        $original = Timer::new(60);
        $updated = $original->withWarningThreshold(30);

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Timer::new(60);
        $resized = $original->setSize(20, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static factories
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesInstance(): void
    {
        $timer = Timer::new(60);
        $this->assertInstanceOf(Timer::class, $timer);
    }

    public function testFromMinutesCreatesInstance(): void
    {
        $timer = Timer::fromMinutes(5);
        $this->assertSame(300, $timer->getRemainingSeconds());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testZeroDurationTimer(): void
    {
        $timer = Timer::new(0);
        $rendered = $timer->render();

        // Should show 00:00 or similar
        $this->assertMatchesRegularExpression('/0*:0*0/', $rendered);
    }

    public function testExactlyAtExpiry(): void
    {
        $timer = Timer::new(60)->withElapsed(60);
        $this->assertTrue($timer->isExpired());
    }
}
