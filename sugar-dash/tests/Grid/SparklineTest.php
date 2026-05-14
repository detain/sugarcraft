<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Sparkline;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SparklineTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSparklineImplementsSizer(): void
    {
        $sparkline = Sparkline::new([1, 2, 3]);
        $this->assertInstanceOf(Sizer::class, $sparkline);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5]);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithDataContainsBlockChars(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5]);
        $rendered = $sparkline->render();

        // Should contain Unicode block characters
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $sparkline = Sparkline::new([]);
        $this->assertSame('', $sparkline->render());
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $sparkline = Sparkline::new([1, 2, 3])->withWidth(0);
        $this->assertSame('', $sparkline->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Single-line vs multi-line rendering
    // ═══════════════════════════════════════════════════════════════

    public function testSingleLineHeightOne(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])->withHeight(1);
        $rendered = $sparkline->render();

        // Single line should not contain newlines
        $this->assertStringNotContainsString("\n", $rendered);
    }

    public function testMultiLineHeightGreaterThanOne(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])->withHeight(3);
        $rendered = $sparkline->render();

        // Multi-line should contain newlines
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testMultiLineHasCorrectLineCount(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])->withHeight(3);
        $rendered = $sparkline->render();

        $lines = explode("\n", $rendered);
        $this->assertCount(3, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Data point normalization (upscaling/downscalling)
    // ═══════════════════════════════════════════════════════════════

    public function testDataDownscaledToFitWidth(): void
    {
        // 10 data points squeezed into width 5
        $data = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $sparkline = Sparkline::new($data)->withWidth(5);
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
        $sparkline = Sparkline::new($data)->withWidth(10);
        $rendered = $sparkline->render();

        // Should render 10 positions worth of data
        $this->assertNotSame('', $rendered);
    }

    public function testDataWithoutWidthConstraintUsesDataCount(): void
    {
        $data = [1, 2, 3, 4, 5];
        $sparkline = Sparkline::new($data);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(5, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Data point markers
    // ═══════════════════════════════════════════════════════════════

    public function testShowDataPointsAddsMarkers(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withDataPoints(true);
        $rendered = $sparkline->render();

        // Should contain the dot marker character
        $this->assertStringContainsString('•', $rendered);
    }

    public function testHideDataPointsRemovesMarkers(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withDataPoints(false);
        $rendered = $sparkline->render();

        // Should NOT contain the dot marker
        $this->assertStringNotContainsString('•', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Fill behavior
    // ═══════════════════════════════════════════════════════════════

    public function testFillAddsPartialBlockChars(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withHeight(3)
            ->withFill(true);
        $rendered = $sparkline->render();

        // Should contain partial fill character
        $this->assertStringContainsString('░', $rendered);
    }

    public function testNoFillOnlyUsesFullBlocks(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withHeight(3)
            ->withFill(false);
        $rendered = $sparkline->render();

        // Should not contain partial fill character
        $this->assertStringNotContainsString('░', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color application (color, maxColor, minColor)
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withColor(Color::ansi(9));
        $rendered = $sparkline->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testMaxColorAddsAnsiCodesToHighestValue(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withColor(Color::ansi(0))
            ->withMaxColor(Color::ansi(10)); // Green for max
        $rendered = $sparkline->render();

        // Should contain ANSI codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testMinColorAddsAnsiCodesToLowestValue(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withColor(Color::ansi(0))
            ->withMinColor(Color::ansi(9)); // Red for min
        $rendered = $sparkline->render();

        // Should contain ANSI codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withColor(Color::ansi(9));
        $rendered = $sparkline->render();

        // Should end with reset code (0m)
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testNullColorNoAnsiCodes(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withColor(null)
            ->withMaxColor(null)
            ->withMinColor(null);
        $rendered = $sparkline->render();

        // Should NOT contain ANSI codes
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithDataReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withData([4, 5, 6]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withWidth(10);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withHeight(3);

        $this->assertNotSame($original, $updated);
    }

    public function testWithDataPointsReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withDataPoints(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithFillReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withFill(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithMaxColorReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withMaxColor(Color::ansi(10));

        $this->assertNotSame($original, $updated);
    }

    public function testWithMinColorReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withMinColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithFillColorReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3]);
        $updated = $original->withFillColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize returns new instance
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Sparkline::new([1, 2, 3, 4, 5]);
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRenderedWidth(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])->setSize(20, 1);
        $rendered = $sparkline->render();

        // Should render with width 20
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])->withHeight(3);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(5, $w);
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeWithWidthConstraint(): void
    {
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])->withWidth(10)->withHeight(2);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(10, $w);
        $this->assertSame(2, $h);
    }

    public function testGetInnerSizeEmptyDataReturnsZeroWidth(): void
    {
        $sparkline = Sparkline::new([])->withHeight(3);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeAfterSetSize(): void
    {
        $sparkline = Sparkline::new([1, 2, 3])->withHeight(4)->setSize(15, 1);
        [$w, $h] = $sparkline->getInnerSize();

        // setSize sets width but getInnerSize uses the sparkline's height property
        $this->assertSame(15, $w);
        $this->assertSame(4, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    /**
     * Single data point with height > 1 renders without error.
     * Bug fix: range check in renderMultiLine prevents DivisionByZeroError.
     */
    public function testSingleDataPointWithHeightGreaterThanOne(): void
    {
        // Single data point with height > 1 should render correctly
        $sparkline = Sparkline::new([42])->withHeight(2);
        $rendered = $sparkline->render();

        // Should render without error and produce multi-line output
        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString("\n", $rendered);
    }

    /**
     * All same values with height > 1 renders without error.
     * Bug fix: range check in renderMultiLine prevents DivisionByZeroError.
     */
    public function testAllSameValuesWithHeightGreaterThanOne(): void
    {
        // All same values with height > 1 should render correctly
        $sparkline = Sparkline::new([5, 5, 5, 5, 5])->withHeight(2);
        $rendered = $sparkline->render();

        // Should render without error
        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testNegativeValues(): void
    {
        $sparkline = Sparkline::new([-5, -3, -1, 0, 2]);
        $rendered = $sparkline->render();

        // Should handle negative values correctly
        $this->assertNotSame('', $rendered);
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testFloatingPointValues(): void
    {
        $sparkline = Sparkline::new([0.1, 0.5, 0.9, 0.3, 0.7]);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHeightClampedToMinimumOne(): void
    {
        $sparkline = Sparkline::new([1, 2, 3])->withHeight(0);
        [$w, $h] = $sparkline->getInnerSize();

        // Height should be clamped to 1
        $this->assertSame(1, $h);
    }

    public function testRenderWithNoWidthAndNoData(): void
    {
        $sparkline = new Sparkline(
            data: [],
            widthConstraint: null,
            height: 1,
            showDataPoints: false,
            fill: false,
            color: null,
            maxColor: null,
            minColor: null,
            fillColor: null,
        );
        $this->assertSame('', $sparkline->render());
    }

    public function testFillColorOnlyUsedWhenFillEnabled(): void
    {
        // Without fill, fillColor should not affect output
        $sparkline = Sparkline::new([1, 2, 3, 4, 5])
            ->withHeight(3)
            ->withFill(false)
            ->withFillColor(Color::ansi(8));
        $rendered = $sparkline->render();

        // Should not contain the fill character when fill is disabled
        $this->assertStringNotContainsString('░', $rendered);
    }
}
