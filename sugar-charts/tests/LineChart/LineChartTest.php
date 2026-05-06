<?php

declare(strict_types=1);

namespace CandyCore\Charts\Tests\LineChart;

use CandyCore\Charts\LineChart\LineChart;
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
}
