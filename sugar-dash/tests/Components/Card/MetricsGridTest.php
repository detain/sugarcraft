<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Card;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Card\MetricsGrid;
use SugarCraft\Dash\Components\Card\MetricCard;

final class MetricsGridTest extends TestCase
{
    public function testNewCreatesMetricsGrid(): void
    {
        $grid = MetricsGrid::new([]);
        $this->assertNotNull($grid);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $grid = MetricsGrid::new([
            new MetricCard('Revenue', '$12,450'),
        ]);
        $rendered = $grid->render();
        $this->assertNotSame('', $rendered);
    }

    public function testSampleCreatesMetricsGrid(): void
    {
        $grid = MetricsGrid::sample();
        $rendered = $grid->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $grid = MetricsGrid::new([
            new MetricCard('Revenue', '$12,450'),
        ]);
        [$width, $height] = $grid->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithMetricsReturnsNewInstance(): void
    {
        $grid = MetricsGrid::new([]);
        $card = new MetricCard('Revenue', '$12,450');
        $newGrid = $grid->withMetrics([$card]);
        $this->assertNotSame($grid, $newGrid);
    }

    public function testWithColumnsReturnsNewInstance(): void
    {
        $grid = MetricsGrid::new([]);
        $newGrid = $grid->withColumns(4);
        $this->assertNotSame($grid, $newGrid);
    }

    public function testWithShowTrendsReturnsNewInstance(): void
    {
        $grid = MetricsGrid::new([]);
        $newGrid = $grid->withShowTrends(false);
        $this->assertNotSame($grid, $newGrid);
    }

    public function testWithShowLabelsReturnsNewInstance(): void
    {
        $grid = MetricsGrid::new([]);
        $newGrid = $grid->withShowLabels(false);
        $this->assertNotSame($grid, $newGrid);
    }

    public function testWithCompactReturnsNewInstance(): void
    {
        $grid = MetricsGrid::new([]);
        $newGrid = $grid->withCompact(true);
        $this->assertNotSame($grid, $newGrid);
    }

    public function testEmptyMetricsRendersEmpty(): void
    {
        $grid = MetricsGrid::new([]);
        $rendered = $grid->render();
        $this->assertSame('', $rendered);
    }
}
