<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;

/**
 * @covers \SugarCraft\Mosaic\ImageSource
 */
final class ImageSourceTest extends TestCase
{
    public function testCropThrowsOutOfBounds(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        // 4x2 image: x [0,3], y [0,1].  x+w=50+10=60 > width=4 → OOB.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Crop region .* is outside image bounds/');
        $image->crop(50, 0, 10, 2);
    }

    public function testResizeThrowsNonPositiveWidth(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Resize dimensions must be positive/');
        $image->resize(0, 10);
    }

    public function testResizeThrowsNonPositiveHeight(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Resize dimensions must be positive/');
        $image->resize(10, -1);
    }

    public function testResizeThrowsBothNonPositive(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Resize dimensions must be positive/');
        $image->resize(0, 0);
    }

    public function testCropThrowsOnNegativeX(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $image->crop(-1, 0, 2, 2);
    }

    public function testCropThrowsOnNegativeY(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $image->crop(0, -1, 2, 2);
    }

    public function testCropThrowsOnZeroWidth(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $image->crop(0, 0, 0, 2);
    }

    public function testCropThrowsOnZeroHeight(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $image->crop(0, 0, 2, 0);
    }
}
