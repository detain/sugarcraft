<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Scoring;

use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Scoring\TSpin;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class TSpinTest extends TestCase
{
    public function testNonTPieceReturnsNoSpin(): void
    {
        $board = new Board();
        $piece = new Piece(Tetromino::I, 0, 5, 15);
        $tspin = TSpin::detect($board, $piece, 0);
        $this->assertFalse($tspin->active);
        $this->assertFalse($tspin->mini);
    }

    public function testNoRotationMeansNoSpin(): void
    {
        $board = new Board();
        // T at rotation 0, no rotation occurred (wasRotated === 0)
        $piece = new Piece(Tetromino::T, 0, 5, 15);
        $tspin = TSpin::detect($board, $piece, 0);
        $this->assertFalse($tspin->active);
    }

    public function testTPieceRotatedButCornersNotFilled(): void
    {
        $board = new Board();
        // T was rotated from 0 to 1, but no corners are filled
        $piece = new Piece(Tetromino::T, 1, 5, 15);
        $tspin = TSpin::detect($board, $piece, 0);
        $this->assertFalse($tspin->active);
    }

    public function testTPieceRotatedWithThreeCornersFilledIsTSpin(): void
    {
        // Build a board where all 4 corners around the T are filled.
        // T at (5,15) rotation 2 has cells with minY=16, maxY=17.
        // Corners: TL=(4,15), TR=(8,15), BL=(4,18), BR=(8,18).
        $rows = [];
        for ($y = 0; $y < Board::ROWS; $y++) {
            $row = array_fill(0, Board::COLS, null);
            $rows[$y] = $row;
        }
        $rows[15][4] = Tetromino::I;  // TL corner
        $rows[15][8] = Tetromino::I;  // TR corner
        $rows[18][4] = Tetromino::I;  // BL corner
        $rows[18][8] = Tetromino::I;  // BR corner

        $board = new Board($rows);
        $piece = new Piece(Tetromino::T, 2, 5, 15);
        $tspin = TSpin::detect($board, $piece, 0);
        $this->assertTrue($tspin->active);
        $this->assertFalse($tspin->mini, '4 corners = full T-Spin, not mini');
    }

    public function testTPieceWithTwoFrontCornersIsTSpinMini(): void
    {
        // T rotation 0: cells have minY=15, maxY=16 → corners at rows 14 and 17.
        // Front corners for rotation 0 = TL (4,14) and TR (8,14).
        $rows = [];
        for ($y = 0; $y < Board::ROWS; $y++) {
            $row = array_fill(0, Board::COLS, null);
            $rows[$y] = $row;
        }
        $rows[14][4] = Tetromino::I;  // TL corner
        $rows[14][8] = Tetromino::I;  // TR corner
        // BL and BR are NOT filled → only front corners = mini

        $board = new Board($rows);
        // T at rotation 0, was rotated from rotation 1
        $piece = new Piece(Tetromino::T, 0, 5, 15);
        $tspin = TSpin::detect($board, $piece, 1);
        $this->assertTrue($tspin->active);
        $this->assertTrue($tspin->mini, 'Only front corners filled (rotation 0) = T-Spin Mini');
    }

    public function testOutOfBoundsCornerCountsAsFilled(): void
    {
        // T at x=0, rotation 0: minX=1, maxX=2. Corners: TL=(0,14), TR=(3,14), BL=(0,17), BR=(3,17).
        // TL corner at x=0 is in bounds (not OOB for x), but TL at y=14 is above board top? No.
        // For T at x=0, minX=0 (one of the T cells), so minX-1=-1 → OOB (wall).
        $rows = [];
        for ($y = 0; $y < Board::ROWS; $y++) {
            $row = array_fill(0, Board::COLS, null);
            $rows[$y] = $row;
        }
        $board = new Board($rows);
        // T at x=0 and y=5 (not at the very top of board)
        $piece = new Piece(Tetromino::T, 0, 0, 5);
        // Cells at rotation 0: [[1,0],[0,1],[1,1],[2,1]] + (0,5) = (1,5),(0,6),(1,6),(2,6)
        // minX=0, maxX=2, minY=5, maxY=6
        // TL=(minX-1,minY-1)=(-1,4)→OOB (wall), TR=(3,4)→in bounds, BL=(-1,7)→OOB, BR=(3,7)→in bounds
        // We fill TR at (3,4) and BR at (3,7) to get 2 corners = T-Spin
        $rows[4][3] = Tetromino::I;
        $rows[7][3] = Tetromino::I;
        $board2 = new Board($rows);
        $tspin = TSpin::detect($board2, $piece, 1);
        $this->assertTrue($tspin->active, 'OOB corners (wall) + filled internal corners should be T-Spin');
    }

    public function testPointsConstants(): void
    {
        $this->assertSame(100, TSpin::T_SPIN_MINI_POINTS);
        $this->assertSame(400, TSpin::T_SPIN_POINTS);
    }
}
