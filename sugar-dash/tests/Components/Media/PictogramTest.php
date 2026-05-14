<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Media;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Media\Pictogram;

final class PictogramTest extends TestCase
{
    public function testNewCreatesPictogram(): void
    {
        $chart = Pictogram::new([
            ['label' => 'Apples', 'value' => 75],
        ]);
        $this->assertNotNull($chart);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $chart = Pictogram::new([
            ['label' => 'Apples', 'value' => 75],
        ]);
        $this->assertNotSame('', $chart->render());
    }

    public function testCirclesCreatesCirlePictogram(): void
    {
        $chart = Pictogram::circles([
            ['label' => 'A', 'value' => 50],
        ]);
        $this->assertNotNull($chart);
    }

    public function testStarsCreatesStarPictogram(): void
    {
        $chart = Pictogram::stars([
            ['label' => 'A', 'value' => 50],
        ]);
        $this->assertNotNull($chart);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $chart = Pictogram::new([
            ['label' => 'Item', 'value' => 50],
        ]);
        [$width, $height] = $chart->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithIconReturnsNewInstance(): void
    {
        $chart = Pictogram::new([
            ['label' => 'A', 'value' => 50],
        ]);
        $newChart = $chart->withIcon('★');
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithMaxIconsReturnsNewInstance(): void
    {
        $chart = Pictogram::new([
            ['label' => 'A', 'value' => 50],
        ]);
        $newChart = $chart->withMaxIcons(20);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithShowLabelsReturnsNewInstance(): void
    {
        $chart = Pictogram::new([
            ['label' => 'A', 'value' => 50],
        ]);
        $newChart = $chart->withShowLabels(false);
        $this->assertNotSame($chart, $newChart);
    }

    public function testWithShowValuesReturnsNewInstance(): void
    {
        $chart = Pictogram::new([
            ['label' => 'A', 'value' => 50],
        ]);
        $newChart = $chart->withShowValues(false);
        $this->assertNotSame($chart, $newChart);
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $chart = Pictogram::new([]);
        $this->assertSame('', $chart->render());
    }

    public function testMultipleItemsRender(): void
    {
        $chart = Pictogram::new([
            ['label' => 'Apples', 'value' => 75],
            ['label' => 'Oranges', 'value' => 50],
            ['label' => 'Bananas', 'value' => 100],
        ]);
        $rendered = $chart->render();
        $this->assertStringContainsString('Apples', $rendered);
        $this->assertStringContainsString('Oranges', $rendered);
        $this->assertStringContainsString('Bananas', $rendered);
    }
}
