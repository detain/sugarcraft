<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\LineChart;

use SugarCraft\Charts\LineChart\LineChart;
use PHPUnit\Framework\TestCase;

final class LineChartAnimationTest extends TestCase
{
    public function testAnimationProgressZeroReturnsEmptyCanvas(): void
    {
        $chart = LineChart::new([1, 2, 3, 4, 5], 20, 6)
            ->withAnimationProgress(0.0);
        $out = $chart->view();
        // At progress 0, only an empty canvas is returned (just spaces/empty rows)
        $this->assertSame(6, substr_count($out, "\n") + 1);
    }

    public function testAnimationProgressPartialShowsSubset(): void
    {
        $data = [1, 2, 3, 4, 5];
        $chart = LineChart::new($data, 20, 6)
            ->withAnimationProgress(0.5);
        $out = $chart->view();
        $fullOut = LineChart::new($data, 20, 6)->view();
        // Partial should have fewer data points than full
        $partialStars = substr_count($out, '*');
        $fullStars = substr_count($fullOut, '*');
        $this->assertLessThan($fullStars, $partialStars);
        $this->assertGreaterThan(0, $partialStars);
    }

    public function testAnimationProgressFullShowsAllData(): void
    {
        $data = [1, 2, 3, 4, 5];
        $chart = LineChart::new($data, 20, 6)
            ->withAnimationProgress(1.0);
        $out = $chart->view();
        $fullOut = LineChart::new($data, 20, 6)->view();
        // Progress 1.0 should be identical to default (no animation)
        $this->assertSame($fullOut, $out);
    }

    public function testAnimationProgressClampingNegativeBehavesLikeZero(): void
    {
        $data = [1, 2, 3, 4, 5];
        $chart = LineChart::new($data, 20, 6)
            ->withAnimationProgress(-0.5);
        $out = $chart->view();
        // Negative should behave like 0 - no data points rendered
        $this->assertStringNotContainsString('*', $out);
    }

    public function testAnimationProgressClampingOverOneBehavesLikeFull(): void
    {
        $data = [1, 2, 3, 4, 5];
        $chart = LineChart::new($data, 20, 6)
            ->withAnimationProgress(1.5);
        $out = $chart->view();
        $fullOut = LineChart::new($data, 20, 6)->view();
        // > 1 should behave like 1.0 - full render
        $this->assertSame($fullOut, $out);
    }

    public function testAnimationDurationIsPreserved(): void
    {
        $chart = LineChart::new([1, 2, 3])
            ->withAnimationDuration(500)
            ->withAnimationProgress(0.5);
        $this->assertSame(500, $chart->getAnimationDuration());
        $this->assertSame(0.5, $chart->getAnimationProgress());
    }

    public function testAnimationDurationChainedPreservesDuration(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 6)
            ->withAnimationDuration(300)
            ->withAnimationProgress(0.5)
            ->withLegend(true)
            ->withAxes();
        $this->assertSame(300, $chart->getAnimationDuration());
        $this->assertSame(0.5, $chart->getAnimationProgress());
    }

    public function testAnimationProgressWithDatasets(): void
    {
        $data = [1, 2, 3, 4, 5];
        $chart = LineChart::new($data, 20, 6)
            ->withDataset('series2', [5, 4, 3, 2, 1])
            ->withAnimationProgress(0.5);
        $out = $chart->view();
        // Both series should appear but with fewer points than full render
        $fullChart = LineChart::new($data, 20, 6)
            ->withDataset('series2', [5, 4, 3, 2, 1]);
        $fullOut = $fullChart->view();
        $partialStars = substr_count($out, '*');
        $fullStars = substr_count($fullOut, '*');
        $this->assertLessThan($fullStars, $partialStars);
    }

    public function testAnimationProgressWithAxes(): void
    {
        $data = [1, 2, 3, 4, 5];
        $chart = LineChart::new($data, 20, 8)
            ->withAxes(true)
            ->withAnimationProgress(0.3);
        $out = $chart->view();
        // Axes should still render even at low progress
        $this->assertStringContainsString('└', $out);
        $this->assertStringContainsString('─', $out);
        $this->assertStringContainsString('│', $out);
    }

    public function testAnimationProgressWithYRangePreservesRange(): void
    {
        $data = [1, 2, 3, 4, 5];
        $chart = LineChart::new($data, 20, 6)
            ->withYRange(0, 10)
            ->withAnimationProgress(0.5);
        $out = $chart->view();
        $fullChart = LineChart::new($data, 20, 6)->withYRange(0, 10);
        // Y range should be preserved - same number of rows
        $this->assertSame(count(explode("\n", $fullChart->view())), count(explode("\n", $out)));
    }

    public function testAnimationProgressDefaultIsFull(): void
    {
        $chart = LineChart::new([1, 2, 3, 4, 5], 20, 6);
        $this->assertSame(1.0, $chart->getAnimationProgress());
        $this->assertSame(0, $chart->getAnimationDuration());
    }

    public function testAnimationProgressPartialDeterministic(): void
    {
        $data = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
        $chart = LineChart::new($data, 40, 8)
            ->withAnimationProgress(0.5);
        $out1 = $chart->view();
        $out2 = LineChart::new($data, 40, 8)
            ->withAnimationProgress(0.5)
            ->view();
        // Same progress should produce identical output
        $this->assertSame($out1, $out2);
    }

    public function testAnimationProgressZeroWithAxesReturnsCanvas(): void
    {
        $chart = LineChart::new([1, 2, 3], 20, 8)
            ->withAxes(true)
            ->withAnimationProgress(0.0);
        $out = $chart->view();
        // With 0 progress, we get just an empty canvas (axes not drawn without data)
        $this->assertSame(8, substr_count($out, "\n") + 1);
    }
}
