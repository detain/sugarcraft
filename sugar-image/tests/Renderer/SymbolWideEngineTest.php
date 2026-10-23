<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SymbolMapRenderer;
use SugarCraft\Image\Symbol\CanvasMode;
use SugarCraft\Image\Symbol\SymbolMap;
use SugarCraft\Image\Symbol\SymbolSetKana;

/**
 * The wide-glyph engine — chafa's update_cells_row lookback
 * (chafa-symbol-renderer.c:790) ported into SymbolMapRenderer.
 *
 * Every fixture below is BUILT from a real kana outline (left half into
 * cell A, right half into cell B), so the wide trial sees exactly the
 * glyph it must recover, and the pinned bytes carry two discriminating
 * fingerprints no narrow path can produce: the continuation cell emits
 * zero bytes (the C printer's `if (cell->c == 0) continue;`), and the
 * shared pen pair is chafa_color_average_2's TRUNCATING channel average
 * — white against white lands on 254, not 255.
 *
 * The byte-freeze side: maps with no wide selection (default, latin,
 * every narrow selector) skip the pass structurally, pinned by the
 * frozen digests in SymbolMapRendererTest; a cell this port pins at
 * error 0 (fully transparent, perfect solid) is never trialled over
 * (A-05a deviation from the C, recorded in applyWidePass).
 */
final class SymbolWideEngineTest extends TestCase
{
    private const WA = "\u{3042}"; // hiragana A, weight 44

    private const A_SMALL = "\u{3041}"; // hiragana small A, weight 33

    /** Paint one kana's two halves side by side at $cells columns (8 px per cell). */
    private static function kanaStrip(array $codepoints): ImageSource
    {
        $cells = count($codepoints) * 2;
        $img = imagecreatetruecolor($cells * 8, 8);
        imagealphablending($img, false);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);

        foreach ($codepoints as $k => $cp) {
            $rows = explode('|', SymbolSetKana::OUTLINES[$cp]);
            for ($y = 0; $y < 8; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    imagesetpixel($img, $k * 16 + $x, $y, $rows[$y][$x] === 'X' ? $white : $black);
                }
            }
        }

        return ImageSource::fromGd($img, 'image/png');
    }

    private static function kanaRenderer(): SymbolMapRenderer
    {
        return new SymbolMapRenderer(SymbolMap::new()->withKana());
    }

    public function testAWidePairReplacesBothCellsAndEmitsZeroBytesForTheContinuation(): void
    {
        $strip = self::kanaStrip([0x3042]);

        // Narrow map on the same fixture: two cells, untouched pre-A-09 bytes.
        $narrow = (new SymbolMapRenderer())->render($strip, 2, 1);
        $this->assertSame('68403dbb1496e6bc56d926b48eee30db79b7fbd2', sha1($narrow));

        $wide = self::kanaRenderer()->render($strip, 2, 1);
        $this->assertSame('dab8a2f1de3892c345d47c1c40019f5961e2aff3', sha1($wide));

        // One lead glyph, emitted exactly once; no narrow block glyph survives.
        $this->assertSame(1, substr_count($wide, self::WA));
        $this->assertStringNotContainsString("\u{258a}", $wide);
        $this->assertStringNotContainsString("\u{2598}", $wide);
    }

    public function testTheSharedPairIsChafasTruncatingChannelAverage(): void
    {
        // chafa_color_average_2: (255>>1)+(255>>1) = 254 per channel. A
        // rounding average would emit 38;2;255;255;255 and move the digest.
        $out = self::kanaRenderer()->render(self::kanaStrip([0x3042]), 2, 1);

        $this->assertStringContainsString("\e[38;2;254;254;254m", $out);
        $this->assertStringContainsString("\e[48;2;0;0;0m", $out);
    }

    public function testTheFastAndExhaustiveWideTrialsAgreeOnTheSyntheticPair(): void
    {
        // Dial dispatch on the wide path: the Hamming shortlist holds
        // the exact glyph, so the exhaustive symbols2 scan cannot beat it.
        $strip = self::kanaStrip([0x3042]);

        $fast = self::kanaRenderer()->render($strip, 2, 1);
        $slow = self::kanaRenderer()->withWorkFactor(1.0)->render($strip, 2, 1);

        $this->assertSame($fast, $slow);
    }

    public function testTheWideSelectorAloneRendersThroughTheLookback(): void
    {
        // A `wide`-only selection holds zero narrow defs: chafa's
        // update_cell early return (SYMBOL_ERROR_MAX) hands every pair
        // to the wide trial, landing the same bytes as withKana.
        $strip = self::kanaStrip([0x3042]);

        $viaSelector = (new SymbolMapRenderer())->withSymbolSelectors('wide')->render($strip, 2, 1);

        $this->assertSame(self::kanaRenderer()->render($strip, 2, 1), $viaSelector);
    }

    public function testOddWidthRowsLeaveTheTrailingCellNarrowAndNeverChainOffAContinuation(): void
    {
        // Cells 0-1 form the pair; cell 2 is a lone left half. The cx=3-
        // equivalent lookback at cx=2 must skip: its left neighbour (the
        // continuation at cx=1) is not lead-capable.
        $img = imagecreatetruecolor(24, 8);
        imagealphablending($img, false);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        $rows = explode('|', SymbolSetKana::OUTLINES[0x3042]);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 16; $x++) {
                imagesetpixel($img, $x, $y, $rows[$y][$x] === 'X' ? $white : $black);
            }
            // Cell 2: the small-あ left half.
            $tail = explode('|', SymbolSetKana::OUTLINES[0x3041]);
            for ($x = 0; $x < 8; $x++) {
                imagesetpixel($img, 16 + $x, $y, $tail[$y][$x] === 'X' ? $white : $black);
            }
        }
        $strip = ImageSource::fromGd($img, 'image/png');

        $out = self::kanaRenderer()->render($strip, 3, 1);

        $this->assertSame(1, substr_count($out, self::WA));
        $this->assertStringNotContainsString(self::A_SMALL, $out); // cell 2 stays narrow
    }

    public function testAdjacentWidePairsBothCommit(): void
    {
        // The commit at (0,1) must not disarm the trial at (2,3): the
        // lookback at cx=3 sees a narrow (non-continuation) left cell.
        $out = self::kanaRenderer()->render(self::kanaStrip([0x3042, 0x3041]), 4, 1);

        $this->assertSame(1, substr_count($out, self::WA));
        $this->assertSame(1, substr_count($out, self::A_SMALL));
    }

    public function testColumnZeroIsAlwaysLeadCapableAndLeadsTheRow(): void
    {
        $out = self::kanaRenderer()->render(self::kanaStrip([0x3042]), 2, 1);

        // After the pen bytes, the first cell of the row is the lead —
        // the lookback starts at cx=1, so column 0 can only ever be
        // replaced in its capacity of left half of a pair.
        $cells = preg_replace('/\e\[[0-9;]*m/', '', $out);
        $this->assertSame(0, strpos($cells, self::WA));
    }

    public function testATransparentNeighbourIsNeverWidelyReplaced(): void
    {
        // A fully transparent cell keeps error 0, the pair is not
        // trialled, and the wide map emits the narrow bytes unchanged.
        $img = imagecreatetruecolor(16, 8);
        imagealphablending($img, false);
        imagesavealpha($img, true); // without this the PNG encode flattens the alpha
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, 7, 7, $transparent);
        $rows = explode('|', SymbolSetKana::OUTLINES[0x3042]);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                imagesetpixel($img, 8 + $x, $y, $rows[$y][8 + $x] === 'X' ? $white : $black);
            }
        }
        $strip = ImageSource::fromGd($img, 'image/png');

        // Positive control that the alpha survived the PNG round trip:
        // a lost alpha would paint cell 0 black-solid, not blank.
        $narrow = (new SymbolMapRenderer())->render($strip, 2, 1);
        $this->assertStringContainsString(' ', $narrow);
        $this->assertStringNotContainsString("\u{2588}", $narrow);

        $this->assertSame(
            $narrow,
            self::kanaRenderer()->render($strip, 2, 1),
        );
    }

    public function testAPerfectSolidNeighbourIsNeverWidelyReplaced(): void
    {
        // Cell 0 uniform white lands on the solid shortcut (error 0); the
        // kana right half next to it must survive as its narrow cell.
        $img = imagecreatetruecolor(16, 8);
        imagealphablending($img, false);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 0, 0, 0);
        imagefilledrectangle($img, 0, 0, 7, 7, $white);
        $rows = explode('|', SymbolSetKana::OUTLINES[0x3042]);
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                imagesetpixel($img, 8 + $x, $y, $rows[$y][8 + $x] === 'X' ? $white : $black);
            }
        }
        $strip = ImageSource::fromGd($img, 'image/png');

        $out = self::kanaRenderer()->render($strip, 2, 1);

        $this->assertStringNotContainsString(self::WA, $out);
        $this->assertSame((new SymbolMapRenderer())->render($strip, 2, 1), $out);
    }

    public function testWideCommitUnderFgbgCarriesNoColourPens(): void
    {
        $strip = self::kanaStrip([0x3042]);
        $renderer = self::kanaRenderer()->withCanvasMode(CanvasMode::Fgbg);

        $out = $renderer->render($strip, 2, 1);

        $this->assertSame(1, substr_count($out, self::WA));
        $this->assertStringNotContainsString('38;', $out);
        $this->assertStringNotContainsString('48;', $out);
        // The narrow Fgbg run on the same fixture differs (two block cells).
        $this->assertNotSame(
            (new SymbolMapRenderer())->withCanvasMode(CanvasMode::Fgbg)->render($strip, 2, 1),
            $out,
        );
    }

    public function testTheCandidateObserverSeamStaysNarrowOnly(): void
    {
        // Disclosed A-09 seam: the op-counter observer fires once per
        // NARROW cell; the wide trial never reports through it.
        $seen = 0;
        $renderer = self::kanaRenderer()->withCandidateObserver(
            static function () use (&$seen): void {
                $seen++;
            },
        );
        $renderer->render(self::kanaStrip([0x3042]), 2, 1);

        $this->assertSame(2, $seen); // both cells were narrow-scored; the wide pass added no call
    }
}
