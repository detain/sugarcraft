<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\PlacedImage;

final class PlacedImageTest extends TestCase
{
    public function testConstructorStoresMarkerAndImageId(): void
    {
        $marker = "\x1b=_foo\x1b\\";
        $placed = new PlacedImage($marker, 5);

        $this->assertSame($marker, $placed->marker);
        $this->assertSame(5, $placed->imageId);
    }

    public function testImageIdCanBeNull(): void
    {
        $placed = new PlacedImage("      \n      ", null);

        $this->assertNull($placed->imageId);
    }

    public function testMarkerCanBeEmptyString(): void
    {
        $placed = new PlacedImage('', 0);

        $this->assertSame('', $placed->marker);
        $this->assertSame(0, $placed->imageId);
    }

    public function testDifferentIdsProduceDifferentPlacedImages(): void
    {
        $a = new PlacedImage('markera', 1);
        $b = new PlacedImage('markera', 2);

        $this->assertNotSame($a->imageId, $b->imageId);
    }

    public function testDifferentMarkersProduceDifferentPlacedImages(): void
    {
        $a = new PlacedImage('markera', 1);
        $b = new PlacedImage('markerb', 1);

        $this->assertNotSame($a->marker, $b->marker);
    }

    public function testZeroIsValidImageId(): void
    {
        $placed = new PlacedImage("\x1b=_a\x1b\\", 0);

        $this->assertSame(0, $placed->imageId);
    }
}
