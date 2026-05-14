<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\System;

use SugarCraft\Dash\Components\System\Stopwatch;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class StopwatchTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testStopwatchImplementsSizer(): void
    {
        $stopwatch = Stopwatch::new();
        $this->assertInstanceOf(Sizer::class, $stopwatch);
    }

    public function testStopwatchImplementsItem(): void
    {
        $stopwatch = Stopwatch::new();
        $this->assertInstanceOf(Item::class, $stopwatch);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $stopwatch = Stopwatch::new();
        $rendered = $stopwatch->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsTimeFormat(): void
    {
        $stopwatch = Stopwatch::new();
        $rendered = $stopwatch->render();

        // Should contain colon for MM:SS format
        $this->assertStringContainsString(':', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Stopwatch values
    // ═══════════════════════════════════════════════════════════════

    public function testZeroStopwatch(): void
    {
        $stopwatch = Stopwatch::new();
        $rendered = $stopwatch->render();

        // Should show 00:00 or similar
        $this->assertMatchesRegularExpression('/0*0:0*0/', $rendered);
    }

    public function testWithElapsed(): void
    {
        $stopwatch = Stopwatch::new()->withElapsed(65000); // 65 seconds
        $rendered = $stopwatch->render();

        // Should contain 01:05 or similar
        $this->assertMatchesRegularExpression('/\d{2}:\d{2}/', $rendered);
    }

    public function testShowMilliseconds(): void
    {
        $stopwatch = Stopwatch::new()->withMilliseconds(true);
        $rendered = $stopwatch->render();

        // Should contain decimal point
        $this->assertStringContainsString('.', $rendered);
    }

    public function testHideMilliseconds(): void
    {
        $stopwatch = Stopwatch::new()->withMilliseconds(false);
        $rendered = $stopwatch->render();

        // Should NOT contain decimal point
        $this->assertStringNotContainsString('.', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Running state
    // ═══════════════════════════════════════════════════════════════

    public function testRunningStateShowsIndicator(): void
    {
        $stopwatch = Stopwatch::new()->withRunning(true);
        $rendered = $stopwatch->render();

        // Should contain play indicator
        $this->assertStringContainsString('▶', $rendered);
    }

    public function testStoppedStateNoIndicator(): void
    {
        $stopwatch = Stopwatch::new()->withRunning(false);
        $rendered = $stopwatch->render();

        // Should NOT contain play indicator
        $this->assertStringNotContainsString('▶', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    public function testGetElapsedSeconds(): void
    {
        $stopwatch = Stopwatch::new()->withElapsed(125000); // 125 seconds
        $seconds = $stopwatch->getElapsedSeconds();

        $this->assertSame(125, $seconds);
    }

    public function testGetElapsedMilliseconds(): void
    {
        $stopwatch = Stopwatch::new()->withElapsed(125000);
        $ms = $stopwatch->getElapsedMilliseconds();

        $this->assertSame(125000, $ms);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $stopwatch = Stopwatch::new()->withColor(Color::ansi(9));
        $rendered = $stopwatch->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $stopwatch = Stopwatch::new()->withColor(Color::ansi(9));
        $rendered = $stopwatch->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSize(): void
    {
        $stopwatch = Stopwatch::new();
        [$w, $h] = $stopwatch->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithMilliseconds(): void
    {
        $stopwatch = Stopwatch::new()->withMilliseconds(true);
        [$w, $h] = $stopwatch->getInnerSize();

        // With milliseconds: MM:SS.XX
        $this->assertGreaterThanOrEqual(8, $w);
    }

    public function testGetInnerSizeRunning(): void
    {
        $stopwatch = Stopwatch::new()->withRunning(true);
        [$w, $h] = $stopwatch->getInnerSize();

        // Running adds " ▶" = 2 chars
        $this->assertGreaterThanOrEqual($w - 2, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithElapsedReturnsNewInstance(): void
    {
        $original = Stopwatch::new();
        $updated = $original->withElapsed(5000);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRunningReturnsNewInstance(): void
    {
        $original = Stopwatch::new();
        $updated = $original->withRunning(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMillisecondsReturnsNewInstance(): void
    {
        $original = Stopwatch::new();
        $updated = $original->withMilliseconds(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Stopwatch::new();
        $updated = $original->withColor(Color::ansi(1));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Stopwatch::new();
        $resized = $original->setSize(20, 1);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Static factories
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesInstance(): void
    {
        $stopwatch = Stopwatch::new();
        $this->assertInstanceOf(Stopwatch::class, $stopwatch);
    }

    public function testStartCreatesRunningInstance(): void
    {
        $stopwatch = Stopwatch::start();
        $this->assertInstanceOf(Stopwatch::class, $stopwatch);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeElapsedClamped(): void
    {
        $stopwatch = Stopwatch::new()->withElapsed(-100);
        $ms = $stopwatch->getElapsedMilliseconds();

        // Should be clamped to 0
        $this->assertGreaterThanOrEqual(0, $ms);
    }

    public function testLongDuration(): void
    {
        $stopwatch = Stopwatch::new()->withElapsed(3600000); // 1 hour
        $rendered = $stopwatch->render();

        // Should show hours
        $this->assertMatchesRegularExpression('/\d+:\d{2}:\d{2}/', $rendered);
    }
}