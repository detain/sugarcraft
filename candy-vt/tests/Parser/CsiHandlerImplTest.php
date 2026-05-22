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

    public function testDecsetCursorHidden(): void
    {
        $this->cursor = new Cursor(visible: true);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->decset(25);

        $this->assertFalse($this->csi->cursor()->visible);
    }

    public function testDecrstCursorVisible(): void
    {
        $this->cursor = new Cursor(visible: false);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->decrst(25);

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

        $this->csi->cup(1, 1);
        $this->assertSame(4, $this->csi->cursor()->row);

        $this->csi->cup(20, 1);
        $this->assertSame(9, $this->csi->cursor()->row);
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

    public function testPrintableWrapsAtRightEdge(): void
    {
        $this->cursor = new Cursor(row: 0, col: 79);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->printable('X');

        $this->assertSame(0, $this->csi->cursor()->col);
        $this->assertSame(1, $this->csi->cursor()->row);
    }

    public function testDecstbmClampsCursorIfOutsideRegion(): void
    {
        $this->cursor = new Cursor(row: 15, col: 0);
        $this->csi = new CsiHandlerImpl($this->grid, $this->cursor, $this->theme);

        $this->csi->decstbm(5, 10);

        $this->assertSame(9, $this->csi->cursor()->row);
    }
}
