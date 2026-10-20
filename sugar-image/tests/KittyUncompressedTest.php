<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\KittyRenderer;
use SugarCraft\Image\Support\GdAvailability;

/**
 * F-13: the kitty `f=32` raw-RGBA transmission — wire shape, GD-free
 * ingress, and the clean GD-absence refusal on the decode ingress.
 *
 * The GD-absence legs run through {@see GdAvailability::override()} —
 * the lib's code-path seam (the box HAS gd; `php -d disable_functions`
 * is not how this suite simulates it, and would not survive the linked
 * vendor tree anyway).
 */
final class KittyUncompressedTest extends TestCase
{
    private KittyRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new KittyRenderer();
    }

    protected function tearDown(): void
    {
        GdAvailability::reset();
    }

    public function testTheUncompressedFrameOpensWithCellsFormatAndPixelExtent(): void
    {
        $rgba = $this->frame(8, 4);
        $out  = $this->renderer->renderUncompressed($this->pngSource(), 8, 4, $rgba);

        self::assertStringStartsWith("\x1b_Gc=8,r=4,f=32,s=8,v=4,m=1;", $out);
    }

    public function testTheWholePayloadRidesOnePaddingFreeBase64ChunkThatClosesTheTransaction(): void
    {
        $rgba = $this->frame(8, 4);
        $out  = $this->renderer->renderUncompressed($this->pngSource(), 8, 4, $rgba);

        // 128 raw bytes ≤ the 2967 budget: one m=0 chunk, nothing after.
        self::assertSame(
            "\x1b_Gc=8,r=4,f=32,s=8,v=4,m=1;" . "\x1b\\"
            . "\x1b_Gm=0;" . base64_encode($rgba) . "\x1b\\",
            $out,
        );
    }

    public function testAPayloadPastTheChunkBudgetSlicesOnRawBytesWithOpenChunks(): void
    {
        // 2968 raw bytes (112 pixels… any >2967): first slice m=1 at the
        // full 2967-byte bound, remainder closes with m=0. Quads straddle
        // the slice boundary — legal, the terminal concatenates first.
        $rgba = $this->frame(15, 50); // 750 px → 3000 bytes
        $out  = $this->renderer->renderUncompressed($this->sizedSource(15, 50), 15, 12, $rgba);

        $first = base64_encode(substr($rgba, 0, 2967));
        self::assertStringContainsString("\x1b_Gm=1;" . $first . "\x1b\\", $out);
        self::assertStringEndsWith("\x1b_Gm=0;" . base64_encode(substr($rgba, 2967)) . "\x1b\\", $out);
    }

    public function testTheRawPathTransmitsWithGdDeclaredAbsent(): void
    {
        GdAvailability::override(false);
        $rgba = $this->frame(8, 4);

        $out = $this->renderer->renderUncompressed($this->pngSource(), 8, 4, $rgba);

        self::assertStringContainsString('f=32', $out);
        self::assertStringContainsString(base64_encode($rgba), $out);
    }

    public function testTheDecodeIngressRefusesCleanlyWhenGdIsAbsent(): void
    {
        GdAvailability::override(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-gd is unavailable and no raw RGBA buffer was supplied');

        $this->renderer->renderUncompressed($this->pngSource(), 8, 4);
    }

    public function testTheDecodeIngressProducesTheSameBytesAsTheEquivalentRawBuffer(): void
    {
        $source  = $this->pngSource();
        $decoded = $this->renderer->renderUncompressed($source, 8, 4);

        // Re-encode the same pixels straight from GD for the comparison —
        // both legs must agree because they share resolveRgba().
        $img  = imagecreatefromstring($source->bytes);
        self::assertInstanceOf(\GdImage::class, $img);
        $rgba = \SugarCraft\Image\Codec\RawRgba::fromGdImage($img);
        imagedestroy($img);

        $explicit = $this->renderer->renderUncompressed($source, 8, 4, $rgba);
        self::assertSame($explicit, $decoded);
    }

    public function testTheDefaultPngTransmissionIsUntouchedByTheRawPath(): void
    {
        $source = $this->pngSource();

        // f=100, no s/v, PNG bytes passthrough — the historical shape.
        $out = $this->renderer->render($source, 8, 4);

        self::assertStringStartsWith("\x1b_Gc=8,r=4,f=100,m=1;", $out);
        self::assertStringNotContainsString('f=32', $out);
    }

    private function frame(int $w, int $h): string
    {
        $rgba = '';
        for ($i = 0; $i < $w * $h; $i++) {
            $rgba .= chr($i & 0xFF) . chr(($i >> 8) & 0xFF) . "\xff" . "\xff";
        }

        return $rgba;
    }

    private function pngSource(): ImageSource
    {
        return ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
    }

    private function sizedSource(int $w, int $h): ImageSource
    {
        $bytes = file_get_contents(__DIR__ . '/fixtures/8x4_red.png');
        self::assertIsString($bytes);

        // The raw payload is validated against the SOURCE grid, so a
        // 15×50 geometry needs a matching ImageSource; the container bytes
        // are irrelevant on the explicit-buffer leg.
        return new ImageSource($bytes, 'image/png', $w, $h);
    }
}
