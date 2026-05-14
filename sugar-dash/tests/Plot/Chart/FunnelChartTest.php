<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\FunnelChart;

final class FunnelChartTest extends TestCase
{
    public function testNewCreatesFunnelChart(): void
    {
        $chart = FunnelChart::new([
            ['label' => 'Visitors', 'value' => 1000],
            ['label' => 'Signups', 'value' => 500],
            ['label' => 'Purchases', 'value' => 100],
        ]);
        $this->assertNotNull($chart);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $chart = FunnelChart::new([
            ['label' => 'A', 'value' => 100],
            ['label' => 'B', 'value' => 50],
        ]);
        $this->assertNotSame('', $chart->render());
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $chart = FunnelChart::new([
            ['label' => 'A', 'value' => 100],
        ]);
        [$width, $height] = $chart->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithHorizontalReturnsNewInstance(): void
    {
        $chart = FunnelChart::new([['label' => 'A', 'value' => 10]]);
        $newChart = $chart->withHorizontal(true);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithShowValuesReturnsNewInstance(): void
    {
        $chart = FunnelChart::new([['label' => 'A', 'value' => 10]]);
        $newChart = $chart->withShowValues(false);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithShowPercentagesReturnsNewInstance(): void
    {
        $chart = FunnelChart::new([['label' => 'A', 'value' => 10]]);
        $newChart = $chart->withShowPercentages(false);
        $this->assertNotSame($chart, $newChart);
    }

    public function testEmptyStagesRendersEmpty(): void
    {
        $chart = FunnelChart::new([]);
        $this->assertSame('', $chart->render());
    }

    public function testRenderContainsLabels(): void
    {
        $chart = FunnelChart::new([
            ['label' => 'Visitors', 'value' => 1000],
            ['label' => 'Buyers', 'value' => 100],
        ]);
        $rendered = $chart->render();
        $this->assertStringContainsString('Visitors', $rendered);
        $this->assertStringContainsString('Buyers', $rendered);
    }
}