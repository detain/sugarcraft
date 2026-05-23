<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\FfmpegGifEncoder;
use SugarCraft\Vcr\Encode\GifEncoder;

/**
 * Tests for FfmpegGifEncoder.
 */
final class FfmpegGifEncoderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy-vcr-test-' . getmypid();
        if (!mkdir($this->tmpDir) && !is_dir($this->tmpDir)) {
            throw new \RuntimeException('Failed to create temp dir');
        }
    }

    protected function tearDown(): void
    {
        $files = glob($this->tmpDir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);
    }

    public function testIsAvailableReturnsBool(): void
    {
        $encoder = new FfmpegGifEncoder();
        $result = $encoder->isAvailable();

        $this->assertIsBool($result);
    }

    public function testNameReturnsString(): void
    {
        $encoder = new FfmpegGifEncoder();
        $this->assertEquals('ffmpeg', $encoder->name());
    }

    public function testNameIsFfmpeg(): void
    {
        $encoder = new FfmpegGifEncoder();
        $this->assertSame('ffmpeg', $encoder->name());
    }

    public function testCustomBinName(): void
    {
        $encoder = new FfmpegGifEncoder('/usr/local/bin/ffmpeg');
        $this->assertSame('ffmpeg', $encoder->name());
    }

    public function testEncodeRequiresFrames(): void
    {
        $encoder = new FfmpegGifEncoder();
        $outputPath = $this->tmpDir . '/out.gif';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No frames provided');
        $encoder->encode([], $outputPath);
    }

    public function testEncodeThrowsWhenFfmpegUnavailable(): void
    {
        $encoder = new FfmpegGifEncoder('/nonexistent/ffmpeg');
        if ($encoder->isAvailable()) {
            $this->markTestSkipped('ffmpeg is actually available');
        }

        $pngPath = $this->createPngFrame('test');
        $outputPath = $this->tmpDir . '/out.gif';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');
        $encoder->encode([$pngPath], $outputPath);
    }

    public function testEncodeSingleFrame(): void
    {
        $encoder = new FfmpegGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $pngPath = $this->createPngFrame('A');
        $outputPath = $this->tmpDir . '/out.gif';

        $result = $encoder->encode([$pngPath], $outputPath, 30);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));
    }

    public function testEncodeMultipleFrames(): void
    {
        $encoder = new FfmpegGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $frames = [
            $this->createPngFrame('A'),
            $this->createPngFrame('B'),
            $this->createPngFrame('C'),
        ];
        $outputPath = $this->tmpDir . '/out.gif';

        $result = $encoder->encode($frames, $outputPath, 10);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);
        $this->assertGreaterThan(0, filesize($outputPath));
    }

    public function testEncodeOutputIsNotEmpty(): void
    {
        $encoder = new FfmpegGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $pngPath = $this->createPngFrame('X');
        $outputPath = $this->tmpDir . '/out.gif';

        $encoder->encode([$pngPath], $outputPath, 30);

        $content = file_get_contents($outputPath);
        $this->assertNotEmpty($content);
        $this->assertStringStartsWith('GIF89a', $content);
    }

    public function testEncodeWithDurations(): void
    {
        $encoder = new FfmpegGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('ffmpeg not available');
        }

        $frames = [
            $this->createPngFrame('A'),
            $this->createPngFrame('B'),
        ];
        $outputPath = $this->tmpDir . '/out.gif';
        $durations = [100, 200];

        $result = $encoder->encode($frames, $outputPath, 30, $durations);

        $this->assertTrue($result);
        $this->assertFileExists($outputPath);
    }

    public function testImplementsGifEncoderInterface(): void
    {
        // Compile-time guarantee — `FfmpegGifEncoder implements GifEncoder`
        // is enforced by the type system. Use a reflection check so the
        // test still has runtime value if someone re-shuffles interfaces.
        $impls = class_implements(FfmpegGifEncoder::class);
        $this->assertIsArray($impls);
        $this->assertArrayHasKey(GifEncoder::class, $impls);
    }

    /**
     * @return non-empty-string
     */
    private function createPngFrame(string $char): string
    {
        $img = imagecreatetruecolor(8, 16);
        $this->assertNotFalse($img);
        $bg = imagecolorallocate($img, 0, 0, 0);
        $fg = imagecolorallocate($img, 255, 255, 255);
        $this->assertNotFalse($bg);
        $this->assertNotFalse($fg);
        imagefilledrectangle($img, 0, 0, 7, 15, $bg);
        imagettftext($img, 14, 0, 0, 13, $fg, $this->getFontPath(), $char);

        $path = $this->tmpDir . '/frame_' . md5($char . microtime()) . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function getFontPath(): string
    {
        $font = __DIR__ . '/../../fonts/DejaVuSansMono.ttf';
        if (is_file($font)) {
            return $font;
        }

        $systemFonts = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf',
            '/usr/share/fonts/truetype/freefont/FreeMono.ttf',
            '/usr/share/fonts/truetype/noto/NotoSansMono-Regular.ttf',
        ];

        foreach ($systemFonts as $f) {
            if (is_file($f)) {
                return $f;
            }
        }

        return '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';
    }
}
