<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Dither\FloydSteinberg;
use PHPUnit\Framework\TestCase;

final class FloydSteinbergTest extends TestCase
{
    public function testDitherReturnsNewImageAndDoesNotModifySource(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $src = imagecreatetruecolor(3, 3);
        imagefilledrectangle($src, 0, 0, 2, 2, imagecolorallocate($src, 200, 100, 50));

        $dst = FloydSteinberg::dither($src, [
            [0, 0, 0],
            [255, 255, 255],
            [255, 0, 0],
        ]);

        // Source should be unchanged.
        $this->assertSame(3, imagesx($src));
        $this->assertSame(3, imagesy($src));

        // Destination should have same dimensions.
        $this->assertSame(3, imagesx($dst));
        $this->assertSame(3, imagesy($dst));

        imagedestroy($src);
        imagedestroy($dst);
    }

    public function testDitherOutputUsesOnlyPaletteColors(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $src = imagecreatetruecolor(4, 4);
        // Fill with a gradient.
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                imagesetpixel($src, $x, $y, imagecolorallocate($src, (int) ($x * 63), (int) ($y * 63), 128));
            }
        }

        $palette = [
            [0, 0, 0],
            [128, 0, 0],
            [0, 128, 0],
            [0, 0, 128],
            [255, 255, 255],
        ];

        $dst = FloydSteinberg::dither($src, $palette);

        // Check that every pixel uses one of the palette colors.
        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $rgb = imagecolorat($dst, $x, $y);
                $r = ($rgb >> 16) & 0xff;
                $g = ($rgb >> 8) & 0xff;
                $b = $rgb & 0xff;

                $found = false;
                foreach ($palette as [$pr, $pg, $pb]) {
                    if ($r === $pr && $g === $pg && $b === $pb) {
                        $found = true;
                        break;
                    }
                }
                $this->assertTrue($found, "Pixel ($x,$y) = ($r,$g,$b) not in palette");
            }
        }
        imagedestroy($src);
        imagedestroy($dst);
    }

    public function testDitherWithSingleColorPaletteProducesMonotoneImage(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $src = imagecreatetruecolor(4, 4);
        imagefilledrectangle($src, 0, 0, 3, 3, imagecolorallocate($src, 128, 64, 192));

        $dst = FloydSteinberg::dither($src, [[128, 64, 192]]);

        for ($y = 0; $y < 4; $y++) {
            for ($x = 0; $x < 4; $x++) {
                $rgb = imagecolorat($dst, $x, $y);
                $this->assertSame(128, ($rgb >> 16) & 0xff);
                $this->assertSame(64,  ($rgb >> 8) & 0xff);
                $this->assertSame(192, $rgb & 0xff);
            }
        }
        imagedestroy($src);
        imagedestroy($dst);
    }

    public function testDitherSingleColorTransparent(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        // Create a 1×1 palette (not truecolor) image so we can use imagecolortransparent.
        $src = imagecreate(1, 1);
        $transparentIndex = imagecolorallocate($src, 0, 0, 0);
        imagecolortransparent($src, $transparentIndex);

        // Pixel (0,0) is the transparent color index.
        imagesetpixel($src, 0, 0, $transparentIndex);

        $dst = FloydSteinberg::dither($src, [
            [0, 0, 0],      // index 0 — also our transparent color
            [255, 0, 0],    // index 1 — opaque red
        ]);

        $idx = imagecolorat($dst, 0, 0);
        // For truecolor output, imagecolorat returns 0xRRGGBBAA.
        // Extract alpha from the high byte (24–31).
        $alpha = ($idx >> 24) & 0xff;

        // The transparent source pixel should map to the transparent palette entry
        // and remain transparent (alpha = 127) in the output.
        $this->assertSame(127, $alpha, 'Pixel 0 at (0,0) should be fully transparent');

        imagedestroy($src);
        imagedestroy($dst);
    }
}
