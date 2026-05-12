<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Gauge;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class GaugeTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testGaugeImplementsSizer(): void
    {
        $gauge = Gauge::new(0.5);
        $this->assertInstanceOf(Sizer::class, $gauge);
    }

    public function testGaugeImplementsItem(): void
    {
        $gauge = Gauge::new(0.5);
        $this->assertInstanceOf(Item::class, $gauge);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $gauge = Gauge::new(0.5);
        $rendered = $gauge->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithZeroRatio(): void
    {
        $gauge = Gauge::new(0.0);
        $rendered = $gauge->render();

        // Should contain empty char (░) but no filled char (█)
        $this->assertStringContainsString('░', $rendered);
        $this->assertStringNotContainsString('█', $rendered);
    }

    public function testRenderWithFullRatio(): void
    {
        $gauge = Gauge::new(1.0);
        $rendered = $gauge->render();

        // Should contain filled char (█) but no empty char (░)
        $this->assertStringContainsString('█', $rendered);
        $this->assertStringNotContainsString('░', $rendered);
    }

    public function testRenderWithHalfRatio(): void
    {
        $gauge = Gauge::new(0.5)->withWidth(10);
        $rendered = $gauge->render();

        // With ratio 0.5 and width 10, we expect ~5 filled chars
        $this->assertStringContainsString('█', $rendered);
        $this->assertStringContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Ratio clamping
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeRatioClampedToZero(): void
    {
        $gauge = new Gauge(-0.5, 20, true, null, null, '█', '░');
        $rendered = $gauge->render();
        // Should render as empty bar (no filled chars) with "0%" label
        $this->assertStringNotContainsString('█', $rendered);
        $this->assertStringContainsString('0%', $rendered);
    }

    public function testOverOneRatioClampedToOne(): void
    {
        $gauge = new Gauge(1.5, 20, true, null, null, '█', '░');
        // The ratio is clamped to 1.0
        $rendered = $gauge->render();
        // Should be all filled, no empty
        $this->assertStringNotContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Width handling
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultWidthIsForty(): void
    {
        // Use no color to test bar width without ANSI overhead
        $gauge = new Gauge(0.5, 40, true, null, null, '█', '░');
        $rendered = $gauge->render();

        // Default width constraint is 40, each char is 1 cell
        // Count filled + empty chars (excluding percentage label)
        $chars = $this->countBarChars($rendered);
        $this->assertLessThanOrEqual(45, $chars); // 40 bar + some label
    }

    public function testWithWidthChangesBarWidth(): void
    {
        $gauge10 = Gauge::new(0.5)->withWidth(10);
        $gauge20 = Gauge::new(0.5)->withWidth(20);

        $rendered10 = $gauge10->render();
        $rendered20 = $gauge20->render();

        // 20-char bar should be roughly twice as long as 10-char bar
        $this->assertGreaterThan(
            strlen($rendered10),
            strlen($rendered20)
        );
    }

    public function testSetSizeOverridesWidthConstraint(): void
    {
        $gauge = Gauge::new(0.5)->withWidth(10);
        $gauge = $gauge->setSize(30, 1);
        $rendered = $gauge->render();

        // Should use width 30 from setSize, not 10 from withWidth
        $chars = $this->countBarChars($rendered);
        $this->assertGreaterThan(15, $chars); // At least half of 30
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $gauge = Gauge::new(0.5)->withWidth(0);
        $this->assertSame('', $gauge->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Percentage display
    // ═══════════════════════════════════════════════════════════════

    public function testShowPercentageByDefault(): void
    {
        $gauge = Gauge::new(0.75);
        $rendered = $gauge->render();

        // Should contain "75%"
        $this->assertStringContainsString('75%', $rendered);
    }

    public function testHidePercentageWithFalse(): void
    {
        $gauge = Gauge::new(0.75)->withPercentage(false);
        $rendered = $gauge->render();

        // Should NOT contain percentage
        $this->assertStringNotContainsString('%', $rendered);
    }

    public function testPercentageLabelWidthIncludedInRender(): void
    {
        $gauge10 = Gauge::new(0.5)->withWidth(10)->withPercentage(true);
        $gaugeNoPct = Gauge::new(0.5)->withWidth(10)->withPercentage(false);

        $rendered10 = $gauge10->render();
        $renderedNoPct = $gaugeNoPct->render();

        // With percentage label, output is wider
        $this->assertGreaterThan(
            strlen($renderedNoPct),
            strlen($rendered10)
        );
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testFilledColorAddsAnsiCodes(): void
    {
        $gauge = Gauge::new(0.5)
            ->withFilledColor(Color::ansi(9)); // Red
        $rendered = $gauge->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testEmptyColorAddsAnsiCodes(): void
    {
        $gauge = Gauge::new(0.5)
            ->withEmptyColor(Color::ansi(8)); // Gray
        $rendered = $gauge->render();

        // Should contain ANSI color codes (empty portion)
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $gauge = Gauge::new(0.5)
            ->withFilledColor(Color::ansi(9))
            ->withEmptyColor(Color::ansi(8));
        $rendered = $gauge->render();

        // Should end with reset code (0m)
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom characters
    // ═══════════════════════════════════════════════════════════════

    public function testWithCharsChangesBarCharacters(): void
    {
        $gauge = Gauge::new(0.5)
            ->withWidth(10)
            ->withChars('=', '-');
        $rendered = $gauge->render();

        // Should contain the custom chars
        $this->assertStringContainsString('=', $rendered);
        $this->assertStringContainsString('-', $rendered);
        // Should NOT contain default chars
        $this->assertStringNotContainsString('█', $rendered);
        $this->assertStringNotContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithRatioReturnsNewInstance(): void
    {
        $original = Gauge::new(0.25);
        $updated = $original->withRatio(0.75);

        $this->assertNotSame($original, $updated);
        // Original ratio unchanged
        $this->assertStringContainsString('25%', $original->render());
        // New ratio
        $this->assertStringContainsString('75%', $updated->render());
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Gauge::new(0.5);
        $resized = $original->setSize(20, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = Gauge::new(0.5)->withWidth(10);
        $wider = $original->withWidth(20);

        $this->assertNotSame($original, $wider);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $gauge = Gauge::new(0.5)->withWidth(20);
        [$w, $h] = $gauge->getInnerSize();

        // Width includes bar + percentage label
        $this->assertGreaterThanOrEqual(20, $w);
        // Height is always 1 for gauge
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithoutWidthConstraint(): void
    {
        $gauge = new Gauge(0.5, null, false, null, null, '█', '░');
        [$w, $h] = $gauge->getInnerSize();

        // No width constraint, so width should be 0
        $this->assertSame(0, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testExactHalfRatio(): void
    {
        $gauge = Gauge::new(0.5)->withWidth(10)->withPercentage(false);
        $rendered = $gauge->render();

        // Should have approximately equal filled and empty
        $filledCount = substr_count($rendered, '█');
        $emptyCount = substr_count($rendered, '░');

        $this->assertSame($filledCount, $emptyCount);
    }

    public function testRenderWithNoSizeAndNoConstraint(): void
    {
        $gauge = new Gauge(0.5, null, false, null, null, '█', '░');
        $this->assertSame('', $gauge->render());
    }

    public function testRatioOneHundredPercent(): void
    {
        $gauge = Gauge::new(1.0)->withWidth(10)->withPercentage(true);
        $rendered = $gauge->render();

        $this->assertStringContainsString('100%', $rendered);
        $this->assertStringNotContainsString('░', $rendered);
    }

    public function testRatioZeroPercent(): void
    {
        $gauge = Gauge::new(0.0)->withWidth(10)->withPercentage(true);
        $rendered = $gauge->render();

        $this->assertStringContainsString('0%', $rendered);
        $this->assertStringNotContainsString('█', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Helper methods
    // ═══════════════════════════════════════════════════════════════

    /**
     * Count non-ANSI bar characters in rendered output.
     */
    private function countBarChars(string $rendered): int
    {
        // Strip ANSI codes to count only visible characters
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);
        if ($stripped === null) {
            return 0;
        }
        // Use mb_strlen to properly count UTF-8 characters
        return mb_strlen($stripped, 'UTF-8');
    }
}
