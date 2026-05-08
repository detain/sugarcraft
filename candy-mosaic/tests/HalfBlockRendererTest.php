<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\HalfBlockRenderer;

final class HalfBlockRendererTest extends TestCase
{
    private HalfBlockRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new HalfBlockRenderer();
    }

    public function testRendersExpectedBytesFor4x2Fixture(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $expected = file_get_contents(__DIR__ . '/fixtures/expected_halfblock.txt');

        $out = $this->renderer->render($image, 4, 2);

        $this->assertSame($expected, $out);
    }

    public function testRendersWidthOnlyAndDerivesHeightFromAspectRatio(): void
    {
        // 4x2 fixture has aspect ratio 2.0 (4/2). Rendering at width=4 with
        // no height should auto-derive height=2.
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $outAuto = $this->renderer->render($image, 4);

        // Compare against the explicit (4, 2) call.
        $outExplicit = $this->renderer->render($image, 4, 2);

        $this->assertSame($outExplicit, $outAuto);
    }

    public function testNegativeWidthThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, -1, 2);
    }

    public function testZeroWidthThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 0, 2);
    }

    public function testNegativeHeightThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 4, -1);
    }

    public function testZeroHeightThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 4, 0);
    }

    public function testNameReturnsHalfblock(): void
    {
        $this->assertSame('halfblock', $this->renderer->name());
    }

    public function testSupportsAlphaReturnsFalse(): void
    {
        $this->assertFalse($this->renderer->supportsAlpha());
    }

    public function testCheckerboardAt1x1ProducesOneHalfBlockLine(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/checkerboard.png');
        $out = $this->renderer->render($image, 1, 1);

        // Should contain exactly one ▀ (U+2580) character.
        $this->assertSame(1, preg_match_all('/\xE2\x96\x80/', $out));
        // Single-row render: no linebreak separator.
        $this->assertSame(0, substr_count($out, "\r\n"));
    }

    public function testCheckerboardAt4x3(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/checkerboard.png');
        $out = $this->renderer->render($image, 4, 3);

        // 4 cells per row × 3 rows = 3 lines → 2 \r\n separators.
        $this->assertSame(2, substr_count($out, "\r\n"));
        // 4 × 3 = 12 half-block glyphs.
        $this->assertSame(12, preg_match_all('/\xE2\x96\x80/', $out));
    }

    public function testFromStringProducesIdenticalOutput(): void
    {
        $bytes = file_get_contents(__DIR__ . '/fixtures/4x2.png');
        $imageFromFile = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $imageFromString = ImageSource::fromString($bytes);

        $outFile   = $this->renderer->render($imageFromFile, 4, 2);
        $outString = $this->renderer->render($imageFromString, 4, 2);

        $this->assertSame($outFile, $outString);
    }

    public function testMosaicHalfBlockMatchesDirectRenderer(): void
    {
        $mosaic = Mosaic::halfBlock();
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $mosaicOut  = $mosaic->render($image, 4, 2);
        $directOut  = (new HalfBlockRenderer())->render($image, 4, 2);

        $this->assertSame($directOut, $mosaicOut);
    }
}
