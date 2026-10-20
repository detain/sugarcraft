<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Renderer\QuarterBlockRenderer;

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
        $this->assertSame(0, substr_count($out, "\n"));
        // Output must never contain a carriage return (TUI uses \n only).
        $this->assertStringNotContainsString("\r", $out);
    }

    public function testCheckerboardAt4x4ProducesThreeLineSeparators(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/../../tests/fixtures/checkerboard_qb.png');
        $out = $this->renderer->render($image, 4, 4);

        // 4 cells per row × 4 rows = 4 lines → 3 \n separators.
        $this->assertSame(3, substr_count($out, "\n"));
        // Output must never contain a carriage return (TUI uses \n only).
        $this->assertStringNotContainsString("\r", $out);
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

    /**
     * A-03: exhaustive search must beat the retired 1-step 2-means on the
     * survey's mis-choice cell (chafa campaign survey A-03, :60-72): UL=0,
     * UR=200, LL=200, LR=100 grays. The old heuristic's most-distant seeds
     * {0,200} plus the `<=` tie-to-fg rule dragged the mid-gray LR into the
     * foreground group (mask 14, fg 166, bg 0, squared error 20004); the
     * minimum-error split is {UR,LL} fg=200 vs {UL,LR} bg=50 (mask 6 ▞,
     * error 15000) — pinned as exact output bytes.
     */
    public function testExhaustiveSearchCorrectsTheSeedHeuristicMisChoice(): void
    {
        $image = $this->cellImage([
            [[0, 0, 0, 0], [200, 200, 200, 0]],
            [[200, 200, 200, 0], [100, 100, 100, 0]],
        ]);

        $out = $this->renderer->render($image, 1, 1);

        $this->assertSame(
            "\x1b[38;2;200;200;200m\x1b[48;2;50;50;50m\u{259E}\x1b[39;49m",
            $out,
        );
    }

    /**
     * A-03: on a clean two-group cell (upper row white, lower row black)
     * the exhaustive search lands on the same cell the seed heuristic chose
     * — zero-error split mask 3 (▀, fg 255,255,255 over bg 0,0,0). Pins the
     * equivalence half so the rewrite is proven to only ever improve.
     */
    public function testExhaustiveSearchAgreesWithHeuristicOnCleanSplit(): void
    {
        $image = $this->cellImage([
            [[255, 255, 255, 0], [255, 255, 255, 0]],
            [[0, 0, 0, 0], [0, 0, 0, 0]],
        ]);

        $out = $this->renderer->render($image, 1, 1);

        $this->assertSame(
            "\x1b[38;2;255;255;255m\x1b[48;2;0;0;0m\u{2580}\x1b[39;49m",
            $out,
        );
    }

    /**
     * A-03: the flat shortcut survives the rewrite — four identical quads
     * stay a solid █ with fg and bg both the cell colour (byte-stable for
     * the whole goldens corpus).
     */
    public function testFlatCellStaysSolidBlock(): void
    {
        $image = $this->cellImage([
            [[255, 0, 0, 0], [255, 0, 0, 0]],
            [[255, 0, 0, 0], [255, 0, 0, 0]],
        ]);

        $out = $this->renderer->render($image, 1, 1);

        $this->assertSame(
            "\x1b[38;2;255;0;0m\x1b[48;2;255;0;0m\u{2588}\x1b[39;49m",
            $out,
        );
    }

    /**
     * A-05a: a transparent quadrant must never paint its stored RGB. The
     * old fromGdQuarter read colour bits only, so a transparent-black quad
     * became a BLACK background pen (survey A-05, chafa_improvemnts.md
     * :95-101). With three opaque quads the cell renders foreground-only:
     * mask pinned to the opaque set (UL+UR+LL = 7 = ▛), fg the average of
     * the three opaque colours (255,0,0 + 0,255,0 + 0,0,255 → 85,85,85),
     * background left terminal default. No 48;2 pen may appear at all.
     */
    public function testTransparentQuadIsAbsentFromSampleNotPaintedBlack(): void
    {
        $image = $this->cellImage([
            [[255, 0, 0, 0], [0, 255, 0, 0]],
            [[0, 0, 255, 0], [0, 0, 0, 127]],
        ]);

        $out = $this->renderer->render($image, 1, 1);

        $this->assertStringNotContainsString('48;2', $out, 'a transparent quad must not mint a background pen');
        $this->assertStringNotContainsString('48;2;0;0;0', $out, 'the A-05 bug shape: transparent black painted as black bg');
        $this->assertSame("\x1b[38;2;85;85;85m\u{259B}\x1b[39m", $out);
    }

    /**
     * A-05a: two opaque diagonal quads keep only their own corners lit —
     * fg-only mask 9 (▚) in the opaque colour, no background pen.
     */
    public function testPartiallyTransparentCellUsesFgOnlyGlyphFamily(): void
    {
        $image = $this->cellImage([
            [[255, 0, 0, 0], [0, 0, 0, 127]],
            [[0, 0, 0, 127], [255, 0, 0, 0]],
        ]);

        $out = $this->renderer->render($image, 1, 1);

        $this->assertSame("\x1b[38;2;255;0;0m\u{259A}\x1b[39m", $out);
    }

    /**
     * A-05a: an all-transparent cell is a blank — a plain space with no
     * colour sequence anywhere (the old path minted a solid black █).
     */
    public function testAllTransparentCellIsBlank(): void
    {
        $image = $this->cellImage([
            [[0, 0, 0, 127], [0, 0, 0, 127]],
            [[0, 0, 0, 127], [0, 0, 0, 127]],
        ]);

        $out = $this->renderer->render($image, 1, 1);

        $this->assertSame(' ', $out);
        $this->assertStringNotContainsString("\x1b[", $out);
    }

    /**
     * Build a 2×2-pixel PNG (one quarter-block cell) from per-quadrant
     * [r,g,b,gd-alpha] rows: [[ul, ur], [ll, lr]].
     *
     * @param array{0: array{0: array{int,int,int,int}, 1: array{int,int,int,int}}, 1: array{0: array{int,int,int,int}, 1: array{int,int,int,int}}} $quads
     */
    private function cellImage(array $quads): ImageSource
    {
        $gd = imagecreatetruecolor(2, 2);
        self::assertNotFalse($gd);
        imagealphablending($gd, false);

        foreach ($quads as $y => $row) {
            foreach ($row as $x => [$r, $g, $b, $a]) {
                $color = imagecolorallocatealpha($gd, $r, $g, $b, $a);
                self::assertNotFalse($color);
                imagesetpixel($gd, $x, $y, $color);
            }
        }

        imagesavealpha($gd, true);
        $source = ImageSource::fromGd($gd, 'image/png');
        imagedestroy($gd);

        return $source;
    }
}
