<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\Iterm2Renderer;
use SugarCraft\Mosaic\Renderer\KittyRenderer;

/**
 * `ImageSource::$format` must describe the bytes it travels with. fromString()
 * used to label every format outside its magic-byte list (BMP, WBMP, AVIF …) as
 * `image/png`, and the Kitty (`f=100`) and iTerm2 renderers passed "PNG" bytes
 * straight to the terminal — a silently blank image instead of a re-encode.
 *
 * @covers \SugarCraft\Mosaic\ImageSource
 * @covers \SugarCraft\Mosaic\Renderer\KittyRenderer
 * @covers \SugarCraft\Mosaic\Renderer\Iterm2Renderer
 */
final class ImageSourceFormatIntegrityTest extends TestCase
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private function bmp(int $w = 4, int $h = 2): string
    {
        if (!function_exists('imagebmp')) {
            self::markTestSkipped('GD built without BMP support');
        }
        $im = imagecreatetruecolor($w, $h);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, (int) imagecolorallocate($im, 200, 30, 30));
        ob_start();
        imagebmp($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    public function testFromStringKeepsTheSniffedMimeForUnlistedFormats(): void
    {
        $source = ImageSource::fromString($this->bmp());

        self::assertSame('image/bmp', $source->format);
        self::assertFalse($source->isPng());
        self::assertSame([4, 2], [$source->width, $source->height]);
    }

    public function testIsPngChecksTheSignatureNotTheLabel(): void
    {
        self::assertTrue(ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png')->isPng());
        self::assertFalse((new ImageSource($this->bmp(), 'image/png', 4, 2))->isPng());
    }

    public function testKittyReencodesNonPngBytesEvenWhenLabelledPng(): void
    {
        foreach ([ImageSource::fromString($this->bmp()), new ImageSource($this->bmp(), 'image/png', 4, 2)] as $source) {
            $out = (new KittyRenderer())->render($source, 4, 2);
            preg_match_all('/;([A-Za-z0-9+\/=]+)\x1b\\\\/', $out, $m);
            $payload = base64_decode(implode('', $m[1]), true);

            self::assertIsString($payload);
            self::assertStringStartsWith(self::PNG_SIGNATURE, $payload, 'f=100 must only ever carry PNG bytes');
        }
    }

    public function testIterm2ReencodesNonPngBytesEvenWhenLabelledPng(): void
    {
        foreach ([ImageSource::fromString($this->bmp()), new ImageSource($this->bmp(), 'image/png', 4, 2)] as $source) {
            $out = (new Iterm2Renderer())->render($source, 4, 2);
            self::assertSame(1, preg_match('/:([A-Za-z0-9+\/=]+)\x07$/', $out, $m));

            self::assertStringStartsWith(self::PNG_SIGNATURE, (string) base64_decode($m[1], true));
        }
    }

    public function testCropAndResizeOfAnUnwritableFormatReencodeAsPng(): void
    {
        $source = ImageSource::fromString($this->bmp(8, 4));

        foreach ([$source->crop(0, 0, 4, 2), $source->resize(2, 1)] as $derived) {
            self::assertSame('image/png', $derived->format);
            self::assertTrue($derived->isPng());
        }
    }

    public function testCropOfWebpReencodesAsPngInsteadOfThrowing(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD built without WebP support');
        }
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagewebp($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        $source = ImageSource::fromString($bytes);
        self::assertSame('image/webp', $source->format);
        self::assertSame('image/png', $source->crop(0, 0, 2, 2)->format);
    }
}
