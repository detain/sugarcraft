<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\RadarChart;

final class RadarChartTest extends TestCase
{
    public function testNewCreatesRadarChart(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power', 'Control'],
            [
                ['label' => 'Player 1', 'values' => [0.8, 0.6, 0.7]],
            ]
        );

        $this->assertNotNull($chart);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power', 'Control'],
            [
                ['label' => 'Player 1', 'values' => [0.8, 0.6, 0.7]],
            ]
        );

        $rendered = $chart->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power', 'Control'],
            [
                ['label' => 'Player 1', 'values' => [0.8, 0.6, 0.7]],
            ]
        );

        [$width, $height] = $chart->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power'],
            [
                ['label' => 'P1', 'values' => [0.5, 0.5]],
            ]
        );

        $newChart = $chart->withSize(30);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithGridLinesReturnsNewInstance(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power'],
            [
                ['label' => 'P1', 'values' => [0.5, 0.5]],
            ]
        );

        $newChart = $chart->withGridLines(5);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithMaxValueReturnsNewInstance(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power'],
            [
                ['label' => 'P1', 'values' => [50, 50]],
            ]
        );

        $newChart = $chart->withMaxValue(100.0);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithShowLabelsReturnsNewInstance(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power'],
            [
                ['label' => 'P1', 'values' => [0.5, 0.5]],
            ]
        );

        $newChart = $chart->withShowLabels(false);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithShowGridReturnsNewInstance(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power'],
            [
                ['label' => 'P1', 'values' => [0.5, 0.5]],
            ]
        );

        $newChart = $chart->withShowGrid(false);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithShowDotsReturnsNewInstance(): void
    {
        $chart = RadarChart::new(
            ['Speed', 'Power'],
            [
                ['label' => 'P1', 'values' => [0.5, 0.5]],
            ]
        );

        $newChart = $chart->withShowDots(false);
        $this->assertNotSame($chart, $newChart);
    }

    public function testEmptyLabelsReturnsEmpty(): void
    {
        $chart = RadarChart::new(
            [],
            [
                ['label' => 'P1', 'values' => []],
            ]
        );

        $this->assertSame('', $chart->render());
    }

    public function testTooFewLabelsReturnsEmpty(): void
    {
        $chart = RadarChart::new(
            ['Speed'],
            [
                ['label' => 'P1', 'values' => [0.5]],
            ]
        );

        $this->assertSame('', $chart->render());
    }
}
