<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Codec;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Codec\RawRgba;

/**
 * F-13: the kitty `f=32` raw-quad serialiser — endianness, batching,
 * the GD bridge, and fail-fast length guards.
 */
final class RawRgbaTest extends TestCase
{
    public function testLittleEndianPackEmitsRedGreenBlueAlphaByteOrder(): void
    {
        // R=0x11 G=0x22 B=0x33 A=0x44 composed as R|G<<8|B<<16|A<<24.
        // pack('V') is FIXED little-endian, so the wire quads are R,G,B,A
        // on every host — the kitty f=32 field format (no native-order
        // assumption, no platform caveat).
        self::assertSame(
            "\x11\x22\x33\x44",
            RawRgba::fromPixels([0x44332211]),
        );
    }

    public function testTheEmptyPixelListEncodesToTheEmptyString(): void
    {
        self::assertSame('', RawRgba::fromPixels([]));
    }

    public function testBatchingAcrossThePackBoundaryIsTransparent(): void
    {
        $pixels = [];
        $naive  = '';
        for ($i = 0; $i < 8193; $i++) { // one past PACK_BATCH_PIXELS
            $value = $i & 0xFFFFFFFF;
            $pixels[] = $value;
            $naive .= pack('V', $value);
        }

        self::assertSame($naive, RawRgba::fromPixels($pixels));
        self::assertSame(8193 * 4, strlen(RawRgba::fromPixels($pixels)));
    }

    public function testTheGdBridgeMapsGdAlphaZeroOneTwoSevenToTwoFiveFiveZero(): void
    {
        $img = imagecreatetruecolor(2, 1);
        self::assertInstanceOf(\GdImage::class, $img);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        // GD alpha 0 = opaque → wire A 255; GD alpha 127 = transparent → wire A 0.
        imagesetpixel($img, 0, 0, imagecolorallocatealpha($img, 10, 20, 30, 0));
        imagesetpixel($img, 1, 0, imagecolorallocatealpha($img, 40, 50, 60, 127));

        self::assertSame("\x0a\x14\x1e\xff\x28\x32\x3c\x00", RawRgba::fromGdImage($img));
        imagedestroy($img);
    }

    public function testTheGdBridgeReadsTruecolorRowMajor(): void
    {
        $img = imagecreatetruecolor(2, 2);
        self::assertInstanceOf(\GdImage::class, $img);
        imagesetpixel($img, 1, 0, imagecolorallocate($img, 1, 2, 3));
        imagesetpixel($img, 0, 1, imagecolorallocate($img, 4, 5, 6));

        $bytes = RawRgba::fromGdImage($img);

        self::assertSame(2 * 2 * 4, strlen($bytes));
        // Row-major: (0,0) black, (1,0) 1/2/3, (0,1) 4/5/6, (1,1) black.
        self::assertSame("\x00\x00\x00\xff\x01\x02\x03\xff\x04\x05\x06\xff\x00\x00\x00\xff", $bytes);
        imagedestroy($img);
    }

    public function testValidateAcceptsTheExactFrameSize(): void
    {
        $rgba = str_repeat("\xff\x00\x00\xff", 6);

        self::assertSame($rgba, RawRgba::validate($rgba, 3, 2));
    }

    public function testValidateRefusesAShortBufferNamingBothSizes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw RGBA payload is 3 bytes; expected 24');

        RawRgba::validate("\x01\x02\x03", 3, 2);
    }

    public function testValidateRefusesNonPositiveDimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be positive, got 0x1');

        RawRgba::validate('', 0, 1);
    }
}
