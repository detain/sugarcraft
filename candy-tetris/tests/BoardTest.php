<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class BoardTest extends TestCase
{
    public function testNewBoardIsEmpty(): void
    {
        $b = new Board();
        for ($y = 0; $y < Board::ROWS; $y++) {
            for ($x = 0; $x < Board::COLS; $x++) {
                $this->assertNull($b->cellAt($x, $y));
                $this->assertFalse($b->isOccupied($x, $y));
            }
        }
    }

    public function testWallsAndFloorAreOccupied(): void
    {
        $b = new Board();
        $this->assertTrue($b->isOccupied(-1, 0));
        $this->assertTrue($b->isOccupied(Board::COLS, 0));
        $this->assertTrue($b->isOccupied(0, Board::ROWS));
        $this->assertTrue($b->isOccupied(0, -1));
    }

    public function testFitsRejectsPieceCrossingWall(): void
    {
        $b = new Board();
        $oob = new Piece(Tetromino::I, 0, x: -2, y: 5);
        $this->assertFalse($b->fits($oob));
    }

    public function testFitsAcceptsPieceInsideEmptyBoard(): void
    {
        $b = new Board();
        $p = new Piece(Tetromino::T, 0, x: 3, y: 5);
        $this->assertTrue($b->fits($p));
    }

    public function testPlaceLocksCellsIntoTheBoard(): void
    {
        $b = new Board();
        $p = new Piece(Tetromino::O, 0, x: 4, y: 18);
        $next = $b->place($p);
        // O-piece cells at (1,0),(2,0),(1,1),(2,1) → +offset → (5,18),(6,18),(5,19),(6,19)
        $this->assertSame(Tetromino::O, $next->cellAt(5, 18));
        $this->assertSame(Tetromino::O, $next->cellAt(6, 19));
        $this->assertNull($next->cellAt(0, 0));
        // Original board unchanged.
        $this->assertNull($b->cellAt(5, 18));
    }

    public function testClearLinesRemovesFullRowsAndShiftsDown(): void
    {
        // Build a board with row Y=23 fully filled.
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[23] = array_fill(0, Board::COLS, Tetromino::I);
        // And one stray Z above it.
        $rows[20][3] = Tetromino::Z;
        $b = new Board($rows);
        [$next, $count] = $b->clearLines();
        $this->assertSame(1, $count);
        // Row that contained stray Z is now at 21 (shifted down by 1).
        $this->assertSame(Tetromino::Z, $next->cellAt(3, 21));
        // Old bottom row is now empty.
        for ($x = 0; $x < Board::COLS; $x++) {
            $this->assertNull($next->cellAt($x, 23));
        }
    }

    public function testClearLinesHandlesMultipleRows(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[20] = array_fill(0, Board::COLS, Tetromino::I);
        $rows[21] = array_fill(0, Board::COLS, Tetromino::I);
        $rows[22] = array_fill(0, Board::COLS, Tetromino::I);
        $rows[23] = array_fill(0, Board::COLS, Tetromino::I);
        $b = new Board($rows);
        [, $count] = $b->clearLines();
        $this->assertSame(4, $count);
    }

    public function testDropPiecePlacesAgainstFloor(): void
    {
        $b = new Board();
        $p = new Piece(Tetromino::O, 0, x: 4, y: 0);
        $resting = $b->dropPiece($p);
        // O-piece occupies rows y+0 and y+1; with floor at ROWS=24, resting y must put cells at 22,23.
        $this->assertSame(Board::ROWS - 2, $resting->y);
    }

    public function testDropPieceStopsOnTopOfStack(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[Board::ROWS - 1] = array_fill(0, Board::COLS, Tetromino::I);
        $b = new Board($rows);
        $p = new Piece(Tetromino::O, 0, x: 4, y: 0);
        $resting = $b->dropPiece($p);
        // Stack on row 23, O-piece occupies +0,+1 from y → must rest with y+1 = 22, so y = 21.
        $this->assertSame(Board::ROWS - 3, $resting->y);
    }

    public function testIsPerfectClearOnEmptyBoard(): void
    {
        $b = new Board();
        $this->assertTrue($b->isPerfectClear());
    }

    public function testIsPerfectClearOnBoardWithCells(): void
    {
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[10][5] = Tetromino::I;
        $b = new Board($rows);
        $this->assertFalse($b->isPerfectClear());
    }

    public function testIsPerfectClearAfterFullLineClear(): void
    {
        // Build a board with exactly one full row at the bottom and all else empty.
        // When that row is cleared, the board becomes perfect clear.
        $rows = array_fill(0, Board::ROWS, array_fill(0, Board::COLS, null));
        $rows[Board::ROWS - 1] = array_fill(0, Board::COLS, Tetromino::I);
        $b = new Board($rows);
        [$cleared, $count] = $b->clearLines();
        $this->assertSame(1, $count);
        $this->assertTrue($cleared->isPerfectClear());
    }
}
