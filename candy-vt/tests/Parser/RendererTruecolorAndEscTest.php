<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Parser\CsiHandlerImpl;
use SugarCraft\Vt\Rendition;
use SugarCraft\Vt\Theme;

/**
 * Renderer-path (CsiHandlerImpl) coverage for the two capabilities that used
 * to be emulator-only: 24-bit truecolour pen storage and the ESC family that
 * now reaches the renderer through {@see \SugarCraft\Vt\Parser\RendererHandler}.
 *
 * The parity suite in candy-vcr proves the emulator and renderer agree
 * end-to-end; this file pins the renderer's own storage so a regression that
 * keeps both engines wrong in the same way still fails close to the code.
 */
final class RendererTruecolorAndEscTest extends TestCase
{
    private function csi(int $cols = 20, int $rows = 6, int $row = 0, int $col = 0): CsiHandlerImpl
    {
        return new CsiHandlerImpl(new Buffer($cols, $rows), new Cursor(row: $row, col: $col), new Theme());
    }

    // ─── A2: truecolour pen storage on the renderer path ────────────────────

    public function testSemicolonTruecolourStoredOnThePrintedCell(): void
    {
        $csi = $this->csi();
        $csi->sgr([38, 2, 200, 100, 50, 48, 2, 10, 20, 30]);
        $csi->printable('X');

        $cell = $csi->grid()->cell(0, 0);
        $this->assertSame(200 << 16 | 100 << 8 | 50, $cell->fgTruecolor);
        $this->assertSame(10 << 16 | 20 << 8 | 30, $cell->bgTruecolor);
        $this->assertSame([200, 100, 50], $cell->fgRgb());
        $this->assertSame("\x1b[38;2;200;100;50;48;2;10;20;30m", $cell->colorSgr());
    }

    public function testPaletteSgrClearsTheTruecolourPen(): void
    {
        // A plain 31 after a 38;2 must not leave a stale packed foreground
        // riding along on the next cell — the palette int is the truth again.
        $csi = $this->csi();
        $csi->sgr([38, 2, 1, 2, 3]);
        $csi->sgr([31]);
        $csi->printable('X');

        $cell = $csi->grid()->cell(0, 0);
        $this->assertNull($cell->fgTruecolor, 'fg truecolour forgotten by a palette set');
        $this->assertSame(1, $cell->fg);
    }

    public function testDefaultForegroundClearsTheTruecolourPen(): void
    {
        $csi = $this->csi();
        $csi->sgr([38, 2, 9, 9, 9]);
        $csi->sgr([39]); // default fg
        $csi->printable('X');

        $this->assertNull($csi->grid()->cell(0, 0)->fgTruecolor);
    }

    // ─── A3: line rendition stamping ────────────────────────────────────────

    /**
     * @return array<string, array{0: int, 1: Rendition}>
     */
    public static function renditionProvider(): array
    {
        return [
            'DECDHL top' => [0x33, Rendition::DoubleTop],
            'DECDHL bottom' => [0x34, Rendition::DoubleBottom],
            'DECSWL' => [0x35, Rendition::None],
            'DECDWL' => [0x36, Rendition::DoubleWidth],
        ];
    }

    #[DataProvider('renditionProvider')]
    public function testEscLineRenditionStampsTheCursorRow(int $final, Rendition $expected): void
    {
        $csi = $this->csi(row: 2, col: 3);
        // Fill the cursor row so we can see every column picked up the stamp.
        $csi->printable('abc');
        $csi->escLineRendition($final);

        for ($c = 0; $c < 20; $c++) {
            $this->assertSame($expected, $csi->grid()->cell(2, $c)->rendition);
        }
        // Neighbouring rows untouched.
        $this->assertSame(Rendition::None, $csi->grid()->cell(1, 0)->rendition);
        $this->assertSame(Rendition::None, $csi->grid()->cell(3, 0)->rendition);
    }

    public function testEscLineRenditionIgnoresUnknownFinal(): void
    {
        $csi = $this->csi();
        $csi->printable('Z');
        $csi->escLineRendition(0x30); // 'ESC # 0' — no rendition
        $this->assertSame(Rendition::None, $csi->grid()->cell(0, 0)->rendition);
        $this->assertSame('Z', $csi->grid()->cell(0, 0)->char);
    }

    public function testDoubleWidthMakesRendererGlyphOccupyTwoColumns(): void
    {
        $csi = $this->csi();
        $csi->escLineRendition(0x36); // DECDWL
        $csi->printable('X');

        $this->assertSame('X', $csi->grid()->cell(0, 0)->char);
        $this->assertTrue($csi->grid()->cell(0, 1)->continuation, 'trailing column is a continuation');
        $this->assertSame(2, $csi->cursor()->col, 'pen advanced two columns');
    }

    public function testDecswlClearsAPreviousDoubleWidthStamp(): void
    {
        // Mirrors the emulator's DecalnWire test: DECSWL (#5 → None) must
        // actively clear a standing DECDWL, not merely "leave a default".
        // Under a no-op escLineRendition this row would stay DoubleWidth.
        $csi = $this->csi(row: 2, col: 3);
        $csi->printable('abc');
        $csi->escLineRendition(0x36); // DECDWL
        $this->assertSame(Rendition::DoubleWidth, $csi->grid()->cell(2, 0)->rendition);
        $csi->escLineRendition(0x35); // DECSWL

        for ($c = 0; $c < 20; $c++) {
            $this->assertSame(Rendition::None, $csi->grid()->cell(2, $c)->rendition);
        }
    }

    public function testDoubleWidthPairReachesTheLastColumnButNoFurther(): void
    {
        // Right-margin geometry: on a 4-col grid a DW glyph at col 2 owns the
        // pair (2,3); at col 3 there is no room for the tail, so it stays a
        // single cell and the renderer never writes out of bounds. Discriminates
        // the `col+width+1 <= cols` bound against a wrong `<`.
        $pair = $this->csi(cols: 4, row: 0, col: 2);
        $pair->escLineRendition(0x36);
        $pair->printable('X');
        $this->assertSame('X', $pair->grid()->cell(0, 2)->char);
        $this->assertTrue($pair->grid()->cell(0, 3)->continuation, 'pair owns the final column');

        $edge = $this->csi(cols: 4, row: 0, col: 3);
        $edge->escLineRendition(0x36);
        $edge->printable('Y');
        $this->assertSame('Y', $edge->grid()->cell(0, 3)->char, 'no room for a tail — stays single');
    }

    public function testOutOfRangePaletteIndexClampsLikeTheEmulator(): void
    {
        // `38;5;n` must resolve n the same way the emulator does: clamp to
        // 0..255 (and map the -1 default sentinel to 0). A naive raw store would
        // leave fg=300 here and silently break emulator↔renderer parity.
        $csi = $this->csi();
        $csi->sgr([38, 5, 300]);
        $csi->printable('X');
        $this->assertSame(255, $csi->grid()->cell(0, 0)->fg, 'over-range index clamps to 255');

        $low = $this->csi();
        $low->sgr([38, 5, -1]);
        $low->printable('Y');
        $this->assertSame(0, $low->grid()->cell(0, 0)->fg, 'default sentinel resolves to 0');
    }

    public function testSaveRestoreCarriesTheTruecolourPen(): void
    {
        // SCOSC/SCORC must snapshot and reinstate the RGB slots, not just the
        // palette ints — otherwise a save/restore across a reset loses the
        // truecolour the moment it is needed again.
        $csi = $this->csi();
        $csi->sgr([38, 2, 10, 20, 30, 48, 2, 40, 50, 60]);
        $csi->scosc();
        $csi->sgr([0]);            // drop to default palette + clear RGB
        $csi->scorc();
        $csi->printable('Q');

        $cell = $csi->grid()->cell(0, 0);
        $this->assertSame(10 << 16 | 20 << 8 | 30, $cell->fgTruecolor, 'saved fg RGB returns');
        $this->assertSame(40 << 16 | 50 << 8 | 60, $cell->bgTruecolor, 'saved bg RGB returns');
    }

    // ─── A4: the ESC roster now drives renderer state ───────────────────────

    public function testEscIndexFeedsTheCursorDown(): void
    {
        $csi = $this->csi(row: 3, col: 5);
        $csi->escIndex();
        $this->assertSame(4, $csi->cursor()->row, 'IND = line feed');
        $this->assertSame(5, $csi->cursor()->col);
    }

    public function testEscNextLineCarriageReturnsThenFeeds(): void
    {
        $csi = $this->csi(row: 1, col: 7);
        $csi->escNextLine();
        $this->assertSame(2, $csi->cursor()->row, 'NEL new row');
        $this->assertSame(0, $csi->cursor()->col, 'NEL returns to column 0');
    }

    public function testEscReverseIndexFeedsTheCursorUp(): void
    {
        $csi = $this->csi(row: 4, col: 2);
        $csi->escReverseIndex();
        $this->assertSame(3, $csi->cursor()->row, 'RI backs up one row');
        $this->assertSame(2, $csi->cursor()->col);
    }

    public function testEscSaveAndRestoreCursorRoundTrips(): void
    {
        $csi = $this->csi(row: 2, col: 6);
        $csi->escSaveCursor();
        $csi->escIndex(); // move the cursor off the saved spot
        $this->assertSame(3, $csi->cursor()->row);
        $csi->escRestoreCursor();
        $this->assertSame(2, $csi->cursor()->row, 'DECRC returns the row');
        $this->assertSame(6, $csi->cursor()->col, 'DECRC returns the column');
    }

    public function testEscResetToInitialStateClearsGridAndPen(): void
    {
        $csi = $this->csi(row: 2, col: 3);
        $csi->sgr([38, 2, 5, 6, 7]);
        $csi->printable('HI');
        $csi->escResetToInitialState();

        $this->assertSame(0, $csi->cursor()->row);
        $this->assertSame(0, $csi->cursor()->col);
        $this->assertSame(' ', $csi->grid()->cell(0, 0)->char, 'grid cleared');
        // A fresh print after RIS carries no leftover truecolour.
        $csi->printable('K');
        $this->assertNull($csi->grid()->cell(0, 0)->fgTruecolor, 'pen truecolour forgotten by RIS');
    }
}
