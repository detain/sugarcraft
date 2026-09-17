<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
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

    public function testHeatMapChartClampEquivalenceThroughHeatmapColorScale(): void
    {
        $raw = HeatMapChart::new([[1.5, -2.0], [0.5, 0.9]])->render();
        $pre = HeatMapChart::new([[1.0, 0.0], [0.5, 0.9]])->render();
        $this->assertSame($pre, $raw, 'out-of-range values clamp to 1.0/0.0 identically');
    }

    public function testHeatMapChartRampColorArmsThroughHeatmapColorScale(): void
    {
        $rendered = HeatMapChart::new([[0.25, 0.5, 0.75]])
            ->withShowLabels(false)
            ->withLowColor(Color::hex('#000000'))
            ->withHighColor(Color::hex('#FFFFFF'))
            ->render();
        $this->assertStringContainsString('38;2;63;63;63', $rendered);
        $this->assertStringContainsString('38;2;127;127;127', $rendered);
        $this->assertStringContainsString('38;2;191;191;191', $rendered);
        $this->assertStringNotContainsString('38;2;128;128;128', $rendered);
        $r63 = strpos($rendered, '38;2;63;63;63');
        $r127 = strpos($rendered, '38;2;127;127;127');
        $r191 = strpos($rendered, '38;2;191;191;191');
        $this->assertLessThan($r127, $r63, 'the low-ratio cell renders LEFT of the mid cell');
        $this->assertLessThan($r191, $r127, 'the mid cell renders LEFT of the high-ratio cell');
    }
}
