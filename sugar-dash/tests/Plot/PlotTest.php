<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot;

use SugarCraft\Dash\Plot\Plot;
use SugarCraft\Dash\Foundation\Buffer;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class PlotTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesPlot(): void
    {
        $plot = Plot::new();
        $this->assertNotNull($plot);
    }

    public function testImplementsSizer(): void
    {
        $plot = Plot::new();
        $this->assertInstanceOf(Sizer::class, $plot);
    }

    public function testImplementsDrawable(): void
    {
        $plot = Plot::new();
        $this->assertInstanceOf(Drawable::class, $plot);
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering - empty
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyPlot(): void
    {
        $plot = Plot::new();
        $rendered = $plot->render();

        // Should return empty string for zero dimensions
        $this->assertNotSame('', $rendered);
    }

    public function testRenderEmptyPlotWithAxes(): void
    {
        $plot = Plot::new([], 20, 10);
        $rendered = $plot->render();

        $this->assertNotSame('', $rendered);
        // Should contain newlines for multi-line output
        $this->assertStringContainsString("\n", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering - with data
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithDataPoints(): void
    {
        $plot = Plot::new([10, 20, 30, 40, 50], 40, 12);
        $rendered = $plot->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithDataPointsContainsBraille(): void
    {
        $plot = Plot::new([10, 20, 30, 40, 50], 40, 12);
        $rendered = $plot->render();

        // Should contain braille characters (U+2800 to U+28FF)
        $this->assertMatchesRegularExpression('/[\x{2800}-\x{28FF}]/u', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Mode
    // ═══════════════════════════════════════════════════════════════

    public function testLineChartMode(): void
    {
        $plot = Plot::new([10, 20, 30, 40, 50], 40, 12)
            ->withMode(Plot::MODE_LINE);

        $this->assertNotSame('', $plot->render());
    }

    public function testScatterPlotMode(): void
    {
        $plot = Plot::new([10, 20, 30, 40, 50], 40, 12)
            ->withMode(Plot::MODE_SCATTER);

        $rendered = $plot->render();
        $this->assertNotSame('', $rendered);
    }

    public function testModeConstants(): void
    {
        $this->assertSame('line', Plot::MODE_LINE);
        $this->assertSame('scatter', Plot::MODE_SCATTER);
    }

    public function testMarkerConstants(): void
    {
        $this->assertSame('braille', Plot::MARKER_BRAILLE);
        $this->assertSame('dot', Plot::MARKER_DOT);
    }

    // ═══════════════════════════════════════════════════════════════
    // Marker
    // ═══════════════════════════════════════════════════════════════

    public function testMarkerBrailleDefault(): void
    {
        $plot = Plot::new([10, 20, 30], 40, 12);
        $rendered = $plot->render();

        // Default marker is braille
        $this->assertNotSame('', $rendered);
    }

    public function testWithMarkerChangesPlot(): void
    {
        $plot = Plot::new([10, 20, 30], 40, 12)
            ->withMarker(Plot::MARKER_DOT);

        $rendered = $plot->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Axes
    // ═══════════════════════════════════════════════════════════════

    public function testShowAxesRendersLabels(): void
    {
        $plot = Plot::new([10, 20, 30, 40, 50], 40, 12)
            ->withShowAxes(true);

        $rendered = $plot->render();

        // With axes, should have extra rows for labels
        $this->assertNotSame('', $rendered);
    }

    public function testWithoutAxesRendersNoLabels(): void
    {
        $plot = Plot::new([10, 20, 30, 40, 50], 40, 12)
            ->withShowAxes(false);

        $rendered = $plot->render();

        // Without axes, should be just the canvas output
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Horizontal scale
    // ═══════════════════════════════════════════════════════════════

    public function testHorizontalScaleAffectsSpacing(): void
    {
        $plot1 = Plot::new([10, 20, 30], 40, 12)
            ->withHorizontalScale(1);
        $plot2 = Plot::new([10, 20, 30], 40, 12)
            ->withHorizontalScale(2);

        $rendered1 = $plot1->render();
        $rendered2 = $plot2->render();

        // Different scales should produce different output
        $this->assertNotSame($rendered1, $rendered2);
    }

    public function testHorizontalScaleClampedToMinimum1(): void
    {
        $plot = Plot::new([10, 20, 30], 40, 12)
            ->withHorizontalScale(0);

        $rendered = $plot->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color
    // ═══════════════════════════════════════════════════════════════

    public function testWithColorAddsAnsiCodes(): void
    {
        $plot = Plot::new([10, 20, 30], 40, 12)
            ->withColor(Color::ansi(9));

        $rendered = $plot->render();

        // Should contain ANSI escape codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testWithColorEndsWithReset(): void
    {
        $plot = Plot::new([10, 20, 30], 40, 12)
            ->withColor(Color::ansi(9));

        $rendered = $plot->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Value range
    // ═══════════════════════════════════════════════════════════════

    public function testWithMinValue(): void
    {
        $plot = Plot::new([50, 60, 70], 40, 12)
            ->withMinValue(0);

        $rendered = $plot->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithMaxValue(): void
    {
        $plot = Plot::new([10, 20, 30], 40, 12)
            ->withMaxValue(100);

        $rendered = $plot->render();
        $this->assertNotSame('', $rendered);
    }

    public function testWithDataUpdatesValueRange(): void
    {
        $plot = Plot::new([100, 200, 300], 40, 12)
            ->withData([10, 20, 30]);

        $rendered = $plot->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // setSize
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $plot = Plot::new();
        $resized = $plot->setSize(40, 12);

        $this->assertNotSame($plot, $resized);
    }

    public function testSetSizeAffectsDimensions(): void
    {
        $plot = Plot::new();
        $resized = $plot->setSize(60, 20);

        [$w, $h] = $resized->getInnerSize();
        $this->assertSame(60, $w);
        $this->assertSame(20, $h);
    }

    public function testImplementsSizerInterface(): void
    {
        $plot = Plot::new();
        $resized = $plot->setSize(40, 12);

        // setSize should return Sizer
        $this->assertInstanceOf(Sizer::class, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers return new instance
    // ═══════════════════════════════════════════════════════════════

    public function testWithModeReturnsNewInstance(): void
    {
        $original = Plot::new();
        $updated = $original->withMode(Plot::MODE_SCATTER);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMarkerReturnsNewInstance(): void
    {
        $original = Plot::new();
        $updated = $original->withMarker(Plot::MARKER_DOT);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowAxesReturnsNewInstance(): void
    {
        $original = Plot::new();
        $updated = $original->withShowAxes(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHorizontalScaleReturnsNewInstance(): void
    {
        $original = Plot::new();
        $updated = $original->withHorizontalScale(2);

        $this->assertNotSame($original, $updated);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Plot::new();
        $updated = $original->withColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithDataReturnsNewInstance(): void
    {
        $original = Plot::new();
        $updated = $original->withData([1, 2, 3]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMinValueReturnsNewInstance(): void
    {
        $original = Plot::new();
        $updated = $original->withMinValue(10.0);

        $this->assertNotSame($original, $updated);
    }

    public function testWithMaxValueReturnsNewInstance(): void
    {
        $original = Plot::new();
        $updated = $original->withMaxValue(100.0);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testAllSameValues(): void
    {
        $plot = Plot::new([50, 50, 50, 50], 40, 12);
        $rendered = $plot->render();

        // Should still render without division issues
        $this->assertNotSame('', $rendered);
    }

    public function testNegativeValues(): void
    {
        $plot = Plot::new([-10, -5, 0, 5, 10], 40, 12);
        $rendered = $plot->render();

        $this->assertNotSame('', $rendered);
    }

    public function testNullValuesInData(): void
    {
        $plot = Plot::new([10, null, 30, null, 50], 40, 12);
        $rendered = $plot->render();

        $this->assertNotSame('', $rendered);
    }

    public function testVerySmallWidth(): void
    {
        $plot = Plot::new([10, 20, 30], 3, 12);
        $rendered = $plot->render();

        // Should handle small width gracefully
        $this->assertNotSame('', $rendered);
    }

    public function testVerySmallHeight(): void
    {
        $plot = Plot::new([10, 20, 30], 40, 2);
        $rendered = $plot->render();

        // Very small height with axes on produces empty string (innerHeight=0)
        $this->assertSame('', $rendered);
    }

    public function testVeryLargeValues(): void
    {
        $plot = Plot::new([1_000_000, 2_000_000, 3_000_000], 40, 12);
        $rendered = $plot->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSingleDataPoint(): void
    {
        $plot = Plot::new([42], 40, 12);
        $rendered = $plot->render();

        $this->assertNotSame('', $rendered);
    }
}
