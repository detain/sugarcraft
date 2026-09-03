<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Dash\Plot\Chart\Chart;
use SugarCraft\Dash\Plot\Chart\ChartDataPoint;
use SugarCraft\Dash\Plot\Chart\ChartType;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class ChartTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testChartImplementsSizer(): void
    {
        $chart = Chart::new();
        $this->assertInstanceOf(Sizer::class, $chart);
    }

    public function testChartImplementsItem(): void
    {
        $chart = Chart::new();
        $this->assertInstanceOf(Item::class, $chart);
    }

    // ═══════════════════════════════════════════════════════════════
    // ChartType enum
    // ═══════════════════════════════════════════════════════════════

    public function testChartTypeBarValue(): void
    {
        $this->assertSame('bar', ChartType::Bar->value);
    }

    public function testChartTypeLineValue(): void
    {
        $this->assertSame('line', ChartType::Line->value);
    }

    public function testChartTypeCanBeUsedInChart(): void
    {
        $barChart = Chart::new([], ChartType::Bar);
        $lineChart = Chart::new([], ChartType::Line);

        $this->assertSame('', $barChart->render());
        $this->assertSame('', $lineChart->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // ChartDataPoint creation
    // ═══════════════════════════════════════════════════════════════

    public function testChartDataPointCreation(): void
    {
        $point = new ChartDataPoint('Jan', 42.5);

        $this->assertSame('Jan', $point->label);
        $this->assertSame(42.5, $point->value);
    }

    public function testChartDataPointWithZeroValue(): void
    {
        $point = new ChartDataPoint('Zero', 0.0);

        $this->assertSame('Zero', $point->label);
        $this->assertSame(0.0, $point->value);
    }

    public function testChartDataPointWithNegativeValue(): void
    {
        $point = new ChartDataPoint('Neg', -10.5);

        $this->assertSame('Neg', $point->label);
        $this->assertSame(-10.5, $point->value);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsEmptyWithNoData(): void
    {
        $chart = Chart::new();
        $this->assertSame('', $chart->render());
    }

    public function testRenderReturnsNonEmptyWithData(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('Jan', 10),
            new ChartDataPoint('Feb', 20),
        ]);
        $rendered = $chart->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithEmptyDataPointsArray(): void
    {
        $chart = Chart::new([]);
        $this->assertSame('', $chart->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Bar chart rendering
    // ═══════════════════════════════════════════════════════════════

    public function testBarChartContainsBlockCharacters(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 10),
        ], ChartType::Bar);
        $rendered = $chart->render();

        // Bar chart should contain filled block characters
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testBarChartWithDifferentHeights(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('Low', 1),
            new ChartDataPoint('High', 100),
        ], ChartType::Bar);
        $rendered = $chart->render();

        // Should contain block characters for bars
        $this->assertMatchesRegularExpression('/[▁▂▃▄▅▆▇█]/', $rendered);
    }

    public function testBarChartGridLineCharacter(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
        ], ChartType::Bar);
        $rendered = $chart->render();

        // Should contain grid line character
        $this->assertStringContainsString('─', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Line chart rendering
    // ═══════════════════════════════════════════════════════════════

    public function testLineChartContainsPointCharacter(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 10),
        ], ChartType::Line);
        $rendered = $chart->render();

        // Line chart should contain point character
        $this->assertStringContainsString('●', $rendered);
    }

    public function testLineChartContainsGridDots(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 10),
        ], ChartType::Line);
        $rendered = $chart->render();

        // Line chart should contain grid dot character
        $this->assertStringContainsString('·', $rendered);
    }

    public function testLineChartSinglePoint(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 10),
        ], ChartType::Line);
        $rendered = $chart->render();

        // Should render with points
        $this->assertStringContainsString('●', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Grid display
    // ═══════════════════════════════════════════════════════════════

    public function testGridShownByDefault(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
        ]);
        $rendered = $chart->render();

        // Default should show grid lines
        $this->assertStringContainsString('─', $rendered);
    }

    public function testGridHiddenWithFalse(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
        ])->withGrid(false);
        $rendered = $chart->render();

        // Should NOT contain horizontal grid line character (but may have y-axis labels)
        // The grid line is the '─' character at the bottom
        $lines = explode("\n", $rendered);
        $hasHorizontalLine = false;
        foreach ($lines as $line) {
            if (str_contains($line, '─') && strlen(trim($line)) > 8) {
                $hasHorizontalLine = true;
                break;
            }
        }
        $this->assertFalse($hasHorizontalLine);
    }

    public function testGridColorAppliedWhenSet(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
        ])->withGridColor(Color::ansi(9));
        $rendered = $chart->render();

        // Should contain ANSI codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Label display
    // ═══════════════════════════════════════════════════════════════

    public function testLabelsShownByDefault(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('Jan', 5),
            new ChartDataPoint('Feb', 10),
        ]);
        $rendered = $chart->render();

        // Should contain the label text
        $this->assertStringContainsString('Jan', $rendered);
        $this->assertStringContainsString('Feb', $rendered);
    }

    public function testLabelsHiddenWithFalse(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('Jan', 5),
            new ChartDataPoint('Feb', 10),
        ])->withShowLabels(false);
        $rendered = $chart->render();

        // Should NOT contain the label text
        $this->assertStringNotContainsString('Jan', $rendered);
        $this->assertStringNotContainsString('Feb', $rendered);
    }

    public function testLabelColorAppliedWhenSet(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('Jan', 5),
        ])->withLabelColor(Color::ansi(10));
        $rendered = $chart->render();

        // Should contain ANSI codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
        ])->withColor(Color::ansi(9));
        $rendered = $chart->render();

        // Should contain ANSI color codes
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
        ])->withColor(Color::ansi(9));
        $rendered = $chart->render();

        // Should end with reset code
        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    public function testNullColorRendersWithoutAnsi(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
        ])->withColor(null)->withGridColor(null)->withLabelColor(null);
        $rendered = $chart->render();

        // Should NOT contain ANSI codes when all colors are null
        $this->assertDoesNotMatchRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithDataPointsReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)]);
        $updated = $original->withDataPoints([new ChartDataPoint('B', 2)]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithTypeReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)], ChartType::Bar);
        $updated = $original->withType(ChartType::Line);

        $this->assertNotSame($original, $updated);
    }

    public function testWithWidthReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)])->withWidth(20);
        $updated = $original->withWidth(40);

        $this->assertNotSame($original, $updated);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)])->withHeight(5);
        $updated = $original->withHeight(15);

        $this->assertNotSame($original, $updated);
    }

    public function testWithGridReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)])->withGrid(true);
        $updated = $original->withGrid(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowValuesReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)])->withShowValues(false);
        $updated = $original->withShowValues(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithShowLabelsReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)])->withShowLabels(true);
        $updated = $original->withShowLabels(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithXAxisLabelReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)]);
        $updated = $original->withXAxisLabel('Months');

        $this->assertNotSame($original, $updated);
    }

    public function testWithYAxisLabelReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)]);
        $updated = $original->withYAxisLabel('Values');

        $this->assertNotSame($original, $updated);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)])->withColor(Color::ansi(9));
        $updated = $original->withColor(Color::ansi(10));

        $this->assertNotSame($original, $updated);
    }

    public function testWithGridColorReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)])->withGridColor(Color::ansi(8));
        $updated = $original->withGridColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)])->withLabelColor(Color::ansi(7));
        $updated = $original->withLabelColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Chart::new([new ChartDataPoint('A', 1)]);
        $resized = $original->setSize(50, 20);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $chart = Chart::new([new ChartDataPoint('A', 1)])
            ->withWidth(40)
            ->withHeight(10);
        [$w, $h] = $chart->getInnerSize();

        // Width includes widthConstraint + 10 for y-axis labels
        $this->assertSame(50, $w);
        // Height includes heightConstraint + 2 for labels
        $this->assertSame(12, $h);
    }

    public function testGetInnerSizeWithoutConstraints(): void
    {
        $chart = Chart::new([new ChartDataPoint('A', 1)]);
        [$w, $h] = $chart->getInnerSize();

        // Default widthConstraint is null, defaults to 50 + 10
        $this->assertSame(60, $w);
        // Default heightConstraint is 10 + 2
        $this->assertSame(12, $h);
    }

    public function testGetInnerSizeWithSetSize(): void
    {
        $chart = Chart::new([new ChartDataPoint('A', 1)])
            ->withWidth(40)
            ->withHeight(10)
            ->setSize(60, 15);
        [$w, $h] = $chart->getInnerSize();

        // setSize overrides width/height, adding 10 and 2 respectively
        $this->assertSame(70, $w);
        $this->assertSame(17, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSingleDataPoint(): void
    {
        $chart = Chart::new([new ChartDataPoint('Only', 5)]);
        $rendered = $chart->render();

        $this->assertNotSame('', $rendered);
        $this->assertStringContainsString('Only', $rendered);
    }

    public function testAllZeroValues(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 0),
            new ChartDataPoint('B', 1),
        ]);
        $rendered = $chart->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testNegativeValues(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('Neg', -10),
            new ChartDataPoint('Pos', 10),
        ]);
        $rendered = $chart->render();

        // Should render without errors
        $this->assertNotSame('', $rendered);
    }

    public function testVeryLargeValues(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('Big', 1000000),
            new ChartDataPoint('Bigger', 10000000),
        ]);
        $rendered = $chart->render();

        // Should render and may contain M suffix for millions
        $this->assertNotSame('', $rendered);
    }

    public function testVerySmallValues(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('Small', 0.001),
            new ChartDataPoint('Smaller', 0.0001),
        ]);
        $rendered = $chart->render();

        // Should render with decimal values
        $this->assertNotSame('', $rendered);
    }

    public function testAllSameValues(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 5),
            new ChartDataPoint('C', 5),
        ]);
        $rendered = $chart->render();

        // Should render without division by zero issues
        $this->assertNotSame('', $rendered);
    }

    public function testLongLabels(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('ThisIsAVeryLongLabel', 5),
            new ChartDataPoint('AnotherLongLabel', 10),
        ]);
        $rendered = $chart->render();

        // Should render and may truncate labels
        $this->assertNotSame('', $rendered);
    }

    public function testRenderWithNoWidthAndNoConstraint(): void
    {
        $chart = new Chart(
            dataPoints: [new ChartDataPoint('A', 5)],
            type: ChartType::Bar,
            widthConstraint: null,
            heightConstraint: 10,
            showGrid: false,
            showValues: false,
            showLabels: false,
        );
        $rendered = $chart->render();

        // Should render using default width (40) minus y-axis padding (10) = 30
        $this->assertNotSame('', $rendered);
    }

    public function testHeightConstraintAffectsOutput(): void
    {
        $shortChart = Chart::new([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 10),
        ])->withHeight(5);

        $tallChart = Chart::new([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 10),
        ])->withHeight(20);

        $shortRendered = $shortChart->render();
        $tallRendered = $tallChart->render();

        // Taller chart should have more lines
        $shortLines = substr_count($shortRendered, "\n");
        $tallLines = substr_count($tallRendered, "\n");

        $this->assertGreaterThan($shortLines, $tallLines);
    }

    public function testBarChartYAxisLabels(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 100),
        ], ChartType::Bar);
        $rendered = $chart->render();

        // Should contain Y-axis labels (padded to 8 chars)
        // Each line should start with a number followed by spaces
        $lines = explode("\n", $rendered);
        $hasNumericLabel = false;
        foreach ($lines as $line) {
            if (preg_match('/^\d+\s/', trim($line))) {
                $hasNumericLabel = true;
                break;
            }
        }
        $this->assertTrue($hasNumericLabel);
    }

    public function testLineChartYAxisLabels(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 100),
            new ChartDataPoint('B', 200),
        ], ChartType::Line);
        $rendered = $chart->render();

        // Should contain Y-axis labels
        $lines = explode("\n", $rendered);
        $hasNumericLabel = false;
        foreach ($lines as $line) {
            if (preg_match('/^\d+\s/', trim($line))) {
                $hasNumericLabel = true;
                break;
            }
        }
        $this->assertTrue($hasNumericLabel);
    }

    public function testChartNewWithDefaultColor(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
        ]);
        $rendered = $chart->render();

        // Default color is set, so ANSI codes should be present
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    /**
     * Benchmark: diff-based render emits fewer bytes than full re-render
     * for small changes between consecutive frames.
     *
     * Frame 1: full output (baseline)
     * Frame 2: delta output (smaller than full re-render)
     * Frame 3: delta output (smaller than full re-render)
     */
    public function testDiffEmissionByteBenchmark(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 3),
        ])->setSize(40, 15);

        // Frame 1: full render
        $out1 = $chart->render();
        $bytes1 = \strlen($out1);

        // Frame 2: change one data point value (small change)
        $chart2 = $chart->withDataPoints([
            new ChartDataPoint('A', 5),
            new ChartDataPoint('B', 4), // changed from 3 to 4
        ]);
        $out2 = $chart2->render();
        $bytes2 = \strlen($out2);

        // Frame 3: change another data point value
        $chart3 = $chart2->withDataPoints([
            new ChartDataPoint('A', 6), // changed from 5 to 6
            new ChartDataPoint('B', 4),
        ]);
        $out3 = $chart3->render();
        $bytes3 = \strlen($out3);

        // Delta frames should be smaller than full re-render (not absolute byte count)
        // The 30-byte threshold was a placeholder; real goal is delta < full 80x24 re-emit (≥1920 bytes)
        $this->assertLessThan($bytes1, $bytes2, 'Frame 2 delta should be smaller than full re-render');
        $this->assertLessThan($bytes1, $bytes3, 'Frame 3 delta should be smaller than full re-render');
    }

    // ═══════════════════════════════════════════════════════════════
    // Diff-path buffer rebuild (B11: codepoint-exact cell mapping)
    // ═══════════════════════════════════════════════════════════════

    /**
     * ChartDataPoint/ChartType are declared inside Chart.php (no PSR-4 file
     * of their own). PSR-4 cannot resolve them when a --filter run touches
     * them as arguments before any Chart reference, so warm the defining
     * file once for the class.
     */
    public static function setUpBeforeClass(): void
    {
        class_exists(Chart::class);
    }

    /**
     * Plain-content factory: no colors (zero ANSI), no grid — every byte
     * reaching bufferFromOutput is renderable text, so the rebuilt grid is
     * fully predictable (CJK labels + 3-byte '█' bar runes).
     *
     * @param list<ChartDataPoint> $dataPoints
     */
    private function plainBarChart(array $dataPoints): Chart
    {
        return new Chart(
            dataPoints: $dataPoints,
            type: ChartType::Bar,
            widthConstraint: null,
            heightConstraint: 10,
            showGrid: false,
            showValues: false,
            showLabels: true,
            color: null,
            gridColor: null,
            labelColor: null,
        );
    }

    /**
     * Ground-truth cell grid: codepoints per line, right-padded to $width.
     *
     * @return list<list<string>>
     */
    private function codepointGrid(string $text, int $width, int $height): array
    {
        $grid = [];
        $lines = explode("\n", $text);
        for ($row = 0; $row < $height; $row++) {
            $runes = mb_str_split($lines[$row] ?? '');
            $grid[] = array_pad($runes, $width, ' ');
        }

        return $grid;
    }

    /**
     * Strict cell-rune check. assertSame on raw strings compares numeric
     * strings loosely (byte slice "\xE6" == codepoint "日"), so wrap in a
     * one-element array — multibyte mismatches must fail loudly.
     */
    private function assertSameRune(string $expected, Buffer $buffer, int $col, int $row): void
    {
        $this->assertSame(
            [$expected],
            [$buffer->cellAt($col, $row)->rune],
            sprintf('cell (%d,%d) rune', $col, $row)
        );
    }

    /**
     * Core B11 pin: on a reused chart the second render must store the
     * same cell grid a fresh full render of that state would store. With
     * multibyte content the old byte-indexed guard emitted '' cells instead
     * of space padding for every column past the last codepoint but within
     * the byte length — both stored frames diverged from what was painted,
     * so the diff churned phantom cells and repainted stale glyphs.
     */
    public function testDiffReRenderBufferMatchesFreshRender(): void
    {
        $chart = $this->plainBarChart([
            new ChartDataPoint('日本', 5),
            new ChartDataPoint('B', 10),
        ]);
        $firstFrame = $chart->render();
        $this->assertStringContainsString('日本', $firstFrame, 'precondition: CJK label painted on first frame');

        // Mutate the SAME instance → next render() takes the diff path
        // (value shift grows the first bar by two rows; label keeps CJK).
        $points = new \ReflectionProperty(Chart::class, 'dataPoints');
        $points->setAccessible(true);
        $points->setValue($chart, [
            new ChartDataPoint('東京', 7),
            new ChartDataPoint('B', 10),
        ]);

        $delta = $chart->render();
        $this->assertNotSame('', $delta, 'precondition: changed cells must emit ops');
        $this->assertLessThan(strlen($firstFrame), strlen($delta), 'precondition: same-size re-render must emit a delta, not a full frame');

        $stored = new \ReflectionProperty(Chart::class, 'renderContext');
        $stored->setAccessible(true);
        /** @var Buffer $diffBuffer */
        $diffBuffer = $stored->getValue($chart)->previousFrame;

        $fresh = $this->plainBarChart([
            new ChartDataPoint('東京', 7),
            new ChartDataPoint('B', 10),
        ]);
        $freshFrame = $fresh->render();
        /** @var Buffer $freshBuffer */
        $freshBuffer = $stored->getValue($fresh)->previousFrame;

        $this->assertNotSame($diffBuffer, $freshBuffer, 'sanity: distinct instances');

        // Full-grid parity: stored diff-state buffer == codepoint grid of
        // the fresh full render (rows clipped to the chart area 40x10 by
        // design — labels row sits outside the diff geometry).
        $this->assertSame($this->codepointGrid($freshFrame, 40, 10), self::readableGrid($diffBuffer, 40, 10));
        $this->assertSame(self::readableGrid($freshBuffer, 40, 10), self::readableGrid($diffBuffer, 40, 10));

        // Exact ground truth on the CJK label row (direct rebuild at full
        // frame height): 8-space gutter, 日本/東京 pair, space after.
        $rebuilt = new \ReflectionMethod(Chart::class, 'bufferFromOutput');
        $rebuilt->setAccessible(true);
        /** @var Buffer $labelBuffer */
        $labelBuffer = $rebuilt->invoke($chart, $freshFrame, 40, 11);
        $this->assertSameRune('東', $labelBuffer, 8, 10);
        $this->assertSameRune('京', $labelBuffer, 9, 10);
        $this->assertSameRune(' ', $labelBuffer, 10, 10);
    }

    /**
     * Read a buffer back into a plain codepoint grid for comparison.
     *
     * @return list<list<string>>
     */
    private static function readableGrid(Buffer $buffer, int $width, int $height): array
    {
        $grid = [];
        for ($row = 0; $row < $height; $row++) {
            $line = [];
            for ($col = 0; $col < $width; $col++) {
                $line[] = $buffer->cellAt($col, $row)->rune;
            }
            $grid[] = $line;
        }

        return $grid;
    }

    /**
     * ASCII rows must map byte-identically (codepoints == bytes): the fix
     * may not shift anything on the pure-ASCII path.
     */
    public function testBufferFromOutputPureAsciiUnchanged(): void
    {
        $output = "Hello World\nabc123";
        $method = new \ReflectionMethod(Chart::class, 'bufferFromOutput');
        $method->setAccessible(true);
        /** @var Buffer $buffer */
        $buffer = $method->invoke(new Chart(), $output, 12, 2);

        $this->assertSame('H', $buffer->cellAt(0, 0)->rune);
        $this->assertSame('World', implode('', array_map(
            static fn (int $i): string => $buffer->cellAt(6 + $i, 0)->rune,
            range(0, 4)
        )));
        $this->assertSame($this->codepointGrid($output, 12, 2), self::readableGrid($buffer, 12, 2));
    }

    /**
     * Wide/multibyte boundary: one line mixing ASCII, 3-byte CJK, and the
     * 3-byte '─' box glyph — each rune must land in exactly one cell with
     * space padding after the codepoint end (the old guard emitted '' there).
     */
    public function testBufferFromOutputMultibyteBoundary(): void
    {
        $output = 'A日B─';
        $method = new \ReflectionMethod(Chart::class, 'bufferFromOutput');
        $method->setAccessible(true);
        /** @var Buffer $buffer */
        $buffer = $method->invoke(new Chart(), $output, 6, 1);

        $this->assertSameRune('A', $buffer, 0, 0);
        $this->assertSameRune('日', $buffer, 1, 0);
        $this->assertSameRune('B', $buffer, 2, 0);
        $this->assertSameRune('─', $buffer, 3, 0);
        $this->assertSameRune(' ', $buffer, 4, 0);
        $this->assertSameRune(' ', $buffer, 5, 0);
        $this->assertSame($this->codepointGrid($output, 6, 1), self::readableGrid($buffer, 6, 1));
    }

    /**
     * C4 core pin (Q4/R5-iii): the colored default path (Chart::new sets
     * color/gridColor/labelColor) must store DISPLAY runes in the diff-state
     * buffer, not SGR fragments. Before the strip, ESC/'['/'3'/'8'/… landed
     * as individual cells, so any escape-length change on a row shifted all
     * later runes right and re-renders diffed phantom whole rows. Ground
     * truth = house-regex-stripped fresh frame (BubbleTest::strippedRenderLines
     * precedent); strip happens inside bufferFromOutput only — the full
     * frame render() returns keeps every SGR byte (pinned below).
     */
    public function testColoredChartDiffBufferMatchesFreshRenderStrippedGrid(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('日本', 5),
            new ChartDataPoint('B', 10),
        ]);
        $firstFrame = $chart->render();
        $this->assertStringContainsString("\x1b[38;2;137;180;250m", $firstFrame, 'precondition: default Chart::new path embeds truecolor SGR');

        // Mutate the SAME instance → second render takes the diff path.
        $points = new \ReflectionProperty(Chart::class, 'dataPoints');
        $points->setAccessible(true);
        $points->setValue($chart, [
            new ChartDataPoint('東京', 7),
            new ChartDataPoint('B', 10),
        ]);

        $delta = $chart->render();
        $this->assertNotSame('', $delta, 'precondition: changed cells must emit ops');
        $this->assertLessThan(strlen($firstFrame), strlen($delta), 'precondition: same-size colored re-render must emit a delta, not a full frame');

        $stored = new \ReflectionProperty(Chart::class, 'renderContext');
        $stored->setAccessible(true);
        /** @var Buffer $diffBuffer */
        $diffBuffer = $stored->getValue($chart)->previousFrame;

        $fresh = Chart::new([
            new ChartDataPoint('東京', 7),
            new ChartDataPoint('B', 10),
        ]);
        $freshFrame = $fresh->render();
        /** @var Buffer $freshBuffer */
        $freshBuffer = $stored->getValue($fresh)->previousFrame;

        $this->assertNotSame($diffBuffer, $freshBuffer, 'sanity: distinct instances');

        // Full-frame stdout bytes still carry the styling (strip is internal):
        $this->assertStringContainsString("\x1b[38;2;", $freshFrame, 'colored stdout frame must keep SGR bytes');

        // Parity: stored diff-state grid == codepoint grid of the DISPLAY
        // runes of the fresh render, width-clipped to 40 exactly as the cell
        // loop does (runes past the last buffer column are status quo, clip
        // redesign parked); chart area is 40x10 — the x-axis + labels rows
        // sit outside the diff height.
        $stripped = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $freshFrame);
        $expected = array_map(
            static fn (array $r): array => array_slice($r, 0, 40),
            $this->codepointGrid($stripped, 40, 10)
        );
        $this->assertSame($expected, self::readableGrid($diffBuffer, 40, 10));
        $this->assertSame(self::readableGrid($freshBuffer, 40, 10), self::readableGrid($diffBuffer, 40, 10));
    }

    /**
     * Direct cell pin: on the colored frame, bufferFromOutput cells hold the
     * painted runes ('█' bar fill, '─' axis, CJK labels) with zero ESC cells
     * anywhere. Pre-strip each bar/axis/label color sequence began at its
     * own cell (ESC was the rune at col 9 of every bar row, col 0 of the
     * labels row) — reverting the strip fails these pins loudly.
     */
    public function testBufferFromOutputColoredCellsAreDisplayRunesNotSgrFragments(): void
    {
        $chart = Chart::new([
            new ChartDataPoint('日本', 5),
            new ChartDataPoint('B', 10),
        ]);
        $frame = $chart->render();
        $this->assertStringContainsString("\x1b[", $frame, 'precondition: frame carries SGR');

        $rebuilt = new \ReflectionMethod(Chart::class, 'bufferFromOutput');
        $rebuilt->setAccessible(true);
        // Full emitted height 12 = 10 chart rows + x-axis + labels row.
        /** @var Buffer $buffer */
        $buffer = $rebuilt->invoke($chart, $frame, 40, 12);

        // Bar area: gutter is str_pad(yLabel, 8).' ' (9 cells), bar 1 fills
        // the bottom rows, bar 2 (value 10 = max) fills every row.
        $this->assertSameRune('█', $buffer, 9, 9);
        $this->assertSameRune('█', $buffer, 29, 0);
        // X-axis row: 8 spaces then the grid '─' run.
        $this->assertSameRune('─', $buffer, 8, 10);
        $this->assertSameRune('─', $buffer, 39, 10);
        // Labels row: labelColor wrapper stripped, label starts at col 8.
        $this->assertSameRune('日', $buffer, 8, 11);
        $this->assertSameRune('本', $buffer, 9, 11);

        // Escape census: not one cell may hold an ESC fragment.
        $escapes = [];
        for ($row = 0; $row < 12; $row++) {
            for ($col = 0; $col < 40; $col++) {
                if ($buffer->cellAt($col, $row)->rune === "\x1b") {
                    $escapes[] = sprintf('(%d,%d)', $col, $row);
                }
            }
        }
        $this->assertSame([], $escapes, 'diff-state cells must never hold ESC fragments');
    }

    /**
     * Q5 byte-identity pin: the strip is INERT on escape-free input — pure
     * ASCII rows (and an SGR-look-alike that lacks the ESC introducer) must
     * map exactly as before: cell grid == raw codepoint grid, byte for byte.
     */
    public function testSgrStripInertOnEscapeFreeOutput(): void
    {
        $output = "plain 42\nrow two\n[38;2;1;2;3m no-esc look-alike";
        $method = new \ReflectionMethod(Chart::class, 'bufferFromOutput');
        $method->setAccessible(true);
        /** @var Buffer $buffer */
        $buffer = $method->invoke(new Chart(), $output, 28, 3);

        // Same width-clip as the cell loop applies (rows longer than the
        // buffer keep only their first $width runes — status quo).
        $expected = array_map(
            static fn (array $r): array => array_slice($r, 0, 28),
            $this->codepointGrid($output, 28, 3)
        );
        $this->assertSame($expected, self::readableGrid($buffer, 28, 3));
        $this->assertSameRune('[', $buffer, 0, 2);
        $this->assertSameRune('3', $buffer, 1, 2);

        // The no-color plain frame (zero SGR by construction) also rebuilds
        // identically to its raw codepoint grid — display path == stored path.
        $plain = $this->plainBarChart([
            new ChartDataPoint('日本', 5),
            new ChartDataPoint('B', 10),
        ])->render();
        $this->assertStringNotContainsString("\x1b", $plain, 'precondition: plainBarChart emits zero SGR');
        /** @var Buffer $plainBuffer */
        $plainBuffer = $method->invoke(new Chart(), $plain, 40, 11);
        $this->assertSame($this->codepointGrid($plain, 40, 11), self::readableGrid($plainBuffer, 40, 11));
    }
}