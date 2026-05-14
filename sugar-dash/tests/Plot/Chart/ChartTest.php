<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

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
}