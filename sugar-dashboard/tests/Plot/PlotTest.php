<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plot;

use SugarCraft\Dashboard\Plot\Plot;
use SugarCraft\Dashboard\Foundation\Buffer;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Foundation\Drawable;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
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

        [$w, $h] = $resized->innerSize();
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

    public function testWithModeAcceptsBothDocumentedModes(): void
    {
        $plot = Plot::new();
        $this->assertNotSame($plot, $plot->withMode(Plot::MODE_LINE));
        $this->assertNotSame($plot, $plot->withMode(Plot::MODE_SCATTER));
    }

    public function testWithModeThrowsOnUnknownVocabularyListingValidSet(): void
    {
        // finding sugar-dashboard-195: unknown strings used to fall through
        // silently to the scatter path; a typo must fail loud at the boundary.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/line/');
        Plot::new()->withMode('bar-chart');
    }

    public function testWithModeRejectsCaseAndWhitespaceVariants(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Plot::new()->withMode('Line');
    }

    // ─── D-93 (finding sugar-dashboard-185): range footguns ───────

    public function testWithMinValueAboveCurrentMaxThrows(): void
    {
        // Plot::new() defaults to the 0..100 auto window; 150 inverts it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds current maximum/');
        Plot::new()->withMinValue(150.0);
    }

    public function testWithMaxValueBelowCurrentMinThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/below current minimum/');
        Plot::new()->withMinValue(50.0)->withMaxValue(20.0);
    }

    public function testManualRangeSurvivesWithData(): void
    {
        // The auto-rescale used to clobber the explicit window: labels jumped
        // to the data-derived K scale after withData.
        $plot = Plot::new([0.0, 5.0, 10.0], 40, 12)
            ->withMinValue(0.0)
            ->withMaxValue(10.0)
            ->withData([0.0, 1000.0, 2000.0]);

        $rendered = $plot->render();

        $this->assertStringNotContainsString('2.0K', $rendered);
        $this->assertStringNotContainsString('1.0K', $rendered);
        $this->assertStringContainsString('10', $rendered);
    }

    public function testAutoRangeStillFollowsDataWhenNoManualWindow(): void
    {
        // Guard against the manual flag leaking into the default path.
        $rendered = Plot::new([0.0, 5.0, 10.0], 40, 12)
            ->withData([0.0, 1000.0, 2000.0])
            ->render();

        $this->assertStringContainsString('2.0K', $rendered);
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

    // ═══════════════════════════════════════════════════════════════
    // D-92 (findings 082/183/184/197/234) - shared geometry law
    // ═══════════════════════════════════════════════════════════════

    public function testLongAxisLabelsNeverOverrunTheAllocation(): void
    {
        $plot = Plot::new([-1_500_000.0, 1_000_000.0, 0.0], 40, 8);
        $rows = self::plainRows($plot->render());

        $this->assertCount(8, $rows, 'axes mode paints innerHeight + two label rows');
        foreach ($rows as $i => $row) {
            $this->assertSame(40, Width::string($row), "row {$i} is exactly the allocation");
        }
        $this->assertStringContainsString('-1.5M', $rows[5], 'the widest label is painted, not clipped away');
    }

    public function testHistoryBeyondTheCanvasIsWindowedNotPiled(): void
    {
        // width 8, axes on -> innerWidth 6 -> dotWidth 12, step 2 -> 6 slots.
        // 30 samples: only indices 24..29 land on the canvas and the x labels
        // say so (the old walk painted 0..29 into slot min(x,11), piling the
        // tail into one column and labelling the origin '0' - fiction).
        $data = range(1, 30);
        $plot = Plot::new($data, 8, 12);
        $rows = self::plainRows($plot->render());

        $xStart = $rows[count($rows) - 2];
        $xEnd = $rows[count($rows) - 1];
        $this->assertMatchesRegularExpression('/^\s*[1-9]\d*\s*$/u', $xStart, 'window start index, not the old hardcoded 0');
        $this->assertMatchesRegularExpression('/^\s*29\s*$/u', $xEnd, 'last painted index');

        // Monotonic ascending data with 6 slots: the old overflow piled
        // samples 0..29 into the final dot column (min(offset*2, dotWidth-1)
        // with no window), smearing a near-full vertical bar at the right
        // edge. The windowed walk paints only the last six samples, so the
        // final cell column carries at most a handful of rows.
        $canvasRows = array_slice($rows, 0, count($rows) - 2);
        $lastColPainted = 0;
        foreach ($canvasRows as $row) {
            $ch = mb_substr(rtrim($row), -1);
            if ($ch !== '' && preg_match('/[\x{2800}-\x{28FF}]/u', $ch)) {
                $lastColPainted++;
            }
        }
        $this->assertGreaterThan(0, $lastColPainted, 'the window tail is painted');
        $this->assertLessThanOrEqual(3, $lastColPainted, 'overflow samples do not smear the final column');
    }

    public function testDrawIntoBufferMatchesRenderLineForLine(): void
    {
        $plot = Plot::new([1.0, 4.0, 2.0, 9.0, 7.0, 3.0], 40, 8);

        $buffer = Buffer::new(40, 8);
        $plot->draw($buffer);

        $renderRows = self::plainRows($plot->render());
        $bufferRows = self::plainRows($buffer->render());

        $this->assertCount(count($renderRows), $bufferRows);
        foreach ($renderRows as $i => $row) {
            $this->assertSame(rtrim($row), rtrim($bufferRows[$i]), "line {$i} agrees between draw() and render()");
        }
    }

    public function testMaxValueReachesTopCanvasRow(): void
    {
        $plot = Plot::new([0.0, 100.0], 10, 6)
            ->withMinValue(0.0)
            ->withMaxValue(100.0)
            ->withShowAxes(false);
        $rows = self::plainRows($plot->render());

        $this->assertMatchesRegularExpression('/[\x{2800}-\x{28FF}]/u', $rows[0], 'the maximum paints dots on the top row');
        $this->assertMatchesRegularExpression('/[\x{2800}-\x{28FF}]/u', $rows[count($rows) - 1], 'the minimum paints dots on the bottom row');
    }

    public function testAxisLabelFormatterIsWiredAndTheDuplicateIsGone(): void
    {
        $ref = new \ReflectionClass(Plot::class);
        $this->assertFalse($ref->hasMethod('formatLabel'), 'the private copy of the formatter is deleted');
        $this->assertTrue($ref->hasMethod('formatYLabel'), 'the shared AxisLabelFormatter trait does the labelling');
    }

    /**
     * @return list<string>
     */
    private static function plainRows(string $block): array
    {
        return explode("\n", (string) preg_replace('/\x1b\[[0-9;]*m/', '', $block));
    }
}
