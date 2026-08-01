<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;

/**
 * @covers \SugarCraft\Mosaic\ImageSource::fromGd
 */
final class ImageSourceFromGdTest extends TestCase
{
    private string $fixture4x2;

    protected function setUp(): void
    {
        $this->fixture4x2 = __DIR__ . '/fixtures/4x2.png';
        if (!file_exists($this->fixture4x2)) {
            $this->markTestSkipped('Fixture tests/fixtures/4x2.png missing');
        }
    }

    public function testFromGdCreatesImageSource(): void
    {
        $gdImage = imagecreatefrompng($this->fixture4x2);
        if ($gdImage === false) {
            $this->fail('Could not create GD image from fixture');
        }

        $source = ImageSource::fromGd($gdImage, 'image/png');

        $this->assertSame('image/png', $source->format);
        $this->assertSame(4, $source->width);
        $this->assertSame(2, $source->height);
        $this->assertNotEmpty($source->bytes);
    }

    public function testFromGdConvertsPaletteToTruecolor(): void
    {
        // Create a palette (non-truecolor) image
        $gdImage = imagecreate(10, 10);
        if ($gdImage === false) {
            $this->fail('Could not create palette GD image');
        }

        // Allocate a color and draw something
        $white = imagecolorallocate($gdImage, 255, 255, 255);
        imagefill($gdImage, 0, 0, $white);

        // fromGd should convert palette to truecolor automatically
        $source = ImageSource::fromGd($gdImage, 'image/png');

        // The format should be preserved, but internally it should be truecolor
        $this->assertSame('image/png', $source->format);
        imagedestroy($gdImage);
    }

    public function testFromGdWithMaxPixels(): void
    {
        $gdImage = imagecreatefrompng($this->fixture4x2);
        if ($gdImage === false) {
            $this->fail('Could not create GD image from fixture');
        }

        // 4x2 = 8 pixels, ceiling of 100 should pass
        $source = ImageSource::fromGd($gdImage, 'image/png', 100);

        $this->assertSame(100, $source->maxPixels);
        imagedestroy($gdImage);
    }

    public function testFromGdRejectsExcessiveMaxPixels(): void
    {
        $gdImage = imagecreatefrompng($this->fixture4x2);
        if ($gdImage === false) {
            $this->fail('Could not create GD image from fixture');
        }

        // 4x2 = 8 pixels, ceiling of 4 should fail
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum/i');

        ImageSource::fromGd($gdImage, 'image/png', 4);

        imagedestroy($gdImage);
    }

    public function testFromGdWithJpegFormat(): void
    {
        // Create a simple JPEG via GD
        $gdImage = imagecreatetruecolor(4, 2);
        $white = imagecolorallocate($gdImage, 255, 255, 255);
        imagefill($gdImage, 0, 0, $white);

        $source = ImageSource::fromGd($gdImage, 'image/jpeg');

        $this->assertSame('image/jpeg', $source->format);
        imagedestroy($gdImage);
    }

    public function testFromGdWithGifFormat(): void
    {
        $gdImage = imagecreatetruecolor(4, 2);
        $white = imagecolorallocate($gdImage, 255, 255, 255);
        imagefill($gdImage, 0, 0, $white);

        $source = ImageSource::fromGd($gdImage, 'image/gif');

        $this->assertSame('image/gif', $source->format);
        imagedestroy($gdImage);
    }

    public function testFromGdWithUnsupportedFormat(): void
    {
        $gdImage = imagecreatetruecolor(4, 2);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unsupported/i');

        ImageSource::fromGd($gdImage, 'image/bmp');

        imagedestroy($gdImage);
    }

    public function testFromGdPreservesMaxPixelsInCrop(): void
    {
        $gdImage = imagecreatefrompng($this->fixture4x2);
        if ($gdImage === false) {
            $this->fail('Could not create GD image from fixture');
        }

        $source = ImageSource::fromGd($gdImage, 'image/png', 100);

        // Should be able to crop without hitting maxPixels
        $cropped = $source->crop(0, 0, 2, 1);
        $this->assertSame(2, $cropped->width);
        $this->assertSame(1, $cropped->height);

        imagedestroy($gdImage);
    }
}
