<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use SugarCraft\Charts\Chart\Position;
use SugarCraft\Charts\LineChart\LineChart;
use PHPUnit\Framework\TestCase;

final class LineChartTest extends TestCase
{
    public function testZeroSizeIsEmpty(): void
    {
        $this->assertSame('', LineChart::new([1, 2, 3], 0, 0)->view());
    }

    public function testSinglePointPlotted(): void
    {
        $out = LineChart::new([5], 5, 3)->view();
        // A single sample plots at column 0.
        $rows = explode("\n", $out);
        $this->assertSame('*', substr($rows[count($rows) - 1], 0, 1));
    }

    public function testMonotonicSeriesRisesFromBottomLeft(): void
    {
        $out = LineChart::new([1, 2, 3, 4, 5, 6, 7, 8], 8, 4)->view();
        $rows = explode("\n", $out);
        $this->assertCount(4, $rows);
        // First column is the minimum (bottom row), last column is the max
        // (top row). With ASCII renderer, '*' should appear at (0, last)
        // and (width-1, 0).
        $this->assertSame('*', substr($rows[3], 0, 1));
        $top = $rows[0];
        $this->assertNotFalse(strpos($top, '*'));
    }

    public function testCustomPointRune(): void
    {
        $out = LineChart::new([1, 2], 4, 2)->withPoint('o')->view();
        $this->assertStringContainsString('o', $out);
        $this->assertStringNotContainsString('*', $out);
    }

    public function testNegativeSizeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        LineChart::new([], -1, 5);
    }

    public function testFlatSeriesRendersHorizontalLine(): void
    {
        // Width=7 with 4 evenly-spaced points → cols 0, 2, 4, 6.
        // The intermediate cells (1, 3, 5) get '-' connectors.
        $out  = LineChart::new([3, 3, 3, 3], 7, 2)->view();
        $rows = explode("\n", $out);
        $this->assertSame('*-*-*-*', rtrim($rows[1]));
    }

    public function testWithAxesEmitsAxisRunes(): void
    {
        $out = LineChart::new([1, 5, 3, 7, 4], 20, 8)
            ->withAxes()
            ->withYLabels(['10', '5', '0'])
            ->withXLabels(['t0', 't4'])
            ->view();
        $this->assertStringContainsString('└', $out);
        $this->assertStringContainsString('─', $out);
        $this->assertStringContainsString('│', $out);
        $this->assertStringContainsString('10', $out);
    }

    public function testWithDatasetRendersMultipleSeries(): void
    {
        $out = LineChart::new([1, 5, 3, 7, 4], 20, 6)
            ->withDataset('compare', [4, 2, 6, 1, 8])
            ->withDatasetPoint('compare', 'o')
            ->view();
        // Both default '*' and dataset 'o' appear somewhere in output.
        $this->assertStringContainsString('*', $out);
        $this->assertStringContainsString('o', $out);
    }

    public function testWithYRangeShorthand(): void
    {
        $chart = LineChart::new([5, 5, 5])->withYRange(0.0, 10.0);
        $this->assertSame(0.0, $chart->min);
        $this->assertSame(10.0, $chart->max);
    }

    public function testWithXRangeStoresBothEnds(): void
    {
        $chart = LineChart::new([1, 2, 3])->withXRange(0.0, 4.0);
        $this->assertSame(0.0, $chart->xMin);
        $this->assertSame(4.0, $chart->xMax);
    }

    public function testWithXYRangeAggregate(): void
    {
        $chart = LineChart::new([1, 2, 3])->withXYRange(0.0, 5.0, -1.0, 9.0);
        $this->assertSame(0.0,  $chart->xMin);
        $this->assertSame(5.0,  $chart->xMax);
        $this->assertSame(-1.0, $chart->min);
        $this->assertSame(9.0,  $chart->max);
    }

    public function testAutoAdjustRangeResetsAllRangeFields(): void
    {
        $chart = LineChart::new([1, 2, 3])
            ->withYRange(0.0, 10.0)
            ->withXRange(0.0, 4.0)
            ->autoAdjustRange();
        $this->assertNull($chart->min);
        $this->assertNull($chart->max);
        $this->assertNull($chart->xMin);
        $this->assertNull($chart->xMax);
    }

    public function testYLabelFormatterRendersTicks(): void
    {
        $chart = LineChart::new([0, 10, 5], 30, 8)
            ->withAxes()
            ->withYLabelFormatter(static fn (float $v) => number_format($v, 1), 3)
            ->withYRange(0.0, 10.0);
        $out = $chart->view();
        $this->assertStringContainsString('10.0', $out);
        $this->assertStringContainsString('0.0',  $out);
    }

    public function testXLabelFormatterRendersTicks(): void
    {
        $chart = LineChart::new([1, 2, 3, 4], 30, 8)
            ->withAxes()
            ->withXLabelFormatter(static fn (float $v) => 't' . (int) $v, 2)
            ->withXRange(0.0, 4.0);
        $out = $chart->view();
        $this->assertStringContainsString('t0', $out);
        $this->assertStringContainsString('t4', $out);
    }

    // ─── Legend Tests ──────────────────────────────────────────────────

    public function testWithLegendEnablesLegend(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withLegend(true);
        $out = $chart->view();
        // Legend should contain the dataset label
        $this->assertStringContainsString('Series A', $out);
    }

    public function testWithLegendFalseDisablesLegend(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Should Not Appear', [3, 2, 1])
            ->withLegend(false);
        $out = $chart->view();
        // Legend should not appear when disabled
        $this->assertStringNotContainsString('Should Not Appear', $out);
    }

    public function testWithDatasetAutoAddsLegendItems(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withDataset('Series B', [1, 2, 3])
            ->withLegend(true);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('Series B', $out);
    }

    public function testWithLegendPositionBottom(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withLegend(true)
            ->withLegendPosition(Position::Bottom);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
    }

    public function testWithLegendPositionRight(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withLegend(true)
            ->withLegendPosition(Position::Right);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
    }

    public function testWithLegendPositionTop(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withLegend(true)
            ->withLegendPosition(Position::Top);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
    }

    public function testWithLegendPositionLeft(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withLegend(true)
            ->withLegendPosition(Position::Left);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
    }

    public function testLegendShortFormAlias(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->legend(true)
            ->legendPos(Position::Bottom);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
    }

    public function testMultipleDatasetsWithCyclingColors(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withDataset('Series B', [1, 2, 3])
            ->withDataset('Series C', [2, 3, 1])
            ->withLegend(true);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('Series B', $out);
        $this->assertStringContainsString('Series C', $out);
    }

    public function testFullLegendExampleFromDocumentation(): void
    {
        $dataA = [1, 3, 2, 4, 3, 5];
        $dataB = [5, 2, 4, 1, 3, 2];
        $chart = LineChart::new()
            ->withDataset('Series A', $dataA)
            ->withDataset('Series B', $dataB)
            ->withLegend(true)
            ->withLegendPosition(Position::Right);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('Series B', $out);
    }

    public function testWithSizePreservesLegend(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withLegend(true)
            ->withSize(30, 8);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
    }

    public function testWithAxesPreservesLegend(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 8)
            ->withDataset('Series A', [3, 2, 1])
            ->withLegend(true)
            ->withAxes(true);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('└', $out);
    }

    public function testWithTitleAndLegend(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withLegend(true)
            ->withTitle('My Chart');
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('My Chart', $out);
    }

    public function testFluentChainingWithLegend(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 5)
            ->withDataset('Series A', [3, 2, 1])
            ->withDataset('Series B', [1, 2, 3])
            ->legend(true)
            ->legendPos(Position::Top)
            ->axes(true);
        $out = $chart->view();
        $this->assertStringContainsString('Series A', $out);
        $this->assertStringContainsString('Series B', $out);
    }

    // ─── push() Streaming Tests ───────────────────────────────────────────

    public function testPushAppendsValue(): void
    {
        $chart = LineChart::new([1, 2], 5, 4)->push(3.0);
        $this->assertSame([1, 2, 3.0], $chart->data);
    }

    public function testPushSlidesWindowWhenFull(): void
    {
        // Width=3, so window holds exactly 3 points.
        $chart = LineChart::new([1, 2], 3, 4)->push(3.0)->push(4.0);
        // Oldest point (1) should have been evicted.
        $this->assertSame([2, 3.0, 4.0], $chart->data);
    }

    public function testPushWindowOfOne(): void
    {
        $chart = LineChart::new([], 1, 4)->push(5.0)->push(6.0);
        // Only the newest value is kept.
        $this->assertSame([6.0], $chart->data);
    }

    // ─── withFill() Tests ───────────────────────────────────────────────

    public function testWithFillRendersAreaBelowCurve(): void
    {
        // When fill is on, the output should have more rune cells filled
        // vertically below each point compared to no-fill.
        $filled   = LineChart::new([1, 5, 2], 10, 5)->withFill(true)->view();
        $unfilled = LineChart::new([1, 5, 2], 10, 5)->withFill(false)->view();
        // Count non-space cells (filled output has more "filled" characters).
        $filledRunes   = preg_replace('/\s+/', '', $filled);
        $unfilledRunes = preg_replace('/\s+/', '', $unfilled);
        $this->assertGreaterThan(mb_strlen($unfilledRunes), mb_strlen($filledRunes));
    }

    public function testFillFalseRendersLineOnly(): void
    {
        $chart = LineChart::new([1, 2, 3], 10, 4)->withFill(false);
        $out = $chart->view();
        // Just verify it doesn't throw and produces output.
        $this->assertNotEmpty($out);
        $this->assertSame(false, $chart->fill);
    }

    public function testFillShortAlias(): void
    {
        $chart = LineChart::new([1, 2, 3], 10, 4)->fill(true);
        $this->assertSame(true, $chart->fill);
    }

    // ─── legendItems() Regression Test ──────────────────────────────────
    // Verifies that LineChart::legendItems() does NOT call Chart::copy()
    // which would fatal with ArgumentCountError (LineChart needs data/min/max/point).

    public function testLegendItemsAliasDoesNotFatalAndSetsItems(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 6)->legendItems([['label' => 'a', 'color' => 'red']]);

        // Assert it returns a LineChart (not a parent Chart from copy())
        $this->assertInstanceOf(LineChart::class, $chart);

        // Assert that withLegend()->view() renders without throwing
        $out = $chart->withLegend(true)->view();
        $this->assertNotEmpty($out);

        // Assert that legend label 'a' appears in output
        $this->assertStringContainsString('a', $out);
    }
}
