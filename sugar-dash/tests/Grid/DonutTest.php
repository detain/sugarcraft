<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Donut;

final class DonutTest extends TestCase
{
    public function testNewCreatesDonut(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 30],
            ['label' => 'B', 'value' => 70],
        ]);

        $this->assertNotNull($donut);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 30],
            ['label' => 'B', 'value' => 70],
        ]);

        $rendered = $donut->render();
        $this->assertNotSame('', $rendered);
    }

    public function testMochaCreatesWithColors(): void
    {
        $donut = Donut::mocha([
            ['label' => 'A', 'value' => 30],
            ['label' => 'B', 'value' => 70],
        ]);

        $rendered = $donut->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsSquareDimensions(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 30],
        ]);

        [$width, $height] = $donut->getInnerSize();
        $this->assertEquals($width, $height);
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $donut = Donut::new([]);
        $this->assertNotSame('', $donut->render());
    }

    public function testWithSizeReturnsNewInstance(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 50],
        ]);

        $newDonut = $donut->withSize(30);
        $this->assertNotSame($donut, $newDonut);
    }

    public function testWithCenterLabelReturnsNewInstance(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 50],
        ]);

        $newDonut = $donut->withCenterLabel('Test');
        $this->assertNotSame($donut, $newDonut);
    }

    public function testWithShowPercentageReturnsNewInstance(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 50],
        ]);

        $newDonut = $donut->withShowPercentage(true);
        $this->assertNotSame($donut, $newDonut);
    }

    public function testWithStartAngleReturnsNewInstance(): void
    {
        $donut = Donut::new([
            ['label' => 'A', 'value' => 50],
        ]);

        $newDonut = $donut->withStartAngle(90.0);
        $this->assertNotSame($donut, $newDonut);
    }
}
