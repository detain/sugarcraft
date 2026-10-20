<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Codec\TiffUncompressed;
use SugarCraft\Image\Renderer\Iterm2Renderer;
use SugarCraft\Image\Support\GdAvailability;

/**
 * F-13: the iTerm2 `type=tiff` transmission — envelope shape, TIFF
 * payload round-trip, byte-stable PNG passthrough, and both GD-absence
 * branches (passthrough survives, re-encode refuses cleanly).
 */
final class Iterm2TiffTest extends TestCase
{
    private Iterm2Renderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new Iterm2Renderer();
    }

    protected function tearDown(): void
    {
        GdAvailability::reset();
    }

    public function testTheTiffEnvelopeAnnouncesItsTypeBeforeTheInlineFlag(): void
    {
        $rgba = $this->redFrame();

        $out = $this->renderer->renderTiff($this->pngSource(), 8, 4, $rgba);

        self::assertStringStartsWith(
            "\x1b]1337;File=width=8;height=4;preserveAspectRatio=1;type=tiff;inline=1:",
            $out,
        );
        self::assertStringEndsWith("\x07", $out);
    }

    public function testThePayloadDecodesToTheTiffContainerCarryingTheGivenPixels(): void
    {
        $rgba = $this->redFrame();
        $out  = $this->renderer->renderTiff($this->pngSource(), 8, 4, $rgba);

        $prefix = "\x1b]1337;File=width=8;height=4;preserveAspectRatio=1;type=tiff;inline=1:";
        $b64    = substr($out, strlen($prefix), -1); // drop trailing BEL
        $tiff   = base64_decode((string) $b64, true);

        self::assertIsString($tiff);
        self::assertSame('II', substr($tiff, 0, 2));
        self::assertSame(
            TiffUncompressed::encode($rgba, 8, 4),
            $tiff,
        );
    }

    public function testTheTiffPathNeedsNoGdWhatsoever(): void
    {
        GdAvailability::override(false);

        $out = $this->renderer->renderTiff($this->pngSource(), 8, 4, $this->redFrame());

        self::assertStringContainsString('type=tiff', $out);
    }

    public function testTheTiffDecodeIngressRefusesCleanlyWhenGdIsAbsent(): void
    {
        GdAvailability::override(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-gd is unavailable');

        $this->renderer->renderTiff($this->pngSource(), 8, 4);
    }

    public function testThePngPassthroughEnvelopeIsByteIdenticalWithGdAbsent(): void
    {
        GdAvailability::override(false);
        $source = $this->pngSource(); // PNG ingress long since loaded; render() itself needs no GD here

        $out = $this->renderer->render($source, 8, 4);

        self::assertSame(
            "\x1b]1337;File=width=8;height=4;preserveAspectRatio=1;inline=1:"
            . base64_encode((string) file_get_contents(__DIR__ . '/fixtures/8x4_red.png'))
            . "\x07",
            $out,
        );
    }

    public function testTheNonPngReEncodeRefusesCleanlyWhenGdIsAbsent(): void
    {
        $img = imagecreatetruecolor(8, 4);
        self::assertInstanceOf(\GdImage::class, $img);
        ob_start();
        imagejpeg($img);
        $jpeg = (string) ob_get_clean();
        imagedestroy($img);

        $source = new ImageSource($jpeg, 'image/jpeg', 8, 4);

        GdAvailability::override(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ext-gd is unavailable');

        $this->renderer->render($source, 8, 4);
    }

    public function testTheGdDecodedTiffLegAgreesWithTheEquivalentExplicitBuffer(): void
    {
        $source   = $this->pngSource();
        $auto     = $this->renderer->renderTiff($source, 8, 4);

        $img = imagecreatefromstring($source->bytes);
        self::assertInstanceOf(\GdImage::class, $img);
        $rgba = \SugarCraft\Image\Codec\RawRgba::fromGdImage($img);
        imagedestroy($img);

        self::assertSame($this->renderer->renderTiff($source, 8, 4, $rgba), $auto);
    }

    private function redFrame(): string
    {
        return str_repeat("\xff\x00\x00\xff", 32); // 8×4 opaque red
    }

    private function pngSource(): ImageSource
    {
        return ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
    }
}
