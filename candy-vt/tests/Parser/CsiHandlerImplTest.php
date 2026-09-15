<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Parser\CsiHandlerImpl;
use SugarCraft\Vt\Theme;

final class CsiHandlerImplTest extends TestCase
{
    private CellGrid $grid;
    private Cursor $cursor;
    private Theme $theme;
    private CsiHandlerImpl $csi;

    protected function setUp(): void
    {
        $this->grid = new CellGrid(80, 24);
        $this->cursor = new Cursor();
        $this->theme = new Theme();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);
    }

    public function testCuuMovesCursorUp(): void
    {
        $this->cursor = new Cursor(row: 5, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cuu(2);

        $this->assertSame(3, $this->csi->cursor()->row);
        $this->assertSame(10, $this->csi->cursor()->col);
    }

    public function testCuuClampsAtZero(): void
    {
        $this->cursor = new Cursor(row: 2, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cuu(10);

        $this->assertSame(0, $this->csi->cursor()->row);
    }

    public function testCudMovesCursorDown(): void
    {
        $this->cursor = new Cursor(row: 5, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cud(3);

        $this->assertSame(8, $this->csi->cursor()->row);
    }

    public function testCudClampsAtBottom(): void
    {
        $this->cursor = new Cursor(row: 22, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cud(10);

        $this->assertSame(23, $this->csi->cursor()->row);
    }

    public function testCufMovesCursorForward(): void
    {
        $this->cursor = new Cursor(row: 0, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cuf(5);

        $this->assertSame(15, $this->csi->cursor()->col);
    }

    public function testCufClampsAtRightEdge(): void
    {
        $this->cursor = new Cursor(row: 0, col: 78);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cuf(10);

        $this->assertSame(79, $this->csi->cursor()->col);
    }

    public function testCubMovesCursorBack(): void
    {
        $this->cursor = new Cursor(row: 0, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cub(3);

        $this->assertSame(7, $this->csi->cursor()->col);
    }

    public function testCubClampsAtZero(): void
    {
        $this->cursor = new Cursor(row: 0, col: 3);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cub(10);

        $this->assertSame(0, $this->csi->cursor()->col);
    }

    public function testCupMovesCursorToPosition(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cup(5, 10);

        $this->assertSame(4, $this->csi->cursor()->row);
        $this->assertSame(9, $this->csi->cursor()->col);
    }

    public function testCupIsOneIndexed(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cup(1, 1);

        $this->assertSame(0, $this->csi->cursor()->row);
        $this->assertSame(0, $this->csi->cursor()->col);
    }

    public function testHvpSameAsCup(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->hvp(3, 7);

        $this->assertSame(2, $this->csi->cursor()->row);
        $this->assertSame(6, $this->csi->cursor()->col);
    }

    public function testPrintableWritesCellAndAdvancesCursorHorizontally(): void
    {
        $this->cursor = new Cursor(row: 0, col: 0);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->printable('X');

        $cell = $this->csi->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(0, $this->csi->cursor()->row);
        $this->assertSame(1, $this->csi->cursor()->col);
    }

    public function testSgrBoldAppliedToPrintedCell(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->sgr([1]);
        $this->csi->printable('X');

        $cell = $this->csi->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(Cell::ATTR_BOLD, $cell->attrs & Cell::ATTR_BOLD);
    }

    public function testSgrBoldThenReset(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->sgr([1]);
        $this->csi->sgr([22]);
        $this->csi->printable('X');

        $cell = $this->csi->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(0, $cell->attrs & Cell::ATTR_BOLD);
    }

    public function testSgrForegroundColor(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->sgr([31]);
        $this->csi->printable('X');

        $cell = $this->csi->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(1, $cell->fg);
    }

    public function testSgrBackgroundColor(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->sgr([42]);
        $this->csi->printable('X');

        $cell = $this->csi->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(2, $cell->bg);
    }

    public function testSgr256ColorFg(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->sgr([38, 5, 196]);
        $this->csi->printable('X');

        $cell = $this->csi->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(196, $cell->fg);
    }

    public function testSgr256ColorBg(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->sgr([48, 5, 21]);
        $this->csi->printable('X');

        $cell = $this->csi->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(21, $cell->bg);
    }

    public function testSgrReset(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->sgr([31, 42, 1]);
        $this->csi->sgr([0]);
        $this->csi->printable('X');

        $cell = $this->csi->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(7, $cell->fg);
        $this->assertSame(0, $cell->bg);
        $this->assertSame(0, $cell->attrs);
    }

    public function testDecsetCursorShown(): void
    {
        // DECTCEM: `CSI ? 25 h` SHOWS the cursor — set = mode enabled.
        // (The handler used to invert this, catalogued in VtParityTest.)
        $this->cursor = new Cursor(visible: false);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->decset(25, 0x3F);

        $this->assertTrue($this->csi->cursor()->visible);
    }

    public function testDecrstCursorHidden(): void
    {
        // DECTCEM: `CSI ? 25 l` HIDES the cursor.
        $this->cursor = new Cursor(visible: true);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->decrst(25, 0x3F);

        $this->assertFalse($this->csi->cursor()->visible);
    }

    public function testDecsetWithoutPrivatePrefixIsIgnored(): void
    {
        // The emulator forwards only '?'-prefixed modes to its mode handler;
        // the bare `CSI 25 h` form must be inert here too.
        $this->cursor = new Cursor(visible: true);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->decset(25, 0);
        $this->assertTrue($this->csi->cursor()->visible);

        $this->csi->decrst(25, 0);
        $this->assertTrue($this->csi->cursor()->visible);
    }

    public function testEdMode0ClearsBelow(): void
    {
        $this->cursor = new Cursor(row: 1, col: 0);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->printable('A');
        $this->csi->printable('B');
        $this->csi->cup(1, 1);

        $this->csi->ed(0);

        $this->assertSame(' ', $this->csi->grid()->get(1, 0)->char);
        $this->assertSame(' ', $this->csi->grid()->get(1, 1)->char);
        $this->assertSame(' ', $this->csi->grid()->get(23, 79)->char);
    }

    public function testEdMode2ClearsEntireScreen(): void
    {
        $this->cursor = new Cursor(row: 5, col: 5);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);
        $this->csi->printable('X');

        $this->csi->ed(2);

        $this->assertSame(' ', $this->csi->grid()->get(0, 0)->char);
        $this->assertSame(' ', $this->csi->grid()->get(5, 5)->char);
    }

    public function testElMode0ClearsToEndOfLine(): void
    {
        $this->cursor = new Cursor(row: 0, col: 0);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->printable('H');
        $this->csi->printable('e');
        $this->csi->printable('l');
        $this->csi->printable('l');
        $this->csi->printable('o');

        $this->csi->printable(' ');
        $this->csi->cub(1);

        $this->csi->el(0);

        $this->assertSame('o', $this->csi->grid()->get(0, 4)->char);
        $this->assertSame(' ', $this->csi->grid()->get(0, 5)->char);
    }

    public function testDecstbmSetsScrollRegion(): void
    {
        $this->cursor = new Cursor();
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->decstbm(5, 10);

        // CUP addresses ABSOLUTE screen lines clamped to the buffer — it is
        // not yanked into the scroll region (emulator CursorHandler parity).
        $this->csi->cup(1, 1);
        $this->assertSame(0, $this->csi->cursor()->row);

        $this->csi->cup(20, 1);
        $this->assertSame(19, $this->csi->cursor()->row);

        // The region itself is live: LF at its bottom scrolls lines 5..10
        // up by one and leaves content above the top margin untouched.
        $this->csi->cup(4, 1);
        $this->csi->printable('K');
        $this->csi->cup(10, 1);
        $this->csi->printable('B');
        $this->csi->cup(9, 1);
        $this->csi->printable('M');
        $this->csi->cup(10, 1);
        $this->csi->lf();

        $this->assertSame('B', $this->csi->grid()->get(8, 0)->char, 'line 9 took line 10 content');
        $this->assertSame(' ', $this->csi->grid()->get(9, 0)->char, 'region bottom blanked');
        $this->assertSame('K', $this->csi->grid()->get(3, 0)->char, 'outside the region, untouched');
    }

    public function testCbtMovesCursorBackwardByCount(): void
    {
        $this->cursor = new Cursor(row: 0, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cbt(3);

        $this->assertSame(7, $this->csi->cursor()->col);
    }

    public function testChtMovesCursorForwardByCount(): void
    {
        $this->cursor = new Cursor(row: 0, col: 2);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cht(3);

        $this->assertSame(5, $this->csi->cursor()->col);
    }

    public function testGridRowsReturnsCorrectCount(): void
    {
        $this->assertSame(24, $this->csi->gridRows());
    }

    public function testGridColsReturnsCorrectCount(): void
    {
        $this->assertSame(80, $this->csi->gridCols());
    }

    public function testPrintableDefersWrapAtRightEdge(): void
    {
        // Phantom-cell (deferred-wrap) semantics, xterm `wrapnext`: the glyph
        // lands in the last column, the cursor PARKS there with the flag set,
        // and only the NEXT graphic consumes the line break.
        $this->cursor = new Cursor(row: 0, col: 79);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->printable('X');

        $this->assertSame('X', $this->csi->grid()->get(0, 79)->char);
        $this->assertSame(79, $this->csi->cursor()->col, 'cursor parks on the last column');
        $this->assertSame(0, $this->csi->cursor()->row);
        $this->assertTrue($this->csi->wrapPending(), 'wrap deferred until the next graphic');

        $this->csi->printable('Y');

        $this->assertSame(1, $this->csi->cursor()->row);
        $this->assertSame(1, $this->csi->cursor()->col, 'Y landed at (1,0), cursor advanced to (1,1)');
        $this->assertSame('Y', $this->csi->grid()->get(1, 0)->char);
        $this->assertFalse($this->csi->wrapPending());
    }

    public function testCursorMotionDisarmsDeferredWrap(): void
    {
        $this->cursor = new Cursor(row: 0, col: 79);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        // Re-arm: park the cursor on the last column with a fresh phantom.
        $arm = function (): void {
            $this->csi->cup(1, 80);
            $this->csi->printable('X');
            $this->assertTrue($this->csi->wrapPending(), 're-arm sanity');
        };

        $arm();
        $this->csi->cub(1);
        $this->assertFalse($this->csi->wrapPending(), 'CUB (and the BS route) disarms');

        $arm();
        $this->csi->cuf(1);
        $this->assertFalse($this->csi->wrapPending(), 'CUF disarms');

        $arm();
        $this->csi->cuu(1);
        $this->assertFalse($this->csi->wrapPending(), 'CUU disarms');

        $arm();
        $this->csi->cud(1);
        $this->assertFalse($this->csi->wrapPending(), 'CUD disarms');

        $arm();
        $this->csi->cup(1, 1);
        $this->assertFalse($this->csi->wrapPending(), 'CUP disarms');

        $arm();
        $this->csi->cr();
        $this->assertFalse($this->csi->wrapPending(), 'CR disarms');

        $arm();
        $this->csi->lf();
        $this->assertFalse($this->csi->wrapPending(), 'LF consumes vertical motion');

        // Erase does NOT disarm (xterm leaves _wrapnext alone) — EL/ED and
        // ICH/DCH/SU/SD all preserve the phantom, matching the emulator.
        $arm();
        $this->csi->el(0);
        $this->assertTrue($this->csi->wrapPending(), 'EL preserves the phantom');

        $arm();
        $this->csi->ed(0);
        $this->assertTrue($this->csi->wrapPending(), 'ED preserves the phantom');

        $arm();
        $this->csi->cht(1);
        $this->assertTrue($this->csi->wrapPending(), 'HT/CHT preserve it (emulator horizontalTab)');
    }

    public function testSaveCursorKeepsRestoreDropsDeferredWrap(): void
    {
        // xterm: DECSC ('s') snapshots the position and the phantom survives
        // it; DECRC ('u') is a movement and clears on the way back — exactly
        // the emulator's cursor-dispatch rule (clear for every final but 's').
        $this->cursor = new Cursor(row: 0, col: 79);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);
        $this->csi->printable('X');

        $this->csi->scosc();
        $this->assertTrue($this->csi->wrapPending(), 'SCOSC does not disarm');

        $this->csi->cup(3, 3);
        $this->assertFalse($this->csi->wrapPending());

        $this->csi->scorc();
        $this->assertFalse($this->csi->wrapPending(), 'SCORC disarms after restoring');
    }

    public function testScorcRestoresPositionOnlyNotVisibility(): void
    {
        // The emulator's Cursor::restore() carries saved row/col alone —
        // visibility and shape are live state, so a hide between save and
        // restore stays hidden.
        $this->csi->cup(4, 6);
        $this->csi->scosc();
        $this->csi->cup(1, 1);
        $this->csi->decrst(25, 0x3F);
        $this->csi->scorc();

        $this->assertSame(3, $this->csi->cursor()->row);
        $this->assertSame(5, $this->csi->cursor()->col);
        $this->assertFalse($this->csi->cursor()->visible, 'restore must not resurrect a hidden cursor');
    }

    public function testScorcRestoresPenSavedByScosc(): void
    {
        // w4-vt emulator parity: the GENERAL DECSC slot restores the active
        // rendition along with the position; the renderer expresses that
        // snapshot as its fg/bg/attrs triple.
        $this->csi->sgr([1, 31]);           // bold red
        $this->csi->cup(2, 2);
        $this->csi->scosc();

        $this->csi->sgr([0, 32]);           // reset to green
        $this->csi->scorc();
        $this->csi->printable('Q');

        $cell = $this->csi->grid()->get(1, 1);
        $this->assertSame('Q', $cell->char);
        $this->assertSame(1, $cell->fg & 0x0F, 'red pen back from the save');
        $this->assertSame(Cell::ATTR_BOLD, $cell->attrs & Cell::ATTR_BOLD, 'bold returns with the saved pen, green stays discarded');

        // With nothing saved, restore leaves the live pen untouched.
        $fresh = new CsiHandlerImpl(new CellGrid(5, 2), new Cursor(), $this->theme);
        $fresh->sgr([34]);
        $fresh->scorc();
        $fresh->printable('Z');
        $this->assertSame(4, $fresh->grid()->get(0, 0)->fg & 0x0F, 'un-saved scorc keeps the live pen');
    }

    public function testCombiningMarkAttachesToPhantomHostCell(): void
    {
        // While the phantom cell is armed the last graphic sits UNDER the
        // cursor, so a combining mark belongs there — not one column before
        // (emulator: ScreenHandler::attachCombiningChar keys on wrapPending;
        // pinned for that engine in Mode\DeferredWrapInteractionTest).
        $this->cursor = new Cursor(row: 0, col: 79);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);
        $this->csi->printable('X');
        $this->assertTrue($this->csi->wrapPending(), 'precondition: phantom armed at right margin');

        $this->csi->printable("\u{0301}");

        $this->assertStringContainsString("\u{0301}", $this->grid->get(0, 79)->char);
        $this->assertSame(' ', $this->grid->get(0, 78)->char, 'neighbour cell must stay untouched');
    }

    public function testDecawmOffOverwritesLastColumn(): void
    {
        // `CSI ? 7 l`: printing at the right margin stops at the last cell —
        // the glyph overwrites it instead of wrapping (emulator parity).
        $this->csi = new CsiHandlerImpl($this->grid, new Cursor(), $this->theme);
        $this->csi->decrst(7, 0x3F);

        $this->csi->cup(1, 80);
        $this->csi->printable('A');
        $this->csi->printable('B');

        $this->assertSame('B', $this->csi->grid()->get(0, 79)->char, 'second glyph overwrites the last column');
        $this->assertSame(0, $this->csi->cursor()->row, 'no wrap, no scroll');
        $this->assertSame(79, $this->csi->cursor()->col);

        // Re-enabling DECAWM resumes the deferred wrap.
        $this->csi->decset(7, 0x3F);
        $this->csi->printable('C');
        $this->assertSame(1, $this->csi->cursor()->row);
        $this->assertSame(1, $this->csi->cursor()->col, 'C landed at (1,0), cursor advanced to (1,1)');
    }

    public function testDecstbmHomesCursor(): void
    {
        $this->cursor = new Cursor(row: 15, col: 0);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->decstbm(5, 10);

        $this->assertSame(0, $this->csi->cursor()->row, 'DECSTBM homes the cursor (VT500 §DECSTBM, emulator parity)');
        $this->assertSame(0, $this->csi->cursor()->col);
    }

    public function testTbcMode0IsNoOp(): void
    {
        $this->cursor = new Cursor(row: 0, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        // tbc(0) should be a no-op in the renderer path (no tab-stop state)
        $this->csi->tbc(0);

        // Cursor position unchanged
        $this->assertSame(0, $this->csi->cursor()->row);
        $this->assertSame(10, $this->csi->cursor()->col);
    }

    public function testTbcMode3IsNoOp(): void
    {
        $this->cursor = new Cursor(row: 0, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        // tbc(3) should be a no-op in the renderer path
        $this->csi->tbc(3);

        $this->assertSame(0, $this->csi->cursor()->row);
        $this->assertSame(10, $this->csi->cursor()->col);
    }

    public function testCrMovesCursorToColumnZero(): void
    {
        $this->cursor = new Cursor(row: 5, col: 40);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->cr();

        $this->assertSame(5, $this->csi->cursor()->row);
        $this->assertSame(0, $this->csi->cursor()->col);
    }

    public function testLfAdvancesCursorDown(): void
    {
        $this->cursor = new Cursor(row: 5, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->lf();

        $this->assertSame(6, $this->csi->cursor()->row);
        $this->assertSame(10, $this->csi->cursor()->col);
    }

    public function testLfAtBottomScrollsRegion(): void
    {
        $this->cursor = new Cursor(row: 23, col: 10);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->lf();

        // Should have scrolled, cursor stays at bottom row
        $this->assertSame(23, $this->csi->cursor()->row);
    }

    // ─── Emulator CSI finals (dispatched by candy-ansi's HandlerAdapter) ─────

    public function testSuScrollsRegionUp(): void
    {
        $grid = new CellGrid(4, 4);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);
        $csi->cup(1, 1);
        $csi->printable('A'); // row 0
        $csi->cup(2, 1);
        $csi->printable('B'); // row 1

        $csi->su(1);

        // Row 1's 'B' moves up to row 0; the bottom row is blanked.
        $this->assertSame('B', $csi->grid()->get(0, 0)->char);
        $this->assertSame(' ', $csi->grid()->get(3, 0)->char);
    }

    public function testSdScrollsRegionDown(): void
    {
        $grid = new CellGrid(4, 4);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);
        $csi->cup(1, 1);
        $csi->printable('A'); // row 0

        $csi->sd(1);

        // 'A' shifts down to row 1; the top row is blanked.
        $this->assertSame(' ', $csi->grid()->get(0, 0)->char);
        $this->assertSame('A', $csi->grid()->get(1, 0)->char);
    }

    public function testIlInsertsBlankLineShiftingDown(): void
    {
        $grid = new CellGrid(4, 4);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);
        $csi->cup(1, 1);
        $csi->printable('A'); // row 0
        $csi->cup(2, 1);
        $csi->printable('B'); // row 1
        $csi->cup(1, 1);      // cursor back to row 0

        $csi->il(1);

        // A blank line is inserted at row 0, pushing 'A' and 'B' down.
        $this->assertSame(' ', $csi->grid()->get(0, 0)->char);
        $this->assertSame('A', $csi->grid()->get(1, 0)->char);
        $this->assertSame('B', $csi->grid()->get(2, 0)->char);
    }

    public function testDlDeletesLineShiftingUp(): void
    {
        $grid = new CellGrid(4, 4);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);
        $csi->cup(1, 1);
        $csi->printable('A'); // row 0
        $csi->cup(2, 1);
        $csi->printable('B'); // row 1
        $csi->cup(1, 1);      // cursor back to row 0

        $csi->dl(1);

        // Row 0 is deleted; 'B' shifts up to row 0.
        $this->assertSame('B', $csi->grid()->get(0, 0)->char);
    }

    public function testIchInsertsBlankCellsShiftingRight(): void
    {
        $grid = new CellGrid(5, 1);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);
        $csi->printable('A');
        $csi->printable('B');
        $csi->printable('C');
        $csi->cup(1, 1); // cursor to col 0

        $csi->ich(1);

        $this->assertSame(' ', $csi->grid()->get(0, 0)->char);
        $this->assertSame('A', $csi->grid()->get(0, 1)->char);
        $this->assertSame('B', $csi->grid()->get(0, 2)->char);
        $this->assertSame('C', $csi->grid()->get(0, 3)->char);
    }

    public function testDchDeletesCellsShiftingLeft(): void
    {
        $grid = new CellGrid(5, 1);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);
        $csi->printable('A');
        $csi->printable('B');
        $csi->printable('C');
        $csi->cup(1, 1); // cursor to col 0

        $csi->dch(1);

        $this->assertSame('B', $csi->grid()->get(0, 0)->char);
        $this->assertSame('C', $csi->grid()->get(0, 1)->char);
        $this->assertSame(' ', $csi->grid()->get(0, 2)->char);
    }

    public function testRepRepeatsLastPrintable(): void
    {
        $grid = new CellGrid(5, 1);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);
        $csi->printable('X'); // col 0, cursor -> col 1

        $csi->rep(3);

        $this->assertSame('X', $csi->grid()->get(0, 0)->char);
        $this->assertSame('X', $csi->grid()->get(0, 1)->char);
        $this->assertSame('X', $csi->grid()->get(0, 2)->char);
        $this->assertSame('X', $csi->grid()->get(0, 3)->char);
        $this->assertSame(4, $csi->cursor()->col);
    }

    public function testRepIsNoOpWithoutPriorPrintable(): void
    {
        $grid = new CellGrid(5, 1);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);

        $csi->rep(3);

        $this->assertSame(' ', $csi->grid()->get(0, 0)->char);
        $this->assertSame(0, $csi->cursor()->col);
    }

    public function testScoscScorcSaveAndRestoreCursor(): void
    {
        $csi = new CsiHandlerImpl($this->grid, new Cursor(), $this->theme);
        $csi->cup(5, 10); // row 4, col 9
        $csi->scosc();
        $csi->cup(1, 1);  // move away to row 0, col 0
        $this->assertSame(0, $csi->cursor()->row);

        $csi->scorc();

        $this->assertSame(4, $csi->cursor()->row);
        $this->assertSame(9, $csi->cursor()->col);
    }

    public function testScorcIsNoOpWithoutPriorSave(): void
    {
        $csi = new CsiHandlerImpl($this->grid, new Cursor(row: 3, col: 7), $this->theme);

        $csi->scorc();

        $this->assertSame(3, $csi->cursor()->row);
        $this->assertSame(7, $csi->cursor()->col);
    }

    // ─── Emulator-parity additions (renderer path, PR #1417 follow-up) ───────

    public function testIlHomesCursorAndDisarmsPhantom(): void
    {
        $grid = new CellGrid(5, 4);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);

        $csi->printable('A');
        $csi->cup(3, 4);
        $csi->printable('B');
        $csi->printable('C'); // parked in the last column, phantom armed
        $this->assertTrue($csi->wrapPending());

        // No cursor motion between the arming print and the IL — the
        // disarm below can only come from IL's own column-home.
        $csi->il(1);

        $this->assertSame(2, $csi->cursor()->row, 'IL keeps the row…');
        $this->assertSame(0, $csi->cursor()->col, '…and homes to column 0 (VT500 §IL, emulator parity)');
        $this->assertFalse($csi->wrapPending(), 'column-home drops the phantom armed at the old margin');
        $this->assertSame(' ', $csi->grid()->get(2, 0)->char, 'inserted line is blank');
    }

    public function testDlHomesCursor(): void
    {
        $grid = new CellGrid(5, 4);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);

        $csi->cup(3, 4);
        $csi->dl(2);

        $this->assertSame(2, $csi->cursor()->row);
        $this->assertSame(0, $csi->cursor()->col);
    }

    public function testCupClampsToBufferNotScrollRegion(): void
    {
        $csi = new CsiHandlerImpl(new CellGrid(5, 4), new Cursor(), $this->theme);
        $csi->decstbm(2, 3);

        $csi->cup(1, 1);
        $this->assertSame(0, $csi->cursor()->row, 'absolute line 1, not region top');

        $csi->cup(999, 999);
        $this->assertSame(3, $csi->cursor()->row, 'clamped to buffer bottom');
        $this->assertSame(4, $csi->cursor()->col, 'clamped to buffer right edge');
    }

    public function testExtremeCupThenPrintLeavesLastRowIntact(): void
    {
        // The curated-parity repro: CUP beyond both margins then one glyph.
        // Old behaviour advanced off the corner and scrolled a line early;
        // deferred wrap parks on the phantom cell instead (emulator parity).
        $csi = new CsiHandlerImpl(new CellGrid(5, 4), new Cursor(), $this->theme);
        $csi->cup(1, 1);
        $csi->printable('K'); // canary on the top row

        $csi->cup(99, 99);
        $csi->printable('Z');

        $this->assertSame('Z', $csi->grid()->get(3, 4)->char, 'Z lands bottom-right');
        $this->assertSame('K', $csi->grid()->get(0, 0)->char, 'no early scroll shifted the grid');
        $this->assertSame(3, $csi->cursor()->row);
        $this->assertSame(4, $csi->cursor()->col);
        $this->assertTrue($csi->wrapPending());

        // Only the NEXT graphic consumes the wrap — and it scrolls there.
        $csi->printable('W');
        $this->assertSame('W', $csi->grid()->get(3, 0)->char);
        $this->assertSame('Z', $csi->grid()->get(2, 4)->char, 'old bottom row moved up intact, Z included');
        $this->assertSame(' ', $csi->grid()->get(0, 0)->char, 'the canary scrolled out of the region');
    }

    public function testSgr29ClearsStrikethrough(): void
    {
        $csi = new CsiHandlerImpl($this->grid, new Cursor(), $this->theme);

        $csi->sgr([9]);
        $csi->printable('S');
        $csi->sgr([29]);
        $csi->printable('N');

        $this->assertSame(Cell::ATTR_STRIKETHROUGH, $this->grid->get(0, 0)->attrs);
        $this->assertSame(0, $this->grid->get(0, 1)->attrs);
    }

    public function testSgr58ConsumeIndexedFormWithoutTouchingPen(): void
    {
        $csi = new CsiHandlerImpl($this->grid, new Cursor(), $this->theme);

        $csi->sgr([58, 5, 33]);
        $csi->printable('U');

        $cell = $this->grid->get(0, 0);
        $this->assertSame(7, $cell->fg, 'the 33 in 58;5;33 must not repaint the fg');
        $this->assertSame(0, $cell->attrs);
    }

    public function testSgr58ConsumeTruecolourFormWithoutTouchingPen(): void
    {
        $csi = new CsiHandlerImpl($this->grid, new Cursor(), $this->theme);

        $csi->sgr([58, 2, 1, 2, 3]);
        $csi->printable('U');

        $cell = $this->grid->get(0, 0);
        $this->assertSame(7, $cell->fg);
        $this->assertSame(0, $cell->attrs, 'the 1/2/3 components must not land as bold/blink/italic');
    }

    public function testSgr38TruecolourTripletIsConsumedNotMisread(): void
    {
        // The renderer cell has no RGB slot (documented representation limit),
        // but the triplet must be eaten whole: `38;2;255;0;0` previously fell
        // through as five independent SGRs and the two 0 components RESET the
        // pen mid-sequence; `38;2;1;2;3` set italic from the 3.
        $csi = new CsiHandlerImpl($this->grid, new Cursor(), $this->theme);

        $csi->sgr([31]);
        $csi->sgr([38, 2, 255, 0, 0]);
        $csi->printable('R');
        $this->assertSame(1, $this->grid->get(0, 0)->fg, 'pen survives the dropped truecolour form');

        $csi->sgr([1]);
        $csi->sgr([48, 2, 1, 2, 3]);
        $csi->printable('B');
        $this->assertSame(1, $this->grid->get(0, 1)->attrs & Cell::ATTR_BOLD, 'bold survives; components not misread');
    }

    public function testSgrUnderlineColonSubparamVsSemicolon(): void
    {
        // With the parser's continuation flags wired, `4:3` is ONE curly
        // underline (renderer: the single underline bit) while `4;3` is two
        // independent SGRs (underline + italic). Without flags — direct
        // construction — the flat-list behaviour is preserved.
        $colon = new CsiHandlerImpl(new CellGrid(8, 2), new Cursor(), $this->theme);
        $colon->attachSubparamsProvider(static fn(): array => [true, false]);
        $colon->sgr([4, 3]);
        $colon->printable('C');
        $this->assertSame(
            Cell::ATTR_UNDERLINE,
            $colon->grid()->get(0, 0)->attrs,
            '4:3 → underline only',
        );

        $semicolon = new CsiHandlerImpl(new CellGrid(8, 2), new Cursor(), $this->theme);
        $semicolon->attachSubparamsProvider(static fn(): array => [false, false]);
        $semicolon->sgr([4, 3]);
        $semicolon->printable('S');
        $this->assertSame(
            Cell::ATTR_UNDERLINE | Cell::ATTR_ITALIC,
            $semicolon->grid()->get(0, 0)->attrs,
            '4;3 → underline + italic',
        );

        $flat = new CsiHandlerImpl(new CellGrid(8, 2), new Cursor(), $this->theme);
        $flat->sgr([4, 3]);
        $flat->printable('F');
        $this->assertSame(
            Cell::ATTR_UNDERLINE | Cell::ATTR_ITALIC,
            $flat->grid()->get(0, 0)->attrs,
            'unattached stays flat (historical renderer semantics)',
        );
    }

    public function testSgrUnderlineColonZeroClearsUnderline(): void
    {
        $csi = new CsiHandlerImpl($this->grid, new Cursor(), $this->theme);
        $csi->attachSubparamsProvider(static fn(): array => [true, false]);

        $csi->sgr([4]);
        $csi->sgr([4, 0]);
        $csi->printable('U');

        $this->assertSame(0, $this->grid->get(0, 0)->attrs, '4:0 = underline off (SgrHandler parity)');
    }

    public function testDecawmOffClampsWideGlyphWithoutWrapping(): void
    {
        // `?7l` + a double-width glyph at the last column: dropped, cursor
        // parked on the last column, no scroll (emulator printChar guard).
        $grid = new CellGrid(4, 2);
        $csi = new CsiHandlerImpl($grid, new Cursor(), $this->theme);
        $csi->decrst(7, 0x3F);

        $csi->cup(1, 4);
        $csi->printable('あ');

        $this->assertSame(' ', $grid->get(1, 0)->char, 'no wrap to the next row');
        $this->assertSame(3, $csi->cursor()->col);
        $this->assertFalse($csi->wrapPending());
    }
}
