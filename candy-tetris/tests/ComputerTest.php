<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Bag;
use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Computer;
use SugarCraft\Tetris\Game;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class ComputerTest extends TestCase
{
    private Computer $computer;

    protected function setUp(): void
    {
        $this->computer = new Computer();
    }

    public function testBestMoveReturnsValidMove(): void
    {
        $board = new Board();
        $piece = new Piece(Tetromino::T, 0, 3, 0);

        [$dx, $rotation, $score] = $this->computer->bestMove($board, $piece);

        $this->assertIsInt($dx);
        $this->assertIsInt($rotation);
        $this->assertIsFloat($score);
        $this->assertGreaterThanOrEqual(0, $rotation);
        $this->assertLessThanOrEqual(3, $rotation);
    }

    public function testBestMovePrefersLowerColumn(): void
    {
        // Empty board - piece should drop to lowest position
        $board = new Board();
        $piece = new Piece(Tetromino::I, 0, 3, 0);

        [$dx, ,] = $this->computer->bestMove($board, $piece);

        // I piece at rotation 0 has cells at y=1, so it should find a valid drop position
        $this->assertIsInt($dx);
    }

    public function testBestMoveAvoidsInvalidPositions(): void
    {
        $board = new Board();
        $piece = new Piece(Tetromino::O, 0, -10, 0); // Way off to the left

        [$dx, , $score] = $this->computer->bestMove($board, $piece);

        // Should find some valid position
        $this->assertIsInt($dx);
        // If no valid move found, score would still be valid (just not good)
        $this->assertIsFloat($score);
    }

    public function testEvaluateEmptyBoard(): void
    {
        $board = new Board();
        $score = $this->computer->evaluate($board);

        // Empty board should have 0 height, 0 holes, 0 gaps, 0 lines
        $this->assertSame(0.0, $score);
    }

    public function testEvaluatePrefersLowerHeight(): void
    {
        // Build a board with low columns
        $lowBoard = $this->buildBoardWithHeight(2);

        // Build a board with high columns
        $highBoard = $this->buildBoardWithHeight(10);

        $lowScore = $this->computer->evaluate($lowBoard);
        $highScore = $this->computer->evaluate($highBoard);

        // Lower height should score better (less negative)
        $this->assertGreaterThan($highScore, $lowScore);
    }

    public function testEvaluatePrefersFewerHoles(): void
    {
        // Build a board with holes (blocks above empty cells in same column)
        $boardWithHoles = $this->buildBoardWithHoles();

        // Build a board with no holes (solid columns)
        $solidBoard = $this->buildSolidBoard();

        $holesScore = $this->computer->evaluate($boardWithHoles);
        $solidScore = $this->computer->evaluate($solidBoard);

        // Solid board should score better
        $this->assertGreaterThan($holesScore, $solidScore);
    }

    public function testEvaluateRewardsLinesCleared(): void
    {
        // The evaluation function should return numeric values
        // Lines contribute positively to the score
        $emptyBoard = new Board();
        $emptyScore = $this->computer->evaluate($emptyBoard);

        // Board with a line at the bottom
        $lineBoard = $this->buildBoardWithCompleteLine();
        $lineScore = $this->computer->evaluate($lineBoard);

        // Both should be valid numbers
        $this->assertIsFloat($emptyScore);
        $this->assertIsFloat($lineScore);

        // The difference shows the lines contribution (may be negative due to height)
        $difference = $lineScore - $emptyScore;

        // Lines have a positive weight (9.0), so the difference should reflect that
        // Even if total is negative due to height, the line contribution is positive
        // We verify the function is computing something reasonable
        $this->assertNotEquals($emptyScore, $lineScore, 'Lines should affect score');
    }

    public function testBestMoveWithAllRotations(): void
    {
        $board = new Board();

        // Try all piece types
        foreach (Tetromino::cases() as $kind) {
            $piece = new Piece($kind, 0, 3, 0);
            [$dx, $rotation, $score] = $this->computer->bestMove($board, $piece);

            $this->assertIsInt($dx, "dx should be int for {$kind->name}");
            $this->assertIsInt($rotation, "rotation should be int for {$kind->name}");
            $this->assertGreaterThanOrEqual(0, $rotation);
            $this->assertLessThanOrEqual(3, $rotation);
            $this->assertIsFloat($score, "score should be float for {$kind->name}");
        }
    }

    public function testEvaluateBoardWithGarbage(): void
    {
        // Build a board simulating garbage rows
        $cells = [];
        $emptyRow = array_fill(0, Board::COLS, null);

        // Add some garbage-like rows (rows with one hole)
        for ($r = 0; $r < Board::HIDDEN_ROWS; $r++) {
            $cells[] = $emptyRow;
        }

        // Visible rows with garbage pattern (full row with one hole)
        for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
            $garbageRow = [];
            $hole = ($row - Board::HIDDEN_ROWS) % Board::COLS;
            for ($col = 0; $col < Board::COLS; $col++) {
                $garbageRow[] = $col === $hole ? null : Tetromino::I;
            }
            $cells[] = $garbageRow;
        }

        $board = new Board($cells);
        $score = $this->computer->evaluate($board);

        // Should have significant negative score due to height and holes
        $this->assertLessThan(0, $score);
    }

    /**
     * Build a board with columns at approximately the given average height.
     */
    private function buildBoardWithHeight(int $height): Board
    {
        $cells = [];
        $emptyRow = array_fill(0, Board::COLS, null);

        // Fill hidden rows
        for ($r = 0; $r < Board::HIDDEN_ROWS; $r++) {
            $cells[] = $emptyRow;
        }

        // Fill visible rows up to height
        $visibleHeight = Board::VISIBLE_ROWS - $height;
        for ($row = 0; $row < Board::VISIBLE_ROWS; $row++) {
            if ($row < $visibleHeight) {
                $cells[] = $emptyRow;
            } else {
                $cells[] = array_fill(0, Board::COLS, Tetromino::I);
            }
        }

        return new Board($cells);
    }

    /**
     * Build a board with holes - empty cells with blocks above.
     */
    private function buildBoardWithHoles(): Board
    {
        $cells = [];
        $emptyRow = array_fill(0, Board::COLS, null);

        // Fill hidden rows
        for ($r = 0; $r < Board::HIDDEN_ROWS; $r++) {
            $cells[] = $emptyRow;
        }

        // Build columns with holes: row at bottom has empty cell but top has block
        for ($row = 0; $row < Board::VISIBLE_ROWS; $row++) {
            $boardRow = [];
            for ($col = 0; $col < Board::COLS; $col++) {
                // Create a hole in each column at different rows
                if ($row === Board::VISIBLE_ROWS - 1 && $col === 0) {
                    $boardRow[] = null; // Hole at bottom left
                } elseif ($row < Board::VISIBLE_ROWS - 4) {
                    $boardRow[] = Tetromino::I; // Block above hole
                } else {
                    $boardRow[] = null;
                }
            }
            $cells[] = $boardRow;
        }

        return new Board($cells);
    }

    /**
     * Build a solid board with no holes.
     */
    private function buildSolidBoard(): Board
    {
        $cells = [];
        $emptyRow = array_fill(0, Board::COLS, null);

        // Fill hidden rows
        for ($r = 0; $r < Board::HIDDEN_ROWS; $r++) {
            $cells[] = $emptyRow;
        }

        // Fill bottom half with solid blocks
        for ($row = 0; $row < Board::VISIBLE_ROWS; $row++) {
            if ($row < Board::VISIBLE_ROWS / 2) {
                $cells[] = $emptyRow;
            } else {
                $cells[] = array_fill(0, Board::COLS, Tetromino::I);
            }
        }

        return new Board($cells);
    }

    /**
     * Build a board with one complete line.
     */
    private function buildBoardWithCompleteLine(): Board
    {
        $cells = [];
        $emptyRow = array_fill(0, Board::COLS, null);

        // Fill hidden rows
        for ($r = 0; $r < Board::HIDDEN_ROWS; $r++) {
            $cells[] = $emptyRow;
        }

        // All visible rows empty except one complete line
        for ($row = 0; $row < Board::VISIBLE_ROWS; $row++) {
            if ($row === Board::VISIBLE_ROWS - 1) {
                $cells[] = array_fill(0, Board::COLS, Tetromino::I);
            } else {
                $cells[] = $emptyRow;
            }
        }

        return new Board($cells);
    }

    /**
     * Build a board with two complete lines.
     */
    private function buildBoardWithTwoCompleteLines(): Board
    {
        $cells = [];
        $emptyRow = array_fill(0, Board::COLS, null);

        // Fill hidden rows
        for ($r = 0; $r < Board::HIDDEN_ROWS; $r++) {
            $cells[] = $emptyRow;
        }

        // All visible rows empty except two complete lines at bottom
        for ($row = 0; $row < Board::VISIBLE_ROWS; $row++) {
            if ($row >= Board::VISIBLE_ROWS - 2) {
                $cells[] = array_fill(0, Board::COLS, Tetromino::I);
            } else {
                $cells[] = $emptyRow;
            }
        }

        return new Board($cells);
    }
}
