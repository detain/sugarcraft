<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\GifEncoder;
use SugarCraft\Vcr\Encode\PhpGifEncoder;

/**
 * Tests for PhpGifEncoder.
 */
final class PhpGifEncoderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy-vcr-test-php-' . getmypid();
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

    public function testIsAvailableReturnsTrueWhenGdLoaded(): void
    {
        $encoder = new PhpGifEncoder();
        $this->assertSame(extension_loaded('gd'), $encoder->isAvailable());
    }

    public function testNameReturnsPhp(): void
    {
        $encoder = new PhpGifEncoder();
        $this->assertEquals('php', $encoder->name());
    }

    public function testImplementsGifEncoderInterface(): void
    {
        // Compile-time guarantee — `PhpGifEncoder implements GifEncoder`
        // is enforced by the type system. Use a reflection check so the
        // test still has runtime value if someone re-shuffles interfaces.
        $impls = class_implements(PhpGifEncoder::class);
        $this->assertIsArray($impls);
        $this->assertArrayHasKey(GifEncoder::class, $impls);
    }

    public function testEncodeRequiresFrames(): void
    {
        $encoder = new PhpGifEncoder();
        $outputPath = $this->tmpDir . '/out.gif';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No frames provided');
        $encoder->encode([], $outputPath);
    }

    public function testEncodeSingleFrame(): void
    {
        $encoder = new PhpGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('GD not available');
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
        $encoder = new PhpGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('GD not available');
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

    public function testEncodeOutputIsValidGif(): void
    {
        $encoder = new PhpGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('GD not available');
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
        $encoder = new PhpGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('GD not available');
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

    public function testLzwEncodeProducesCorrectOutput(): void
    {
        $encoder = new PhpGifEncoder();
        if (!$encoder->isAvailable()) {
            $this->markTestSkipped('GD not available');
        }

        $pngPath = $this->createPngFrame('!');
        $outputPath = $this->tmpDir . '/out.gif';

        $encoder->encode([$pngPath], $outputPath, 30);

        $this->assertFileExists($outputPath);
        $bytes = file_get_contents($outputPath);
        $this->assertIsString($bytes);

        $this->assertStringStartsWith('GIF89a', $bytes);
        $this->assertStringContainsString("\x21\xf9", $bytes);
        $this->assertStringContainsString("\x2c", $bytes);
        $this->assertStringEndsWith("\x3b", $bytes);
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

        $fontPath = $this->getFontPath();
        if ($fontPath !== null && is_file($fontPath)) {
            imagettftext($img, 14, 0, 0, 13, $fg, $fontPath, $char);
        } else {
            imagestring($img, 1, 0, 0, $char, $fg);
        }

        $path = $this->tmpDir . '/frame_' . md5($char . microtime()) . '.png';
        imagepng($img, $path);
        imagedestroy($img);

        return $path;
    }

    private function getFontPath(): ?string
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

        return null;
    }
}
