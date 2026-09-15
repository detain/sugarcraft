<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;

/**
 * @covers \SugarCraft\Mosaic\ImageSource::fromRgb
 */
final class ImageSourceFromRgbTest extends TestCase
{
    public function testRgb24RoundTripsExactPixelsThroughPngContainer(): void
    {
        // 2×2: red, green, blue, white.
        $bytes = "\xff\x00\x00" . "\x00\xff\x00" . "\x00\x00\xff" . "\xff\xff\xff";

        $source = ImageSource::fromRgb($bytes, 2, 2);

        $this->assertSame('image/png', $source->format);
        $this->assertSame(2, $source->width);
        $this->assertSame(2, $source->height);

        $img = imagecreatefromstring($source->bytes);
        $this->assertNotFalse($img);

        $expected = [0xFF0000, 0x00FF00, 0x0000FF, 0xFFFFFF];
        $i = 0;
        foreach ([0, 1] as $y) {
            foreach ([0, 1] as $x) {
                $this->assertSame(
                    $expected[$i++],
                    imagecolorat($img, $x, $y) & 0xFFFFFF,
                    "pixel ({$x},{$y})"
                );
            }
        }
        imagedestroy($img);
    }

    public function testRgbaCarriesAlphaThroughToThePng(): void
    {
        // 1×2: fully transparent red, fully opaque red.
        $bytes = "\xff\x00\x00\x00" . "\xff\x00\x00\xff";

        $source = ImageSource::fromRgb($bytes, 1, 2, hasAlpha: true);

        $img = imagecreatefromstring($source->bytes);
        $this->assertNotFalse($img);

        $this->assertSame(127, imagecolorat($img, 0, 0) >> 24, 'byte alpha 0 → GD 127');
        $this->assertSame(0, imagecolorat($img, 0, 1) >> 24, 'byte alpha 255 → GD 0');
        imagedestroy($img);
    }

    public function testRgbaMidAlphaScalesIntoGdRange(): void
    {
        // byte alpha 128 → intdiv((255-128)*127, 255) = 63.
        $source = ImageSource::fromRgb("\x00\xff\x00\x80", 1, 1, hasAlpha: true);

        $img = imagecreatefromstring($source->bytes);
        $this->assertNotFalse($img);

        $this->assertSame(63, imagecolorat($img, 0, 0) >> 24);
        $this->assertSame(0x00FF00, imagecolorat($img, 0, 0) & 0xFFFFFF);
        imagedestroy($img);
    }

    public function testThreeByteBufferRejectedWhenAlphaFlagExpectsFour(): void
    {
        // 3-byte buffer must NOT be accepted as RGBA of a 1-pixel image.
        $this->expectException(\InvalidArgumentException::class);

        ImageSource::fromRgb("\x01\x02\x03", 1, 1, hasAlpha: true);
    }

    public function testSizeMismatchThrowsWithExpectedAndActual(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw RGB buffer is 5 bytes; expected 12');

        ImageSource::fromRgb("\x00\x00\x00\x00\x00", 2, 2);
    }

    public function testNonPositiveDimensionsThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw RGB dimensions must be positive');

        ImageSource::fromRgb('', 0, 4);
    }

    public function testNegativeDimensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImageSource::fromRgb(str_repeat("\x00", 12), 2, -2);
    }

    public function testResultIsAcceptedByTheContainerPipeline(): void
    {
        // The whole point: downstream callers treat the output exactly like
        // any decoded ImageSource — fromString() re-detects and matches.
        $source = ImageSource::fromRgb(str_repeat("\x10\x20\x30", 4), 2, 2);
        $again  = ImageSource::fromString($source->bytes);

        $this->assertSame($source->format, $again->format);
        $this->assertSame($source->width, $again->width);
        $this->assertSame($source->height, $again->height);
        $this->assertSame($source->bytes, $again->bytes);
    }

    public function testRendersThroughHalfBlockLikeAnySource(): void
    {
        // 2×2: two red pixels over two green pixels (12 bytes, RGB24).
        $source = ImageSource::fromRgb("\xff\x00\x00\xff\x00\x00\x00\xff\x00\x00\xff\x00", 2, 2);
        $out    = \SugarCraft\Mosaic\Mosaic::halfBlock()->render($source, 2, 1);

        $this->assertNotSame('', $out);
    }
}
