<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\AreaChart;

final class AreaChartTest extends TestCase
{
    public function testNewCreatesAreaChart(): void
    {
        $chart = AreaChart::new([
            ['label' => 'Series A', 'values' => [10, 20, 30, 40]],
        ]);

        $this->assertNotNull($chart);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $chart = AreaChart::new([
            ['label' => 'Series A', 'values' => [10, 20, 30, 40]],
        ]);

        $rendered = $chart->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $chart = AreaChart::new([
            ['label' => 'Series A', 'values' => [10, 20, 30]],
        ]);

        [$width, $height] = $chart->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithShowGridReturnsNewInstance(): void
    {
        $chart = AreaChart::new([
            ['label' => 'A', 'values' => [10, 20]],
        ]);

        $newChart = $chart->withShowGrid(false);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithShowLegendReturnsNewInstance(): void
    {
        $chart = AreaChart::new([
            ['label' => 'A', 'values' => [10, 20]],
        ]);

        $newChart = $chart->withShowLegend(true);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithMaxValueReturnsNewInstance(): void
    {
        $chart = AreaChart::new([
            ['label' => 'A', 'values' => [10, 20]],
        ]);

        $newChart = $chart->withMaxValue(100.0);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithStackedReturnsNewInstance(): void
    {
        $chart = AreaChart::new([
            ['label' => 'A', 'values' => [10, 20]],
        ]);

        $newChart = $chart->withStacked(true);
        $this->assertNotSame($chart, $newChart);
    }

    public function testEmptySeriesRendersEmpty(): void
    {
        $chart = AreaChart::new([]);
        $this->assertNotSame('', $chart->render());
    }

    public function testMultipleSeriesRenders(): void
    {
        $chart = AreaChart::new([
            ['label' => 'A', 'values' => [10, 20, 30]],
            ['label' => 'B', 'values' => [15, 25, 35]],
        ]);

        $rendered = $chart->render();
        $this->assertNotSame('', $rendered);
    }
}
