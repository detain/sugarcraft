<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\PhpGifEncoder;

/**
 * Regression: PhpGifEncoder honors per-frame durations in centiseconds.
 *
 * Bug fixed in d070e742: PhpGifEncoder did a double conversion of the
 * supplied per-frame durations (ms → cs → cs) so a 500ms Sleep would
 * encode as 5cs (50ms) instead of 50cs (500ms). The fix divides the
 * per-frame ms by 10 exactly once.
 *
 * This test writes three PNGs, encodes with explicit [100, 500, 100] ms,
 * then walks the GIF bytes and asserts each Graphic Control Extension
 * carries the expected centisecond delay.
 */
final class PhpGifEncoderDelayTest extends TestCase
{
    public function testPerFrameDelaysAreEncodedInCentiseconds(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $tempDir = sys_get_temp_dir() . '/candy-vcr-php-delay-' . bin2hex(random_bytes(4));
        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            $this->fail("Failed to create temp dir: {$tempDir}");
        }

        $pngPaths = [];
        try {
            // Three small distinct frames.
            foreach ([0xff0000, 0x00ff00, 0x0000ff] as $i => $rgb) {
                $img = imagecreatetruecolor(4, 4);
                $this->assertInstanceOf(\GdImage::class, $img);
                $color = imagecolorallocate(
                    $img,
                    ($rgb >> 16) & 0xff,
                    ($rgb >> 8) & 0xff,
                    $rgb & 0xff,
                );
                imagefilledrectangle($img, 0, 0, 3, 3, (int) $color);
                $p = $tempDir . '/f' . $i . '.png';
                imagepng($img, $p);
                imagedestroy($img);
                $pngPaths[] = $p;
            }

            $gifPath = $tempDir . '/out.gif';
            $encoder = new PhpGifEncoder();
            $encoder->encode($pngPaths, $gifPath, 30, [100, 500, 100]);

            $gif = file_get_contents($gifPath);
            $this->assertIsString($gif);

            $delays = $this->extractGceDelays($gif);
            $this->assertCount(3, $delays, 'Expected exactly one GCE delay per frame');

            // 100ms → 10cs, 500ms → 50cs, 100ms → 10cs (±1 for rounding).
            $this->assertEqualsWithDelta(10, $delays[0], 1);
            $this->assertEqualsWithDelta(50, $delays[1], 1);
            $this->assertEqualsWithDelta(10, $delays[2], 1);
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tempDir);
        }
    }

    /**
     * Walk GIF bytes looking for `\x21\xf9\x04` (GCE marker, block size 4).
     * Extract the 2-byte little-endian delay at bytes [4..5] after the marker.
     *
     * @return list<int> centiseconds, one entry per frame
     */
    private function extractGceDelays(string $gif): array
    {
        $delays = [];
        $len = strlen($gif);
        $i = 0;
        while ($i < $len - 7) {
            if ($gif[$i] === "\x21" && $gif[$i + 1] === "\xf9" && $gif[$i + 2] === "\x04") {
                // Skip marker(3) + packed(1) → delay at offset 4..5 from $i.
                $low = ord($gif[$i + 4]);
                $high = ord($gif[$i + 5]);
                $delays[] = $low | ($high << 8);
                $i += 8; // marker(3) + packed(1) + delay(2) + transp(1) + terminator(1)
                continue;
            }
            $i++;
        }
        return $delays;
    }
}
