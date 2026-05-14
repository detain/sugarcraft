<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\HeatMapChart;

final class HeatMapChartTest extends TestCase
{
    public function testNewCreatesHeatMapChart(): void
    {
        $heatmap = HeatMapChart::new([[0.1, 0.5], [0.8, 0.3]]);
        $this->assertNotNull($heatmap);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $heatmap = HeatMapChart::new([[0.1, 0.5], [0.8, 0.3]]);
        $rendered = $heatmap->render();
        $this->assertNotSame('', $rendered);
    }

    public function testSampleCreatesHeatMapChart(): void
    {
        $heatmap = HeatMapChart::sample();
        $rendered = $heatmap->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $heatmap = HeatMapChart::new([[0.1, 0.5]]);
        [$width, $height] = $heatmap->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithRowLabelsReturnsNewInstance(): void
    {
        $heatmap = HeatMapChart::new([[0.1, 0.5]]);
        $newHeatmap = $heatmap->withRowLabels(['A', 'B']);
        $this->assertNotSame($heatmap, $newHeatmap);
    }

    public function testWithColumnLabelsReturnsNewInstance(): void
    {
        $heatmap = HeatMapChart::new([[0.1, 0.5]]);
        $newHeatmap = $heatmap->withColumnLabels(['X', 'Y']);
        $this->assertNotSame($heatmap, $newHeatmap);
    }

    public function testWithShowLabelsReturnsNewInstance(): void
    {
        $heatmap = HeatMapChart::new([[0.1, 0.5]]);
        $newHeatmap = $heatmap->withShowLabels(false);
        $this->assertNotSame($heatmap, $newHeatmap);
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $heatmap = HeatMapChart::new([]);
        $rendered = $heatmap->render();
        $this->assertSame('', $rendered);
    }
}