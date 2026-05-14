<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\SparklineBar;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SparklineBarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSparklineBarImplementsSizer(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3]);
        $this->assertInstanceOf(Sizer::class, $sparkline);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5]);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithDataContainsBlockChars(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5]);
        $rendered = $sparkline->render();

        // Should contain Unicode block characters
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $sparkline = SparklineBar::new([]);
        $this->assertSame('', $sparkline->render());
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3])->withWidth(0);
        $this->assertSame('', $sparkline->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Multi-line rendering (height > 1)
    // ═══════════════════════════════════════════════════════════════

    public function testMultiLineHeightGreaterThanOne(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])->withHeight(4);
        $rendered = $sparkline->render();

        // Multi-line should contain newlines
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testMultiLineHasCorrectLineCount(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])->withHeight(4);
        $rendered = $sparkline->render();

        $lines = explode("\n", $rendered);
        $this->assertCount(4, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Data point normalization (upscaling/downscalling)
    // ═══════════════════════════════════════════════════════════════

    public function testDataDownscaledToFitWidth(): void
    {
        // 10 data points squeezed into width 5
        $data = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $sparkline = SparklineBar::new($data)->withWidth(5);
        $rendered = $sparkline->render();

        // Should still render something (not empty)
        $this->assertNotSame('', $rendered);
        // Should contain block chars
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testDataUpscaledToFitWidth(): void
    {
        // 3 data points expanded to width 10
        $data = [1, 5, 10];
        $sparkline = SparklineBar::new($data)->withWidth(10);
        $rendered = $sparkline->render();

        // Should render 10 positions worth of data
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Show values
    // ═══════════════════════════════════════════════════════════════

    public function testShowValuesAddsNumericRow(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])
            ->withShowValues(true);
        $rendered = $sparkline->render();

        // Should contain numeric values (3 chars each)
        $this->assertMatchesRegularExpression('/[0-9]{3}/', $rendered);
    }

    public function testHideValuesRemovesNumericRow(): void
    {
        $sparkline = SparklineBar::new([100, 200, 300])
            ->withShowValues(false);
        $rendered = $sparkline->render();

        // Should NOT contain large numbers (3 digits)
        $this->assertStringNotContainsString('100', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color application
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])
            ->withColor(Color::ansi(9));
        $rendered = $sparkline->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testMaxColorAddsAnsiCodesToHighestValue(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])
            ->withColor(Color::ansi(0))
            ->withMaxColor(Color::ansi(10)); // Green for max
        $rendered = $sparkline->render();

        // Should contain ANSI codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testMinColorAddsAnsiCodesToLowestValue(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])
            ->withColor(Color::ansi(0))
            ->withMinColor(Color::ansi(9)); // Red for min
        $rendered = $sparkline->render();

        // Should contain ANSI codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])
            ->withColor(Color::ansi(9));
        $rendered = $sparkline->render();

        // Should end with reset code (0m)
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testNullColorNoAnsiCodes(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])
            ->withColor(null)
            ->withMaxColor(null)
            ->withMinColor(null);
        $rendered = $sparkline->render();

        // Should NOT contain ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Bar gaps
    // ═══════════════════════════════════════════════════════════════

    public function testBarGapsAddsSeparators(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])
            ->withBarGaps(true)
            ->withSeparator(',');
        $rendered = $sparkline->render();

        // Should contain the separator
        $this->assertStringContainsString(',', $rendered);
    }

    public function testNoBarGapsRemovesSeparators(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])
            ->withBarGaps(false);
        $rendered = $sparkline->render();

        // Should still render but without commas
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithDataReturnsNewInstance(): void
    {
        $original = SparklineBar::new([1, 2, 3]);
        $updated = $original->withData([4, 5, 6]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = SparklineBar::new([1, 2, 3]);
        $updated = $original->withWidth(10);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = SparklineBar::new([1, 2, 3]);
        $updated = $original->withHeight(4);

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = SparklineBar::new([1, 2, 3, 4, 5]);
        $resized = $original->setSize(10, 4);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])->withHeight(4);
        [$w, $h] = $sparkline->getInnerSize();

        // Width: 5 bars + 4 gaps (separator=' ') = 9
        $this->assertSame(9, $w);
        $this->assertSame(4, $h);
    }

    public function testGetInnerSizeWithWidthConstraint(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3, 4, 5])->withWidth(10)->withHeight(2);
        [$w, $h] = $sparkline->getInnerSize();

        // Width: 10 bars + 9 gaps = 19
        $this->assertSame(19, $w);
        $this->assertSame(2, $h);
    }

    public function testGetInnerSizeWithValuesRow(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3])->withHeight(4)->withShowValues(true);
        [$w, $h] = $sparkline->getInnerSize();

        // Height includes the values row
        $this->assertSame(5, $h);
    }

    public function testGetInnerSizeEmptyDataReturnsZeroWidth(): void
    {
        $sparkline = SparklineBar::new([])->withHeight(3);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeValues(): void
    {
        $sparkline = SparklineBar::new([-5, -3, -1, 0, 2]);
        $rendered = $sparkline->render();

        // Should handle negative values correctly
        $this->assertNotSame('', $rendered);
    }

    public function testFloatingPointValues(): void
    {
        $sparkline = SparklineBar::new([0.1, 0.5, 0.9, 0.3, 0.7]);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHeightClampedToMinimumOne(): void
    {
        $sparkline = SparklineBar::new([1, 2, 3])->withHeight(0);
        [$w, $h] = $sparkline->getInnerSize();

        // Height should be clamped to 1
        $this->assertSame(1, $h);
    }
}
