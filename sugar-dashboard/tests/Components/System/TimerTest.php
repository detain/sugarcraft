<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\System;

use SugarCraft\Dashboard\Components\System\Timer;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
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
        $timer = Timer::new(60)->withElapsedSeconds(120);
        $rendered = $timer->render();

        // Should show 00:00 or 0:00
        $this->assertMatchesRegularExpression('/0*:0*0/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Elapsed tracking
    // ═══════════════════════════════════════════════════════════════

    public function testWithElapsedUpdatesDisplay(): void
    {
        $timer1 = Timer::new(60)->withElapsedSeconds(0);
        $timer2 = Timer::new(60)->withElapsedSeconds(30);

        $rendered1 = $timer1->render();
        $rendered2 = $timer2->render();

        // Different elapsed times should produce different output
        $this->assertNotSame($rendered1, $rendered2);
    }

    public function testNegativeElapsedClampedToZero(): void
    {
        $timer = Timer::new(60)->withElapsedSeconds(-10);
        $rendered = $timer->render();

        // Should not show negative time
        $this->assertStringNotContainsString('-', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    public function testGetRemainingSeconds(): void
    {
        $timer = Timer::new(60)->withElapsedSeconds(25);
        $remaining = $timer->getRemainingSeconds();

        $this->assertSame(35, $remaining);
    }

    public function testGetRemainingSecondsAtZero(): void
    {
        $timer = Timer::new(60)->withElapsedSeconds(100);
        $remaining = $timer->getRemainingSeconds();

        $this->assertSame(0, $remaining);
    }

    public function testIsExpired(): void
    {
        $timer1 = Timer::new(60)->withElapsedSeconds(30);
        $timer2 = Timer::new(60)->withElapsedSeconds(60);
        $timer3 = Timer::new(60)->withElapsedSeconds(120);

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
        $timer = Timer::new(120)->withElapsedSeconds(100)->withWarningThreshold(30);
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
        [$w, $h] = $timer->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeLongerTimer(): void
    {
        $timer = Timer::new(3600);
        [$w, $h] = $timer->innerSize();

        // Hour timer is wider
        $this->assertSame(8, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithElapsedReturnsNewInstance(): void
    {
        $original = Timer::new(60);
        $updated = $original->withElapsedSeconds(30);

        $this->assertNotSame($original, $updated);
    }

    public function testWithRunningReturnsNewInstance(): void
    {
        $original = Timer::new(60);
        $updated = $original->withRunning(true);

        $this->assertNotSame($original, $updated);
    }

    public function testRunningStateChangesRenderedBytes(): void
    {
        // 074: $isRunning was stored and withered but render() never read
        // it — and no test ever compared the two renders. Now the states
        // are byte-distinguishable (Stopwatch's precedent).
        $stopped = Timer::new(60)->withRunning(false);
        $running = Timer::new(60)->withRunning(true);

        $this->assertStringContainsString('▶', $running->render());
        $this->assertStringNotContainsString('▶', $stopped->render());
        $this->assertNotSame($running->render(), $stopped->render());

        [$rw] = $running->innerSize();
        [$sw] = $stopped->innerSize();
        $this->assertSame($sw + 2, $rw);
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
        $timer = Timer::new(60)->withElapsedSeconds(60);
        $this->assertTrue($timer->isExpired());
    }

    // ═══════════════════════════════════════════════════════════════
    // D-81 (finding sugar-dashboard-180): unit-honest setter name
    // ═══════════════════════════════════════════════════════════════

    public function testWithElapsedSecondsCountsSecondsExactly(): void
    {
        // Raw colorless ctor: the unit trap is painted in digits, not hues.
        $timer = new Timer(3661, 0, false, null, null);
        $this->assertSame('1:01:00', $timer->withElapsedSeconds(1)->render());
        // Feeding the SAME literal 999 that Stopwatch::withElapsedMillis
        // would read as 999 ms paints 999 SECONDS here - the 1000x trap
        // made visible.
        $this->assertSame('00:01', (new Timer(1000, 0, false, null, null))->withElapsedSeconds(999)->render());
    }

    public function testDeprecatedWithElapsedForwardsToSecondsName(): void
    {
        $this->assertSame(
            (new Timer(60, 0, false, null, null))->withElapsedSeconds(25)->render(),
            (new Timer(60, 0, false, null, null))->withElapsed(25)->render(),
        );
    }

    public function testRenderDelegatesToSharedDurationFormat(): void
    {
        // Dedup pin: no hand-rolled face literals survive in the
        // component; the formatter owns them now. (Timer still mentions
        // 3600 in its width estimate - that is sizing, not face math.)
        $source = (string) file_get_contents(
            (new \ReflectionClass(Timer::class))->getFileName()
        );
        $this->assertStringNotContainsString("%d:%02d:%02d", $source);
        $this->assertStringNotContainsString("%02d:%02d'", $source);
        $this->assertStringContainsString('DurationFormat::hms($remaining)', $source);
    }
}
