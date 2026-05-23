<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;

/**
 * Regression: every image descriptor carries the Local Color Table flag.
 *
 * Bug fixed in d070e742: PhpGifEncoder used to forget to set the LCT
 * flag on the FIRST frame's image descriptor, so most decoders fell
 * back to a missing Global Color Table and rendered the first frame as
 * solid colors. The fix writes `0x87` (LCT flag + size 7 → 256 entries)
 * as the packed byte for every image descriptor.
 *
 * Walks the encoded GIF bytes looking for the image descriptor marker
 * `\x2c`, then checks the packed byte 9 bytes later (after 2-byte left,
 * top, width, height = 8 bytes).
 */
final class PhpGifEncoderLctTest extends TestCase
{
    public function testEveryImageDescriptorHasLctFlagSet(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $tempDir = sys_get_temp_dir() . '/candy-vcr-php-lct-' . bin2hex(random_bytes(4));
        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            $this->fail("Failed to create temp dir: {$tempDir}");
        }

        $pngPaths = [];
        try {
            foreach ([0xff0000, 0x00ff00] as $i => $rgb) {
                $img = imagecreatetruecolor(4, 4);
                $this->assertInstanceOf(\GdImage::class, $img);
                $color = imagecolorallocate($img, ($rgb >> 16) & 0xff, ($rgb >> 8) & 0xff, $rgb & 0xff);
                imagefilledrectangle($img, 0, 0, 3, 3, (int) $color);
                $p = $tempDir . '/f' . $i . '.png';
                imagepng($img, $p);
                imagedestroy($img);
                $pngPaths[] = $p;
            }

            $gifPath = $tempDir . '/out.gif';
            $encoder = new PhpGifEncoder();
            $encoder->encode($pngPaths, $gifPath, 30);

            $gif = file_get_contents($gifPath);
            $this->assertIsString($gif);

            $packedBytes = $this->extractImageDescriptorPackedBytes($gif);
            $this->assertCount(2, $packedBytes, 'Expected one image descriptor per frame');

            foreach ($packedBytes as $idx => $packed) {
                $this->assertSame(
                    0x87,
                    $packed,
                    sprintf(
                        'Frame %d image-descriptor packed byte must be 0x87 (LCT flag + size 7), got 0x%02x',
                        $idx,
                        $packed,
                    ),
                );
            }
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tempDir);
        }
    }

    /**
     * Scan for `\x2c` (image separator) and pull the packed byte that
     * lives 9 bytes later. Skip ahead enough to avoid mis-matching on
     * pixel data.
     *
     * @return list<int>
     */
    private function extractImageDescriptorPackedBytes(string $gif): array
    {
        $bytes = [];
        $len = strlen($gif);
        $i = 0;
        // First valid image descriptor lives after the header (6) + LSD (7) = 13
        // bytes, plus any extension blocks. Start at 13 to skip the GIF89a
        // signature + logical screen descriptor.
        while ($i < $len - 10) {
            if ($gif[$i] === "\x2c") {
                $packed = ord($gif[$i + 9]);
                $bytes[] = $packed;
                // The image descriptor is 10 bytes total; followed by an
                // LCT (when flag set) + LZW data. Skip past the LCT
                // (3 * 256 = 768 bytes) + LZW byte to avoid spurious 0x2c
                // matches in pixel data.
                $i += 10 + 768 + 1;
                continue;
            }
            $i++;
        }
        return $bytes;
    }
}
