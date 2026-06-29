<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\KittyOptions;
use SugarCraft\Mosaic\Renderer\KittyRenderer;

final class KittyZlibTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KittyRenderer();
    }

    public function testCompressFlagEmitsF1InBegin(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringContainsString('f=1', $out);
    }

    public function testCompressedPayloadIsActuallyCompressed(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Extract base64-encoded chunks from the Kitty graphics output.
        // Chunks appear as: m=1,<base64> (more coming) or m=0,<base64> (final).
        if (!preg_match_all('/m=[01],([A-Za-z0-9+\/=]+)/', $out, $matches)) {
            $this->fail('No Kitty graphics chunks found in output');
        }

        $fullBase64 = implode('', $matches[1]);
        $compressed = base64_decode($fullBase64);
        $this->assertNotFalse($compressed, 'Base64 decode failed');

        $decompressed = @gzuncompress($compressed);
        $this->assertNotFalse($decompressed, 'Payload is not valid zlib-compressed data');

        // Verify decompressed data starts with PNG header
        $this->assertStringStartsWith("\x89PNG", $decompressed);
    }

    public function testUncompressedPayloadIsNotCompressed(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1); // compress = 100 (no compression)

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Extract base64-encoded chunks
        if (!preg_match_all('/m=[01],([A-Za-z0-9+\/=]+)/', $out, $matches)) {
            $this->fail('No Kitty graphics chunks found in output');
        }

        $fullBase64 = implode('', $matches[1]);
        $raw = base64_decode($fullBase64);
        $this->assertNotFalse($raw, 'Base64 decode failed');

        // Without compression, the raw data should be a valid PNG
        $this->assertStringStartsWith("\x89PNG", $raw);
    }

    public function testCompressionWithUseVirtual(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1)->withUseVirtual(true);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Both compression and virtual placement should work together
        $this->assertStringContainsString('a=p', $out);
        $this->assertStringContainsString('f=1', $out);
    }

    public function testNoCompressionFlagWhenCompressIs100(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(100);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // f=100 (default=no compression) should be in the output as the Kitty protocol default
        $this->assertStringContainsString('f=100', $out);
        // f=1 (zlib compression) should NOT be present as a standalone value (not a substring of f=100)
        $this->assertDoesNotMatchRegularExpression('/\bf=1\b/', $out);
    }

    public function testCompressionSuccessEmitsF1AndNonEmptyPayload(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // f=1 flag must be present.
        $this->assertStringContainsString('f=1', $out);

        // Extract and verify the payload decodes and decompresses to source PNG bytes.
        if (!preg_match_all('/m=[01],([A-Za-z0-9+\/=]+)/', $out, $matches)) {
            $this->fail('No Kitty graphics chunks found in output');
        }

        $fullBase64 = implode('', $matches[1]);
        $this->assertNotEmpty($fullBase64, 'Payload base64 must be non-empty');

        $compressed = base64_decode($fullBase64);
        $this->assertNotFalse($compressed, 'Base64 decode failed');

        $decompressed = @gzuncompress($compressed);
        $this->assertNotFalse($decompressed, 'Payload is not valid zlib-compressed data');

        // Must round-trip back to the source PNG bytes exactly.
        $this->assertSame($image->bytes, $decompressed);
    }
}
