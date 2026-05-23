<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\TapeToGif;

/**
 * Regression: theme propagation end-to-end.
 *
 * Bug fixed in d070e742: `Set Theme "TokyoNight"` in a tape used to be
 * lost — the rasterizer always reached for the basic VGA palette so the
 * rendered background was 0x000000 regardless of theme. The fix routes
 * the cassette header's resolved theme into a `withTheme()`-cloned
 * rasterizer in `TapeToGif::render()`.
 *
 * This pixel-sampling test fails if the bug regresses (bg drifts back
 * toward pure black) by checking that an empty cell of a tokyoNight
 * tape is closer to 0x15161e than 0x000000.
 */
final class TapeToGifThemeTest extends TestCase
{
    public function testTokyoNightBackgroundReachesRenderedGif(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }

        $tapeDir = sys_get_temp_dir() . '/candy-vcr-theme-' . bin2hex(random_bytes(4));
        if (!mkdir($tapeDir, 0700, true) && !is_dir($tapeDir)) {
            $this->fail("Failed to create temp dir: {$tapeDir}");
        }

        $tape = $tapeDir . '/tn.tape';
        $gif = $tapeDir . '/tn.gif';
        file_put_contents(
            $tape,
            "Set Theme \"TokyoNight\"\nSet FontSize 14\nSet Width 20\nSet Height 5\nType \"X\"\nEnter\nSleep 100ms\n",
        );

        try {
            $renderer = TapeToGif::create(['encoder' => 'php', 'backend' => 'gd']);
            $renderer->render($tape, $gif, ['encoder' => 'php', 'backend' => 'gd']);

            $this->assertFileExists($gif);

            $image = @imagecreatefromgif($gif);
            $this->assertInstanceOf(\GdImage::class, $image, 'GIF should be decodable');

            // Sample a pixel in the far-right region of the first row —
            // far past "X\n" output, where the cell should be the
            // theme-default background.
            $w = imagesx($image);
            $h = imagesy($image);
            $sampleX = (int) ($w * 0.9);
            $sampleY = (int) ($h * 0.5);

            $rgb = imagecolorat($image, $sampleX, $sampleY);
            $this->assertNotFalse($rgb);
            $rgba = imagecolorsforindex($image, $rgb);
            imagedestroy($image);

            $sampledHex = ($rgba['red'] << 16) | ($rgba['green'] << 8) | $rgba['blue'];

            $tokyoNightBg = 0x15161e;
            $vgaBg = 0x000000;

            $distTokyo = $this->hexDist($sampledHex, $tokyoNightBg);
            $distVga = $this->hexDist($sampledHex, $vgaBg);

            $this->assertLessThan(
                $distVga + 1,
                $distTokyo,
                sprintf(
                    'Sampled bg pixel #%06x is farther from TokyoNight (%d) than from VGA (%d) — theme propagation regressed.',
                    $sampledHex,
                    $distTokyo,
                    $distVga,
                ),
            );
            // TokyoNight bg = 0x15161e (21,22,30); pure black would have
            // dist 73 to TokyoNight. Allow generous slack (<= 30) to
            // tolerate dithering / palette quantization.
            $this->assertLessThanOrEqual(
                30,
                $distTokyo,
                sprintf(
                    'Sampled bg #%06x should be near TokyoNight 0x15161e — got distance %d',
                    $sampledHex,
                    $distTokyo,
                ),
            );
        } finally {
            @unlink($tape);
            @unlink($gif);
            @rmdir($tapeDir);
        }
    }

    private function hexDist(int $a, int $b): int
    {
        $ra = ($a >> 16) & 0xff;
        $ga = ($a >> 8) & 0xff;
        $ba = $a & 0xff;
        $rb = ($b >> 16) & 0xff;
        $gb = ($b >> 8) & 0xff;
        $bb = $b & 0xff;
        return abs($ra - $rb) + abs($ga - $gb) + abs($ba - $bb);
    }
}
