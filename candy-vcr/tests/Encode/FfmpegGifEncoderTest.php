<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\FfmpegGifEncoder;

/**
 * Tests for FfmpegGifEncoder.
 */
final class FfmpegGifEncoderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir();

        if (!$this->isFfmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }
    }

    public function testEncodeProducesValidGif(): void
    {
        $encoder = new FfmpegGifEncoder('ffmpeg', $this->tempDir);

        $frames = [];
        for ($i = 0; $i < 3; $i++) {
            $img = imagecreatetruecolor(10, 10);
            $color = imagecolorallocate($img, $i * 80, 100, 150);
            imagefilledrectangle($img, 0, 0, 9, 9, $color);
            $frames[] = $img;
        }

        $frameHolds = [0.033, 0.033, 0.033];
        $outputPath = $this->tempDir . '/test_encode_' . uniqid() . '.gif';

        $framesIter = new \ArrayIterator($frames);
        $encoder->encode($framesIter, 10, 10, $frameHolds, $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));

        $header = file_get_contents($outputPath, false, null, 0, 6);
        $this->assertSame('GIF89a', $header);

        foreach ($frames as $img) {
            imagedestroy($img);
        }
        @unlink($outputPath);
    }

    public function testEncodeWithVaryingFrameHolds(): void
    {
        $encoder = new FfmpegGifEncoder('ffmpeg', $this->tempDir);

        $frames = [];
        for ($i = 0; $i < 3; $i++) {
            $img = imagecreatetruecolor(10, 10);
            $color = imagecolorallocate($img, $i * 80, 100, 150);
            imagefilledrectangle($img, 0, 0, 9, 9, $color);
            $frames[] = $img;
        }

        $frameHolds = [0.033, 0.100, 0.050];
        $outputPath = $this->tempDir . '/test_vfr_' . uniqid() . '.gif';

        $framesIter = new \ArrayIterator($frames);
        $encoder->encode($framesIter, 10, 10, $frameHolds, $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));

        $header = file_get_contents($outputPath, false, null, 0, 6);
        $this->assertSame('GIF89a', $header);

        foreach ($frames as $img) {
            imagedestroy($img);
        }
        @unlink($outputPath);
    }

    public function testEncodeEmptyFrames(): void
    {
        $encoder = new FfmpegGifEncoder('ffmpeg', $this->tempDir);

        $framesIter = new \EmptyIterator();
        $frameHolds = [];
        $outputPath = $this->tempDir . '/test_empty_' . uniqid() . '.gif';

        $encoder->encode($framesIter, 10, 10, $frameHolds, $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));

        @unlink($outputPath);
    }

    public function testEncodeThrowsRuntimeExceptionOnFfmpegFailure(): void
    {
        $encoder = new FfmpegGifEncoder('nonexistent-ffmpeg', $this->tempDir);

        $frames = [];
        $img = imagecreatetruecolor(10, 10);
        $frames[] = $img;

        $frameHolds = [0.033];
        $outputPath = $this->tempDir . '/test_fail_' . uniqid() . '.gif';

        $framesIter = new \ArrayIterator($frames);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ffmpeg failed');

        try {
            $encoder->encode($framesIter, 10, 10, $frameHolds, $outputPath);
        } finally {
            imagedestroy($img);
            @unlink($outputPath);
        }
    }

    public function testEncodeCleansUpTempFilesOnSuccess(): void
    {
        $encoder = new FfmpegGifEncoder('ffmpeg', $this->tempDir);

        $frames = [];
        for ($i = 0; $i < 3; $i++) {
            $img = imagecreatetruecolor(10, 10);
            $color = imagecolorallocate($img, $i * 80, 100, 150);
            imagefilledrectangle($img, 0, 0, 9, 9, $color);
            $frames[] = $img;
        }

        $frameHolds = [0.033, 0.033, 0.033];
        $outputPath = $this->tempDir . '/test_cleanup_' . uniqid() . '.gif';

        $framesIter = new \ArrayIterator($frames);
        $encoder->encode($framesIter, 10, 10, $frameHolds, $outputPath);

        $tempPngs = glob($this->tempDir . '/frame*.png');
        $this->assertEmpty($tempPngs, 'Temp PNG files should be cleaned up');

        foreach ($frames as $img) {
            imagedestroy($img);
        }
        @unlink($outputPath);
    }

    private function isFfmpegAvailable(): bool
    {
        $process = \Symfony\Component\Process\Process::fromShellCommandline('ffmpeg -version');
        $process->run();
        return $process->isSuccessful();
    }
}
