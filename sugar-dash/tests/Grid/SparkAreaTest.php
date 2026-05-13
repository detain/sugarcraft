<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\SparkArea;

final class SparkAreaTest extends TestCase
{
    public function testNewCreatesSparkArea(): void
    {
        $spark = SparkArea::new([1, 2, 3, 2, 1]);
        $this->assertNotNull($spark);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $spark = SparkArea::new([1, 2, 3, 2, 1]);
        $rendered = $spark->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $spark = SparkArea::new([1, 2, 3]);
        [$width, $height] = $spark->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithHeightReturnsNewInstance(): void
    {
        $spark = SparkArea::new([1, 2, 3]);
        $newSpark = $spark->withHeight(4);
        $this->assertNotSame($spark, $newSpark);
    }

    public function testWithColorReturnsNewInstance(): void
    {
        $spark = SparkArea::new([1, 2, 3]);
        $newSpark = $spark->withColor(\SugarCraft\Core\Util\Color::hex('#FF0000'));
        $this->assertNotSame($spark, $newSpark);
    }

    public function testWithShowMinMaxReturnsNewInstance(): void
    {
        $spark = SparkArea::new([1, 2, 3]);
        $newSpark = $spark->withShowMinMax(true);
        $this->assertNotSame($spark, $newSpark);
    }

    public function testWithGradientReturnsNewInstance(): void
    {
        $spark = SparkArea::new([1, 2, 3]);
        $newSpark = $spark->withGradient(false);
        $this->assertNotSame($spark, $newSpark);
    }

    public function testEmptyValuesRendersEmpty(): void
    {
        $spark = SparkArea::new([]);
        $this->assertNotSame('', $spark->render());
    }

    public function testSingleValueRenders(): void
    {
        $spark = SparkArea::new([5]);
        $rendered = $spark->render();
        $this->assertNotSame('', $rendered);
    }
}
