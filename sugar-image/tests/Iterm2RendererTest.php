<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\Iterm2Renderer;

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
        // 8x4 fixture has aspect ratio 2.0. Requesting width=8 without height
        // derives round(8 / (2·2)) = 2 cell rows: 8 columns by 2 rows is an
        // 8:4 sub-pixel box at half-block density, i.e. the source aspect.
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/8x4_red.png');

        $outAuto = $this->renderer->render($image, 8);
        $outExplicit = $this->renderer->render($image, 8, 2);

        $this->assertSame($outExplicit, $outAuto);
        $this->assertStringContainsString('height=2', $outAuto);
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

    public function testCaptureReapSitsInAFinallyShapePin(): void
    {
        // The law is structural. A forced in-process throw cannot be staged
        // honestly here: memory_limit exhaustion is an UNcatchable fatal
        // (it would kill the test worker, not exercise the finally), and the
        // renderer is final with no production seam — so the shape itself is
        // the pinned contract, house idiom (cf. SI-20's exclusive-create
        // guard and SI-09's banded-sampler census).
        $src = (string) file_get_contents((new \ReflectionClass(Iterm2Renderer::class))->getFileName());

        $this->assertStringNotContainsString('ob_get_clean', $src, 'the skippable straight-line reap is dead');

        $start = (int) strpos($src, 'ob_start();');
        $this->assertNotFalse($start);
        $window = substr($src, $start, (int) strpos(substr($src, $start), 'ob_end_clean();'));
        $this->assertStringContainsString('} finally {', $window, 'the reap must run on every exit path, throw included');
    }

    public function testNoSrcCaptureUsesTheSkippableReapIdiom(): void
    {
        // The library-wide census: anywhere src/ opens an output buffer, the
        // straight-line ob_get_clean() reap that a throw can skip must not
        // reappear. One walk, exact-token grep, fail loud naming the file.
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php') && str_contains((string) file_get_contents($file->getPathname()), 'ob_get_clean')) {
                $offenders[] = $file->getFilename();
            }
        }

        $this->assertSame([], $offenders, 'output-buffer captures must reap in a finally (ob_end_clean), never on the throw-skippable straight line');
    }
}
