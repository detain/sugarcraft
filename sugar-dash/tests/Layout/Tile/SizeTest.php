<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Tile;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Tile\Size;

final class SizeTest extends TestCase
{
    public function testSizeHasOptionalDefaultsFalse(): void
    {
        $size = new Size();
        $this->assertFalse($size->optional);
    }

    public function testSizeHasMinSizeFitDefaultsFalse(): void
    {
        $size = new Size();
        $this->assertFalse($size->minSizeFit);
    }

    public function testSizeFixedFactory(): void
    {
        $size = Size::fixed(100, 50);
        $this->assertSame(100, $size->fixedWidth);
        $this->assertSame(50, $size->fixedHeight);
        $this->assertSame(0.0, $size->weight);
    }

    public function testSizeFlexFactory(): void
    {
        $size = Size::flex(2.5);
        $this->assertSame(2.5, $size->weight);
        $this->assertNull($size->fixedWidth);
        $this->assertNull($size->fixedHeight);
    }

    public function testSizeFlexFactoryDefaultWeight(): void
    {
        $size = Size::flex();
        $this->assertSame(1.0, $size->weight);
    }

    public function testSizeWithOptional(): void
    {
        $size = new Size();
        $this->assertFalse($size->optional);

        $withOptional = $size->withOptional(true);
        $this->assertTrue($withOptional->optional);
        $this->assertFalse($size->optional); // Original unchanged
    }

    public function testSizeWithMinSizeFit(): void
    {
        $size = new Size();
        $this->assertFalse($size->minSizeFit);

        $withMinSizeFit = $size->withMinSizeFit(true);
        $this->assertTrue($withMinSizeFit->minSizeFit);
        $this->assertFalse($size->minSizeFit); // Original unchanged
    }

    public function testSizeFillFactory(): void
    {
        $size = Size::fill();
        $this->assertSame(1.0, $size->weight);
        $this->assertNull($size->fixedWidth);
        $this->assertNull($size->fixedHeight);
    }

    public function testSizeWithWeight(): void
    {
        $size = new Size();
        $withWeight = $size->withWeight(3.0);
        $this->assertSame(3.0, $withWeight->weight);
        $this->assertSame(1.0, $size->weight); // Original unchanged
    }

    public function testSizeWithMinWidth(): void
    {
        $size = new Size();
        $withMin = $size->withMinWidth(50);
        $this->assertSame(50, $withMin->minWidth);
        $this->assertNull($size->minWidth); // Original unchanged
    }

    public function testSizeWithMaxWidth(): void
    {
        $size = new Size();
        $withMax = $size->withMaxWidth(200);
        $this->assertSame(200, $withMax->maxWidth);
        $this->assertNull($size->maxWidth); // Original unchanged
    }

    public function testSizeImmutability(): void
    {
        $size = new Size(weight: 1.0);
        $size2 = $size->withWidth(100);
        $size3 = $size2->withHeight(50);
        $size4 = $size3->withOptional(true);
        $size5 = $size4->withMinSizeFit(true);

        // Each step preserved previous values
        $this->assertSame(0, $size->width);
        $this->assertSame(0, $size2->height);
        $this->assertFalse($size3->optional);
        $this->assertFalse($size4->minSizeFit);

        // Final has all changes
        $this->assertSame(100, $size5->width);
        $this->assertSame(50, $size5->height);
        $this->assertTrue($size5->optional);
        $this->assertTrue($size5->minSizeFit);
    }
}
