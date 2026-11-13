<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Encode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Encode\GifEncoder;
use SugarCraft\Vcr\Encode\PhpGifEncoder;
use SugarCraft\Vcr\Tests\Support\Stream;

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

    /**
     * Byte-identity contract for the integer-key LZW rewrite (VCR-S17).
     *
     * These md5 values were captured from the PREVIOUS string-keyed
     * implementation on this exact input set — the zero-golden-movement law
     * for the encoder: the code stream the new key scheme emits must equal
     * the old one forever. If one of these moves, the rewrite (or a later
     * change) altered encoded bytes and the goldens need a disclosed re-pin.
     */
    public function testLzwOutputIsByteIdenticalToTheStringKeyedOracle(): void
    {
        $encoder = new PhpGifEncoder();
        $method = new \ReflectionMethod($encoder, 'lzwEncode');

        mt_srand(4242);
        $seeded = '';
        for ($i = 0; $i < 14000; $i++) {
            $seeded .= chr(mt_rand(0, 255));
        }

        $pins = [
            'long-runs' => [str_repeat(chr(7), 300) . str_repeat(chr(9), 200) . str_repeat(chr(7), 100), '871077b22e03ef18b4984dcf4eaf7b27'],
            'uniform' => [str_repeat(chr(0), 65536), '855c3e0fe8be4f81aa9a34f891ec4ac6'],
            'seeded-random' => [$seeded, '4d7220bc08790a059e5e5b8393af5255'],
            'gradient' => [implode('', array_map(static fn (int $i): string => chr($i % 251), range(0, 3999))), 'a11720c35ba3d8e69d0d2af40c562bd4'],
            'interleaved' => [str_repeat("AB\x00\xFF\x00", 500), '2dfb4d27658fdc119a65744e000c6554'],
            'prose' => [str_repeat('the quick brown fox jumps over the lazy dog ', 40), '08071ec301d6ded0db96d054bb569924'],
            'empty' => ['', '3ab2dcf9ff5b8e69662981b6ac1e848c'],
            'one' => [chr(3), 'fa0cb70da54973bce8a4ae776fc9855f'],
        ];

        foreach ($pins as $name => $pinPair) {
            self::assertIsArray($pinPair);
            $data = $pinPair[0];
            $pin = $pinPair[1];
            self::assertIsString($data);
            self::assertIsString($pin);
            $encoded = $method->invoke($encoder, $data, 8);
            self::assertIsString($encoded);
            $this->assertSame($pin, md5($encoded), "LZW output for '{$name}' must stay byte-identical");
        }
    }

    /**
     * Slope guard (VCR-S17): the old string-keyed loop was superlinear on
     * long uniform runs (≈2 KB of churn per pixel at chain length). Encoding
     * a multi-megabyte uniform stream — the dominant shape of real terminal
     * frames — must stay comfortably bounded. Measured on the rewrite lane:
     * 1.92 MB ≈ 0.57 s; the 8 s ceiling only trips on a regression back to
     * quadratic behaviour (which would need minutes).
     */
    public function testLzwEncodesMultiMegabyteUniformRunsWithinBounds(): void
    {
        $encoder = new PhpGifEncoder();
        $method = new \ReflectionMethod($encoder, 'lzwEncode');

        $data = str_repeat(chr(32), 1920000);
        $start = microtime(true);
        $out = $method->invoke($encoder, $data, 8);
        $elapsed = microtime(true) - $start;

        $this->assertNotSame('', $out);
        $this->assertLessThan(8.0, $elapsed, sprintf('1.92 MB uniform encode took %.2f s — quadratic slope is back', $elapsed));
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

        $content = Stream::read($outputPath);
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
        $bytes = Stream::read($outputPath);
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
