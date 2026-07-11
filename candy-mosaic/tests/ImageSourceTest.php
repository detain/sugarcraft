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

    // ---- decompression-bomb (MAX_PIXELS) guards ------------------------

    public function testFromFileRejectsImageAboveMaxPixels(): void
    {
        // 4x2.png = 8 pixels; a ceiling of 4 must reject it BEFORE GD decodes.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum of 4 pixels/');

        ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png', maxPixels: 4);
    }

    public function testFromFileAllowsImageAtMaxPixels(): void
    {
        // 8 pixels with a ceiling of exactly 8 is allowed (strictly-greater test).
        $img = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png', maxPixels: 8);

        $this->assertSame(4, $img->width);
        $this->assertSame(8, $img->maxPixels);
    }

    public function testFromStringRejectsImageAboveMaxPixels(): void
    {
        $bytes = (string) file_get_contents(__DIR__ . '/fixtures/4x2.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum/');

        ImageSource::fromString($bytes, maxPixels: 4);
    }

    public function testDefaultMaxPixelsCeilingIsApplied(): void
    {
        $img = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $this->assertSame(ImageSource::MAX_PIXELS, $img->maxPixels);
    }

    public function testWithMaxPixelsRejectsLoweringBelowCurrentSize(): void
    {
        $img = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum/');

        $img->withMaxPixels(4);
    }

    public function testWithMaxPixelsReturnsNewInstanceWhenWithinCap(): void
    {
        $img  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $next = $img->withMaxPixels(100);

        $this->assertSame(100, $next->maxPixels);
        $this->assertSame(4, $next->width);
        $this->assertSame(ImageSource::MAX_PIXELS, $img->maxPixels);  // original unchanged
    }

    public function testMaxPixelsCeilingPropagatesThroughResize(): void
    {
        // A ceiling carried on the source must reject an oversized resize
        // target before GD allocates the enlarged buffer.
        $img = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png', maxPixels: 8);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum/');

        $img->resize(10, 10);  // 100 pixels > ceiling of 8
    }
}
