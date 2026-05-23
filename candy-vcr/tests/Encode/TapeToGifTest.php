<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\FfmpegGifEncoder;
use SugarCraft\Vcr\Encode\GifEncoder;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Encode\TapeToGif;

/**
 * Tests for TapeToGif pipeline.
 */
final class TapeToGifTest extends TestCase
{
    private string $tempDir;
    private string $smokeTape;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir();
        $this->smokeTape = __DIR__ . '/../../.vhs/smoke.tape';
    }

    public function testRenderSmokeTapeProducesValidGif(): void
    {
        if (!$this->isFfmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        if (!file_exists($this->smokeTape)) {
            $this->markTestSkipped('smoke.tape not found');
        }

        $tapeToGif = TapeToGif::create(['encoder' => 'ffmpeg']);

        $outputPath = $this->tempDir . '/smoke_' . uniqid() . '.gif';

        try {
            $tapeToGif->render($this->smokeTape, $outputPath);

            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            $header = file_get_contents($outputPath, false, null, 0, 6);
            $this->assertSame('GIF89a', $header);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testRenderWithCustomOptions(): void
    {
        if (!$this->isFfmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        if (!file_exists($this->smokeTape)) {
            $this->markTestSkipped('smoke.tape not found');
        }

        $tapeToGif = TapeToGif::create([
            'fps' => 30.0,
            'theme' => 'TokyoNight',
            'fontSize' => 14,
            'backend' => 'gd',
            'encoder' => 'ffmpeg',
        ]);

        $outputPath = $this->tempDir . '/smoke_opts_' . uniqid() . '.gif';

        try {
            $tapeToGif->render($this->smokeTape, $outputPath, [
                'fps' => 30.0,
                'theme' => 'TokyoNight',
                'fontSize' => 14,
            ]);

            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            $header = file_get_contents($outputPath, false, null, 0, 6);
            $this->assertSame('GIF89a', $header);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testRenderDefaultOutputPath(): void
    {
        if (!$this->isFfmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        if (!file_exists($this->smokeTape)) {
            $this->markTestSkipped('smoke.tape not found');
        }

        $tapeToGif = TapeToGif::create(['encoder' => 'ffmpeg']);
        $outputPath = (string) preg_replace('/\.tape$/', '.gif', $this->smokeTape);

        try {
            $tapeToGif->render($this->smokeTape, null);

            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            $header = file_get_contents($outputPath, false, null, 0, 6);
            $this->assertSame('GIF89a', $header);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testRenderWithPhpEncoderProducesValidGif(): void
    {
        if (!file_exists($this->smokeTape)) {
            $this->markTestSkipped('smoke.tape not found');
        }

        $tapeToGif = TapeToGif::create(['encoder' => 'php']);

        $outputPath = $this->tempDir . '/smoke_php_' . uniqid() . '.gif';

        try {
            $tapeToGif->render($this->smokeTape, $outputPath);

            $this->assertFileExists($outputPath);
            $this->assertGreaterThan(0, filesize($outputPath));

            $header = file_get_contents($outputPath, false, null, 0, 6);
            $this->assertSame('GIF89a', $header);
        } finally {
            @unlink($outputPath);
        }
    }

    public function testRenderThrowsForNonexistentTape(): void
    {
        $tapeToGif = TapeToGif::create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read tape file');

        $tapeToGif->render('/nonexistent/path.tape', '/tmp/out.gif');
    }

    public function testCreateWithPhpEncoderUsesPhpGifEncoder(): void
    {
        $tapeToGif = TapeToGif::create(['encoder' => 'php']);

        $reflection = new \ReflectionClass($tapeToGif);
        $encoderProp = $reflection->getProperty('encoder');
        $encoderProp->setAccessible(true);
        $encoder = $encoderProp->getValue($tapeToGif);

        $this->assertInstanceOf(PhpGifEncoder::class, $encoder);
    }

    public function testCreateWithFfmpegEncoderUsesFfmpegGifEncoder(): void
    {
        $tapeToGif = TapeToGif::create(['encoder' => 'ffmpeg']);

        $reflection = new \ReflectionClass($tapeToGif);
        $encoderProp = $reflection->getProperty('encoder');
        $encoderProp->setAccessible(true);
        $encoder = $encoderProp->getValue($tapeToGif);

        $this->assertInstanceOf(FfmpegGifEncoder::class, $encoder);
    }

    public function testCreateWithImagickBackendUsesImagickRasterizer(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('imagick extension not available');
        }

        $tapeToGif = TapeToGif::create(['backend' => 'imagick']);

        $reflection = new \ReflectionClass($tapeToGif);
        $rasterizerProp = $reflection->getProperty('rasterizer');
        $rasterizerProp->setAccessible(true);
        $rasterizer = $rasterizerProp->getValue($tapeToGif);

        $this->assertInstanceOf(\SugarCraft\Vcr\Raster\ImagickRasterizer::class, $rasterizer);
    }

    private function isFfmpegAvailable(): bool
    {
        $process = \Symfony\Component\Process\Process::fromShellCommandline('ffmpeg -version');
        $process->run();
        return $process->isSuccessful();
    }

    public function testScreenshotCapturesFrame(): void
    {
        if (!file_exists($this->smokeTape)) {
            $this->markTestSkipped('smoke.tape not found');
        }

        $tmpDir = sys_get_temp_dir() . '/vcr-screenshot-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0755, true);

        $tapePath = $tmpDir . '/test.tape';
        $screenshotPath = $tmpDir . '/shot.png';

        // Create a minimal tape that outputs something then takes a screenshot
        $tapeContent = "Set Theme TokyoNight\nSet Width 80\nSet Height 24\nType \"X\"\nScreenshot {$screenshotPath}\n";
        file_put_contents($tapePath, $tapeContent);

        try {
            $t2g = TapeToGif::create(['encoder' => 'php', 'backend' => 'gd']);
            $outputPath = $tmpDir . '/out.gif';
            $t2g->render($tapePath, $outputPath);

            $this->assertFileExists($screenshotPath, 'Screenshot should have been written');
            // Verify it's a valid PNG
            $img = @imagecreatefrompng($screenshotPath);
            $this->assertNotFalse($img, 'Screenshot should be a valid PNG');
            imagedestroy($img);
        } finally {
            @unlink($tapePath);
            @unlink($screenshotPath);
            @unlink($tmpDir . '/out.gif');
            @rmdir($tmpDir);
        }
    }

    public function testScreenshotWithFfmpegEncoder(): void
    {
        if (!$this->isFfmpegAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        if (!file_exists($this->smokeTape)) {
            $this->markTestSkipped('smoke.tape not found');
        }

        $tmpDir = sys_get_temp_dir() . '/vcr-screenshot-test-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($tmpDir, 0755, true);

        $tapePath = $tmpDir . '/test.tape';
        $screenshotPath = $tmpDir . '/shot.png';

        $tapeContent = "Set Theme TokyoNight\nSet Width 80\nSet Height 24\nType \"X\"\nScreenshot {$screenshotPath}\n";
        file_put_contents($tapePath, $tapeContent);

        try {
            $t2g = TapeToGif::create(['encoder' => 'ffmpeg', 'backend' => 'gd']);
            $outputPath = $tmpDir . '/out.gif';
            $t2g->render($tapePath, $outputPath);

            $this->assertFileExists($screenshotPath, 'Screenshot should have been written with ffmpeg encoder');
            $img = @imagecreatefrompng($screenshotPath);
            $this->assertNotFalse($img, 'Screenshot should be a valid PNG');
            imagedestroy($img);
        } finally {
            @unlink($tapePath);
            @unlink($screenshotPath);
            @unlink($tmpDir . '/out.gif');
            @rmdir($tmpDir);
        }
    }
}
