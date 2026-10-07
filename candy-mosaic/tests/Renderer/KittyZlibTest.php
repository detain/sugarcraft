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

    public function testCompressionEmitsOzWithPngFormatInBegin(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Per the kitty spec `o=z` is the transmission-compression key and
        // `f` stays the data format: exact begin-frame byte pin (M2/round-LL).
        $this->assertStringStartsWith("\x1b_Ga=T,i=1,f=100,o=z,q=2,c=8,r=4,m=1;\x1b\\", $out);
    }

    public function testCompressedPayloadIsActuallyCompressed(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // Extract base64-encoded chunks from the Kitty graphics output.
        // Chunks appear as: ESC _ G m=1;<base64> ESC backslash (more) or m=0;<base64> (final).
        if (!preg_match_all('/\x1b_Gm=[01];([A-Za-z0-9+\/=]+)\x1b\\\\/', $out, $matches)) {
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
        if (!preg_match_all('/\x1b_Gm=[01];([A-Za-z0-9+\/=]+)\x1b\\\\/', $out, $matches)) {
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
        $this->assertStringContainsString('o=z', $out);
    }

    public function testNoCompressionFlagWhenCompressIs100(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(100);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // f=100 (PNG data format) is always emitted; the o key is omitted
        // entirely when no compression is requested.
        $this->assertStringContainsString('f=100', $out);
        $this->assertStringNotContainsString('o=', $out);
    }

    public function testNonZlibCompressionValueIsInertOnTheWire(): void
    {
        // The wire carries no compression LEVEL — only the o=z zlib signal —
        // so any value other than 1 (or the 100 default) must produce a plain
        // f=100 transmit rather than mislabelling the payload as a format.
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(5);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        $this->assertStringStartsWith("\x1b_Ga=T,i=1,f=100,q=2,c=8,r=4,m=1;\x1b\\", $out);
        $this->assertStringNotContainsString('o=', $out);

        if (!preg_match_all('/\x1b_Gm=[01];([A-Za-z0-9+\/=]+)\x1b\\\\/', $out, $matches)) {
            $this->fail('No Kitty graphics chunks found in output');
        }
        $raw = base64_decode(implode('', $matches[1]));
        $this->assertNotFalse($raw, 'Base64 decode failed');
        $this->assertStringStartsWith("\x89PNG", $raw, 'inert level must leave the payload uncompressed');
    }

    public function testCompressionSuccessEmitsOzAndNonEmptyPayload(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../fixtures/4x2.png');
        $opts  = KittyOptions::transmit(1)->withCompression(1);

        $out = $this->renderer->renderWithOptions($image, 8, 4, $opts);

        // o=z must be present and f must stay the PNG format code.
        $this->assertStringContainsString('o=z', $out);
        $this->assertStringContainsString('f=100', $out);

        // Extract and verify the payload decodes and decompresses to source PNG bytes.
        if (!preg_match_all('/\x1b_Gm=[01];([A-Za-z0-9+\/=]+)\x1b\\\\/', $out, $matches)) {
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
