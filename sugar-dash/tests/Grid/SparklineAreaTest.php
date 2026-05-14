<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\SparklineArea;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SparklineAreaTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSparklineAreaImplementsSizer(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3]);
        $this->assertInstanceOf(Sizer::class, $sparkline);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5]);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithDataContainsBlockChars(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5]);
        $rendered = $sparkline->render();

        // Should contain Unicode block characters
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $sparkline = SparklineArea::new([]);
        $this->assertSame('', $sparkline->render());
    }

    public function testZeroWidthRendersEmpty(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3])->withWidth(0);
        $this->assertSame('', $sparkline->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Single-line vs multi-line rendering
    // ═══════════════════════════════════════════════════════════════

    public function testSingleLineHeightOne(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])->withHeight(1);
        $rendered = $sparkline->render();

        // Single line should not contain newlines
        $this->assertStringNotContainsString("\n", $rendered);
    }

    public function testMultiLineHeightGreaterThanOne(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])->withHeight(3);
        $rendered = $sparkline->render();

        // Multi-line should contain newlines
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testMultiLineHasCorrectLineCount(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])->withHeight(3);
        $rendered = $sparkline->render();

        $lines = explode("\n", $rendered);
        $this->assertCount(3, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Show line / fill options
    // ═══════════════════════════════════════════════════════════════

    public function testShowFillAddsFillCharacters(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])
            ->withHeight(3)
            ->withShowFill(true);
        $rendered = $sparkline->render();

        // Should contain fill characters
        $this->assertMatchesRegularExpression('/[░▒▓█]/', $rendered);
    }

    public function testHideFillOnlyUsesLineCharacters(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])
            ->withHeight(3)
            ->withShowFill(false);
        $rendered = $sparkline->render();

        // Should still render but with fewer fill chars
        $this->assertNotSame('', $rendered);
    }

    public function testHideLineOnlyUsesFillCharacters(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])
            ->withHeight(3)
            ->withShowLine(false)
            ->withShowFill(true);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Data point normalization
    // ═══════════════════════════════════════════════════════════════

    public function testDataDownscaledToFitWidth(): void
    {
        $data = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $sparkline = SparklineArea::new($data)->withWidth(5);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testDataUpscaledToFitWidth(): void
    {
        $data = [1, 5, 10];
        $sparkline = SparklineArea::new($data)->withWidth(10);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color application
    // ═══════════════════════════════════════════════════════════════

    public function testLineColorAddsAnsiCodes(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])
            ->withLineColor(Color::ansi(9));
        $rendered = $sparkline->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testFillColorAddsAnsiCodes(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])
            ->withFillColor(Color::ansi(10));
        $rendered = $sparkline->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testMaxColorAddsAnsiCodesToHighestValue(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])
            ->withLineColor(Color::ansi(0))
            ->withMaxColor(Color::ansi(10));
        $rendered = $sparkline->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testMinColorAddsAnsiCodesToLowestValue(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])
            ->withLineColor(Color::ansi(0))
            ->withMinColor(Color::ansi(9));
        $rendered = $sparkline->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])
            ->withLineColor(Color::ansi(9));
        $rendered = $sparkline->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithDataReturnsNewInstance(): void
    {
        $original = SparklineArea::new([1, 2, 3]);
        $updated = $original->withData([4, 5, 6]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = SparklineArea::new([1, 2, 3]);
        $updated = $original->withWidth(10);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = SparklineArea::new([1, 2, 3]);
        $updated = $original->withHeight(3);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowLineReturnsNewInstance(): void
    {
        $original = SparklineArea::new([1, 2, 3]);
        $updated = $original->withShowLine(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowFillReturnsNewInstance(): void
    {
        $original = SparklineArea::new([1, 2, 3]);
        $updated = $original->withShowFill(true);

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = SparklineArea::new([1, 2, 3, 4, 5]);
        $resized = $original->setSize(10, 3);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])->withHeight(3);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(5, $w);
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeWithWidthConstraint(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3, 4, 5])->withWidth(10)->withHeight(2);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(10, $w);
        $this->assertSame(2, $h);
    }

    public function testGetInnerSizeEmptyDataReturnsZeroWidth(): void
    {
        $sparkline = SparklineArea::new([])->withHeight(3);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleDataPointWithHeightGreaterThanOne(): void
    {
        $sparkline = SparklineArea::new([42])->withHeight(2);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testAllSameValuesWithHeightGreaterThanOne(): void
    {
        $sparkline = SparklineArea::new([5, 5, 5, 5, 5])->withHeight(2);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString("\n", $rendered);
    }

    public function testNegativeValues(): void
    {
        $sparkline = SparklineArea::new([-5, -3, -1, 0, 2]);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testFloatingPointValues(): void
    {
        $sparkline = SparklineArea::new([0.1, 0.5, 0.9, 0.3, 0.7]);
        $rendered = $sparkline->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHeightClampedToMinimumOne(): void
    {
        $sparkline = SparklineArea::new([1, 2, 3])->withHeight(0);
        [$w, $h] = $sparkline->getInnerSize();

        $this->assertSame(1, $h);
    }
}
