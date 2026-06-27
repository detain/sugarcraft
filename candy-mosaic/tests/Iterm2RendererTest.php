<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Renderer\Iterm2Renderer;

final class Iterm2RendererTest extends TestCase
{
    private Iterm2Renderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new Iterm2Renderer();
    }

    public function testRendersOsc1337Sequence(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out = $this->renderer->render($image, 8, 4);

        // Must contain the OSC 1337 introducer and BEL terminator.
        $this->assertStringStartsWith("\x1b]1337;", $out);
        $this->assertStringEndsWith("\x07", $out);

        // Must contain the width/height params.
        $this->assertStringContainsString('width=8', $out);
        $this->assertStringContainsString('height=4', $out);
        $this->assertStringContainsString('preserveAspectRatio=1', $out);
    }

    public function testWidthOnlyDerivesHeightFromAspectRatio(): void
    {
        // 8x4 fixture has aspect ratio 2.0. Requesting width=8 without
        // height should auto-derive height=4.
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $outAuto = $this->renderer->render($image, 8);
        $outExplicit = $this->renderer->render($image, 8, 4);

        $this->assertSame($outExplicit, $outAuto);
        $this->assertStringContainsString('height=4', $outAuto);
    }

    public function testBase64EncodedPngBytes(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out = $this->renderer->render($image, 8, 4);

        $pngBytes = file_get_contents(__DIR__ . '/fixtures/8x4_red.png');
        $expectedB64 = base64_encode((string) $pngBytes);

        // The base64 payload follows the `File=<args>:` prefix.
        $this->assertStringContainsString(':' . $expectedB64 . "\x07", $out);
    }

    public function testReEncodesNonPngWithoutLeakingToStdout(): void
    {
        // A non-PNG source takes the imagepng() re-encode path, which must NOT
        // dump the raw PNG to stdout (the bytes belong in the base64 payload).
        $jpeg = imagecreatetruecolor(8, 4);
        imagefilledrectangle($jpeg, 0, 0, 8, 4, (int) imagecolorallocate($jpeg, 200, 50, 50));
        ob_start();
        imagejpeg($jpeg);
        $jpegBytes = (string) ob_get_clean();
        imagedestroy($jpeg);

        ob_start();
        $out = $this->renderer->render(ImageSource::fromString($jpegBytes), 8, 4);
        $stray = ob_get_clean();

        self::assertSame('', $stray, 'no raw PNG written to stdout during render');
        self::assertStringStartsWith("\x1b]1337;File=", $out);
        self::assertStringNotContainsString('IHDR', $out, 'raw PNG header not in the payload');
    }

    public function testNameReturnsIterm2(): void
    {
        $this->assertSame('iterm2', $this->renderer->name());
    }

    public function testSupportsAlphaReturnsTrue(): void
    {
        $this->assertTrue($this->renderer->supportsAlpha());
    }

    public function testNegativeWidthThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, -1, 4);
    }

    public function testZeroWidthThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 0, 4);
    }

    public function testNegativeHeightThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 8, -1);
    }

    public function testZeroHeightThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 8, 0);
    }

    public function testInlineOptionIsAlways1(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');
        $out = $this->renderer->render($image, 8, 4);

        $this->assertStringContainsString('inline=1', $out);
    }
}
