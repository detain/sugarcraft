<?php

declare(strict_types=1);

namespace SugarCraft\Flip\Tests;

use SugarCraft\Flip\Downsampler;
use PHPUnit\Framework\TestCase;

final class DownsamplerTest extends TestCase
{
    public function testAreaAverageModeReturnsGridOfCorrectDimensions(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $img = imagecreatetruecolor(4, 4);
        // Fill with red.
        imagefilledrectangle($img, 0, 0, 3, 3, imagecolorallocate($img, 255, 0, 0));

        $result = Downsampler::downsample($img, 2, 2, Downsampler::AREA_AVERAGE);

        $this->assertCount(2, $result);
        $this->assertCount(2, $result[0]);
        imagedestroy($img);
    }

    public function testAreaAverageProducesSmootherResultThanNearest(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        // 4×4 image: top-left quadrant red, top-right green, bottom-left blue, bottom-right white.
        $img = imagecreatetruecolor(4, 4);
        imagefilledrectangle($img, 0, 0, 1, 1, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 2, 0, 3, 1, imagecolorallocate($img, 0, 255, 0));
        imagefilledrectangle($img, 0, 2, 1, 3, imagecolorallocate($img, 0, 0, 255));
        imagefilledrectangle($img, 2, 2, 3, 3, imagecolorallocate($img, 255, 255, 255));

        $nearest = Downsampler::downsample($img, 2, 2, Downsampler::NEAREST);
        $average = Downsampler::downsample($img, 2, 2, Downsampler::AREA_AVERAGE);

        // Nearest-neighbor picks exact center of each quadrant.
        $this->assertSame(255, $nearest[0][0][0]); // red
        $this->assertSame(255, $nearest[0][1][1]); // green
        $this->assertSame(255, $nearest[1][0][2]); // blue
        $this->assertSame(255, $nearest[1][1][2]); // white b=255

        // Area-average for a 2×2 downsample of a 4×4 means each cell covers 2×2 source pixels.
        // Top-left cell averages the 2×2 red pixels → [255, 0, 0].
        $this->assertSame(255, $average[0][0][0]);
        $this->assertSame(0,   $average[0][0][1]);
        $this->assertSame(0,  $average[0][0][2]);
        imagedestroy($img);
    }

    public function testNearestModeReturnsGridOfCorrectDimensions(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not available');
        }
        $img = imagecreatetruecolor(6, 4);
        imagefilledrectangle($img, 0, 0, 5, 3, imagecolorallocate($img, 128, 64, 32));

        $result = Downsampler::downsample($img, 3, 2, Downsampler::NEAREST);

        $this->assertCount(2, $result);
        $this->assertCount(3, $result[0]);
        imagedestroy($img);
    }
}
