<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Reel\Decode\RgbFrame;

/**
 * Unit tests for RgbFrame value object.
 *
 * @covers \SugarCraft\Reel\Decode\RgbFrame
 */
final class RgbFrameTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor & property access
    // -------------------------------------------------------------------------

    /**
     * @testdox constructor stores bytes, w, and h as readable properties
     */
    public function testConstructorStoresProperties(): void
    {
        // 3 * 2 * 3 = 18 bytes for a 3×2 RGB frame
        $bytes = str_repeat("\xFF\x00\x7F", 6); // 6 pixels worth
        $frame = new RgbFrame($bytes, 3, 2);

        $this->assertSame($bytes, $frame->bytes);
        $this->assertSame(3, $frame->w);
        $this->assertSame(2, $frame->h);
        $this->assertSame(18, strlen($frame->bytes));
    }

    /**
     * @testdox toGd() creates a GD image with correct dimensions
     */
    public function testToGdCreatesImage(): void
    {
        // Build a 2×2 pixel RGB frame:
        // Pixel 0 (top-left):     R=255, G=0,   B=0   (red)
        // Pixel 1 (top-right):    R=0,   G=255, B=0   (green)
        // Pixel 2 (bottom-left):  R=0,   G=0,   B=255 (blue)
        // Pixel 3 (bottom-right):  R=0,   G=0,   B=0   (black)
        $bytes = "\xFF\x00\x00"   // pixel 0: red
                . "\x00\xFF\x00"   // pixel 1: green
                . "\x00\x00\xFF"   // pixel 2: blue
                . "\x00\x00\x00";  // pixel 3: black
        $frame = new RgbFrame($bytes, 2, 2);

        $img = $frame->toGd();

        $this->assertNotNull($img);
        $this->assertSame(2, imagesx($img));
        $this->assertSame(2, imagesy($img));

        // Verify the center pixel (1,1) is black: imagecolorat returns 0x00BBGGRR on little-endian x86
        $rgb = imagecolorat($img, 1, 1);
        $this->assertSame(0, ($rgb >> 16) & 0xff, 'R component of bottom-right pixel should be 0');
        $this->assertSame(0, ($rgb >> 8) & 0xff,  'G component of bottom-right pixel should be 0');
        $this->assertSame(0, $rgb & 0xff,          'B component of bottom-right pixel should be 0');

        // Verify top-left pixel (0,0) is red
        $rgb = imagecolorat($img, 0, 0);
        $this->assertSame(255, ($rgb >> 16) & 0xff, 'R component of top-left pixel should be 255');
        $this->assertSame(0,   ($rgb >> 8) & 0xff,  'G component of top-left pixel should be 0');
        $this->assertSame(0,  $rgb & 0xff,          'B component of top-left pixel should be 0');

        // Verify top-right pixel (1,0) is green
        $rgb = imagecolorat($img, 1, 0);
        $this->assertSame(0,   ($rgb >> 16) & 0xff, 'R component of top-right pixel should be 0');
        $this->assertSame(255, ($rgb >> 8) & 0xff, 'G component of top-right pixel should be 255');
        $this->assertSame(0,  $rgb & 0xff,          'B component of top-right pixel should be 0');

        imagedestroy($img);
    }

    /**
     * @testdox toGd() pixel format is correct little-endian endianness on x86
     *
     * On little-endian x86, imagecolorat() returns 0x00BBGGRR for a pixel
     * set as (R, G, B). This verifies the endianness extraction:
     *   (rgb >> 16) & 0xff → R  (high byte of RGB0)
     *   (rgb >>  8) & 0xff → G  (middle byte)
     *   (rgb       ) & 0xff → B  (low byte)
     */
    public function testToGdPixelFormatIsCorrectEndian(): void
    {
        // Blue pixel: R=0, G=0, B=255
        $bytes = "\x00\x00\xFF";
        $frame = new RgbFrame($bytes, 1, 1);
        $img = $frame->toGd();

        $rgb = imagecolorat($img, 0, 0);
        // On little-endian: imagecolorat returns 0x00FF0000 for (0,0,255)? No,
        // the format is 0x00BBGGRR on little-endian.
        // So for B=255: low byte = 0xFF. rgb & 0xff = 255.
        $this->assertSame(255, $rgb & 0xff, 'B should be 255 (low byte)');
        $this->assertSame(0,   ($rgb >> 8) & 0xff, 'G should be 0');
        $this->assertSame(0,   ($rgb >> 16) & 0xff, 'R should be 0');

        imagedestroy($img);
    }

    /**
     * Regression for F17. toGd() round-trips pixel colors exactly using
     * the packed 0xRRGGBB form — no imagecolorallocate palette overhead.
     */
    public function testToGdRoundTripExactRgb(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }
        // 2×2 frame: red, green, blue, white
        $bytes = "\xff\x00\x00\x00\xff\x00\x00\x00\xff\xff\xff\xff";
        $frame = new RgbFrame($bytes, 2, 2);
        $img = $frame->toGd();
        // imagecolorat on truecolor returns 0xRRGGBB
        $this->assertSame(0x00ff0000, \imagecolorat($img, 0, 0)); // red
        $this->assertSame(0x0000ff00, \imagecolorat($img, 1, 0)); // green
        $this->assertSame(0x000000ff, \imagecolorat($img, 0, 1)); // blue
        $this->assertSame(0x00ffffff, \imagecolorat($img, 1, 1)); // white
        imagedestroy($img);
    }

    /**
     * @testdox constructor does not validate byte length — caller is responsible
     *
     * RgbFrame is a readonly value object. It accepts any byte string and
     * any w/h pair without validating that strlen(bytes) === w * h * 3.
     * This documents the current behavior; validation is the caller's job.
     */
    public function testBytesLengthMustMatchDimensions(): void
    {
        // Mismatched: 10 bytes but claimed as 3×2 (requires 18 bytes)
        $bytes = str_repeat("\xAA", 10);
        $frame = new RgbFrame($bytes, 3, 2);

        // No exception thrown — constructor accepts mismatched lengths
        $this->assertSame(10, strlen($frame->bytes));
        $this->assertSame(3, $frame->w);
        $this->assertSame(2, $frame->h);

        // toGd() will read past the buffer if bytes are too short — undefined behavior
        // but it will not throw; it may produce garbage or black pixels
        $img = $frame->toGd();
        $this->assertNotNull($img);
        imagedestroy($img);
    }

    // -------------------------------------------------------------------------
    // PNG-frame payload ($png !== null) — the graphics-protocol decode path
    // -------------------------------------------------------------------------

    /**
     * Build a real PNG blob at runtime via GD (no committed binary fixture).
     */
    private static function makePng(int $w, int $h): string
    {
        $img = \imagecreatetruecolor($w, $h);
        \imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, (\imagecolorallocate($img, 12, 34, 56)));
        \ob_start();
        \imagepng($img);
        $bytes = (string) \ob_get_clean();
        \imagedestroy($img);

        return $bytes;
    }

    /**
     * @testdox a PNG-payload frame stores $png and leaves $bytes empty
     */
    public function testPngFrameStoresPngAndEmptyBytes(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(4, 2);
        $frame = new RgbFrame('', 4, 2, $png);

        $this->assertSame('', $frame->bytes);
        $this->assertSame(4, $frame->w);
        $this->assertSame(2, $frame->h);
        $this->assertSame($png, $frame->png);
    }

    /**
     * @testdox toGd() on a PNG-payload frame decodes the PNG to a GdImage of the PNG's dimensions
     *
     * A graphics-mode decoder fills $png with a full-resolution PNG; toGd() must
     * decode it via imagecreatefromstring (and the recovered image dimensions
     * come from the PNG itself, independent of the $w/$h hints).
     */
    public function testToGdDecodesPngPayload(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD required');
        }

        $png = self::makePng(4, 2);
        // Pass deliberately "wrong" w/h hints to prove toGd() trusts the PNG.
        $frame = new RgbFrame('', 99, 99, $png);

        $img = $frame->toGd();
        $this->assertInstanceOf(\GdImage::class, $img);
        $this->assertSame(4, imagesx($img));
        $this->assertSame(2, imagesy($img));

        // The painted colour round-trips through the PNG decode.
        $rgb = imagecolorat($img, 0, 0);
        $this->assertSame(12, ($rgb >> 16) & 0xff);
        $this->assertSame(34, ($rgb >> 8) & 0xff);
        $this->assertSame(56, $rgb & 0xff);

        imagedestroy($img);
    }

    /**
     * @testdox toGd() throws RuntimeException when the PNG payload is undecodable
     */
    public function testToGdThrowsOnInvalidPng(): void
    {
        $frame = new RgbFrame('', 1, 1, 'not-a-png');

        // imagecreatefromstring() emits a benign PHP warning on undecodable data
        // before returning false; swallow only that diagnostic so the assertion
        // can focus on the RuntimeException the production code then throws.
        set_error_handler(static fn(): bool => true, E_WARNING);
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Failed to decode PNG frame');
            $frame->toGd();
        } finally {
            restore_error_handler();
        }
    }
}
