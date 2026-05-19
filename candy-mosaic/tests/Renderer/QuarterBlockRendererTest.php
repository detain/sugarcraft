<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\Renderer\QuarterBlockRenderer;

final class QuarterBlockRendererTest extends TestCase
{
    private QuarterBlockRenderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new QuarterBlockRenderer();
    }

    public function testRendersExpectedBytesFor4x4Fixture(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/4x4_qb.png');
        $expected = file_get_contents(__DIR__ . '/../../tests/fixtures/expected_quarterblock.txt');

        $out = $this->renderer->render($image, 4, 4);

        $this->assertSame($expected, $out);
    }

    public function testNegativeWidthThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/4x4_qb.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, -1, 4);
    }

    public function testZeroWidthThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/4x4_qb.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 0, 4);
    }

    public function testNegativeHeightThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/4x4_qb.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 4, -1);
    }

    public function testZeroHeightThrows(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/4x4_qb.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->renderer->render($image, 4, 0);
    }

    public function testNameReturnsQuarterblock(): void
    {
        $this->assertSame('quarterblock', $this->renderer->name());
    }

    public function testSupportsAlphaReturnsFalse(): void
    {
        $this->assertFalse($this->renderer->supportsAlpha());
    }

    public function testRenderWidthOnlyDerivesHeightFromAspectRatio(): void
    {
        // 4x4 fixture has aspect ratio 1.0. Rendering at width=4 with no
        // height should auto-derive height=4.
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/4x4_qb.png');
        $outAuto = $this->renderer->render($image, 4);

        $outExplicit = $this->renderer->render($image, 4, 4);

        $this->assertSame($outExplicit, $outAuto);
    }

    public function testCheckerboardAt1x1ProducesOneLine(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/checkerboard_qb.png');
        $out = $this->renderer->render($image, 1, 1);

        // Single cell: no linebreak separator.
        $this->assertSame(0, substr_count($out, "\r\n"));
    }

    public function testCheckerboardAt4x4ProducesThreeLineSeparators(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/checkerboard_qb.png');
        $out = $this->renderer->render($image, 4, 4);

        // 4 cells per row × 4 rows = 4 lines → 3 \r\n separators.
        $this->assertSame(3, substr_count($out, "\r\n"));
    }

    public function testFromStringProducesIdenticalOutput(): void
    {
        $bytes = file_get_contents(__DIR__ . '/../../tests/fixtures/4x4_qb.png');
        $imageFromFile = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/4x4_qb.png');
        $imageFromString = ImageSource::fromString($bytes);

        $outFile   = $this->renderer->render($imageFromFile, 4, 4);
        $outString = $this->renderer->render($imageFromString, 4, 4);

        $this->assertSame($outFile, $outString);
    }

    public function testMosaicQuarterBlockMatchesDirectRenderer(): void
    {
        $mosaic = Mosaic::builder()
            ->withRenderer(new QuarterBlockRenderer())
            ->build();
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/4x4_qb.png');

        $mosaicOut = $mosaic->render($image, 4, 4);
        $directOut = (new QuarterBlockRenderer())->render($image, 4, 4);

        $this->assertSame($directOut, $mosaicOut);
    }
}
