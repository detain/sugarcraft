<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\FfmpegGifEncoder;
use Symfony\Component\Process\Process;

/**
 * Regression: FfmpegGifEncoder honors per-frame VFR durations.
 *
 * Bug fixed in d070e742: the ffmpeg encoder used to ignore the
 * `$durations` argument so a `Sleep 2s` in the tape was flattened
 * back to the constant fps cadence. The fix writes a concat-demuxer
 * list with per-file `duration` directives so each frame holds for
 * its own duration. Variable per-frame delays in the resulting GIF
 * are the visible signal that the fix works.
 */
final class FfmpegGifEncoderVfrTest extends TestCase
{
    public function testVfrDurationsProduceNonUniformGifDelays(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        if (!$this->isFfmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $tempDir = sys_get_temp_dir() . '/candy-vcr-ffvfr-' . bin2hex(random_bytes(4));
        if (!mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            $this->fail("Failed to create temp dir: {$tempDir}");
        }

        $pngPaths = [];
        try {
            foreach ([0xff0000, 0x00ff00, 0x0000ff] as $i => $rgb) {
                $img = imagecreatetruecolor(8, 8);
                $this->assertInstanceOf(\GdImage::class, $img);
                $c = imagecolorallocate($img, ($rgb >> 16) & 0xff, ($rgb >> 8) & 0xff, $rgb & 0xff);
                imagefilledrectangle($img, 0, 0, 7, 7, (int) $c);
                $p = $tempDir . '/f' . $i . '.png';
                imagepng($img, $p);
                imagedestroy($img);
                $pngPaths[] = $p;
            }

            $gifPath = $tempDir . '/out.gif';
            $encoder = new FfmpegGifEncoder();
            $encoder->encode($pngPaths, $gifPath, 30, [100, 1000, 100]);
            $this->assertFileExists($gifPath);

            $gif = file_get_contents($gifPath);
            $this->assertIsString($gif);
            $delays = $this->extractGceDelays($gif);
            $this->assertGreaterThanOrEqual(2, count($delays), 'Should have multiple GCEs (one per frame)');

            $spread = max($delays) - min($delays);
            $this->assertGreaterThan(
                50,
                $spread,
                sprintf(
                    'Expected non-uniform delays (max - min > 50cs) — got delays [%s]',
                    implode(', ', $delays),
                ),
            );
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($tempDir);
        }
    }

    private function isFfmpegAvailable(): bool
    {
        try {
            $process = new Process(['ffmpeg', '-version']);
            $process->setTimeout(5);
            $process->run();
            return $process->isSuccessful();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * @return list<int>
     */
    private function extractGceDelays(string $gif): array
    {
        $delays = [];
        $len = strlen($gif);
        $i = 0;
        while ($i < $len - 7) {
            if ($gif[$i] === "\x21" && $gif[$i + 1] === "\xf9" && $gif[$i + 2] === "\x04") {
                $low = ord($gif[$i + 4]);
                $high = ord($gif[$i + 5]);
                $delays[] = $low | ($high << 8);
                $i += 8;
                continue;
            }
            $i++;
        }
        return $delays;
    }
}
