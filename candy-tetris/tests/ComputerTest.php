<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Computer;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural oracle suite for the VS-mode AI (candy-tetris-001).
 *
 * Every pin here asserts a concrete decision outcome — a completed line,
 * a flat landing, a strictly-better candidate score — never a return
 * type. Mutating any heuristic weight or the arg-max comparison in
 * Computer::bestMove() must turn at least one test below red; that is the
 * contract of this file (the suite it replaces could not fail at all).
 */
final class ComputerTest extends TestCase
{
    private Computer $computer;

    protected function setUp(): void
    {
        $this->computer = new Computer();
    }

    public function testBestMoveCompletesAvailableLine(): void
    {
        // Bottom row misses cols 3-5; the T covers exactly that gap.
        $board = $this->boardWithRows([23 => [0, 1, 2, 6, 7, 8, 9]]);
        $piece = new Piece(Tetromino::T, 0, 3, 0);

        [$dx, $rotation, $score] = $this->computer->bestMove($board, $piece);
        $placed = $this->place($board, $piece, $dx, $rotation);

        self::assertCount(
            Board::COLS,
            array_filter($placed->rows()[Board::ROWS - 1]),
            'a takeable line must be taken: the bottom row must come out complete'
        );
        self::assertSame($score, $this->computer->evaluate($placed), 'the reported score must be the placed board\'s score');
    }

    public function testBestMoveSitsFlatNotUnderLedges(): void
    {
        // Two ledges (cols 2 and 5 at row 22) over a bottom-row gap: the
        // safe seat is the open gap between them, not a slot that would
        // roof over empty cells.
        $board = $this->boardWithRows([23 => [0, 1, 2, 5, 6, 7, 8, 9], 22 => [2, 5]]);
        $piece = new Piece(Tetromino::O, 0, 3, 0);

        [$dx, $rotation,] = $this->computer->bestMove($board, $piece);
        $placed = $this->place($board, $piece, $dx, $rotation);

        self::assertSame(
            $this->countHoles($board),
            $this->countHoles($placed),
            'the chosen placement must not bury a hole'
        );
        self::assertCount(Board::COLS, array_filter($placed->rows()[Board::ROWS - 1]), 'the O seats in the open gap, completing the floor');
    }

    public function testBestMovePrefersLowerStack(): void
    {
        // A stair: cols 2-9 stand three rows tall, cols 0-1 sit at the
        // floor. The floor seat is strictly lower than any ledge-top seat.
        $board = $this->boardWithRows([
            21 => range(2, 9),
            22 => range(2, 9),
            23 => range(2, 9),
        ]);
        $piece = new Piece(Tetromino::O, 0, 3, 0);

        [$dx, $rotation,] = $this->computer->bestMove($board, $piece);
        $placed = $this->place($board, $piece, $dx, $rotation);

        self::assertCount(
            Board::COLS,
            array_filter($placed->rows()[Board::ROWS - 1]),
            'prefers lower: the O completes the low floor seat instead of stacking on the stair'
        );
    }

    public function testBestMoveOnEmptyBoardLandsOnTheFloor(): void
    {
        $board = new Board();
        $piece = new Piece(Tetromino::I, 0, 3, 0);

        [$dx, $rotation,] = $this->computer->bestMove($board, $piece);

        self::assertSame(0, $rotation % 2, 'horizontal or vertical I both sit on the floor');
        $landed = $board->dropPiece($piece->rotated($rotation)->moved($dx, 0));
        $rows = $landed->cells();
        self::assertSame(
            Board::ROWS - 1,
            max(array_map(static fn(array $c): int => $c[1], $rows)),
            'every candidate drop must bottom out'
        );
    }

    public function testNoValidMoveReturnsTheDocumentedContract(): void
    {
        // A saturated board admits no placement at all. The contract (now
        // pinned, previously only prose in ComputerTest and a comment):
        // [0, 0, -INF] — the -INF score is the sentinel callers test for.
        $board = $this->boardWithRows(array_fill(0, Board::ROWS, range(0, Board::COLS - 1)));
        $piece = new Piece(Tetromino::O, 0, 3, 0);

        [$dx, $rotation, $score] = $this->computer->bestMove($board, $piece);

        self::assertSame(0, $dx, 'no valid move: the delta leg is the neutral 0');
        self::assertSame(0, $rotation, 'no valid move: the rotation leg is the neutral 0');
        self::assertSame(-INF, $score, 'no valid move: the score leg is the -INF sentinel');
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
        $lowScore = $this->computer->evaluate($this->boardWithRows([22 => range(0, 9), 23 => range(0, 9)]));
        $highScore = $this->computer->evaluate($this->boardWithRows(array_fill(14, 10, range(0, 9))));

        // Lower height should score better (less negative)
        $this->assertGreaterThan($highScore, $lowScore);
    }

    public function testEvaluatePrefersFewerHoles(): void
    {
        $holesScore = $this->computer->evaluate($this->buildBoardWithHoles());

        // Build a board with no holes (solid columns)
        $solidBoard = $this->buildSolidBoard();

        $solidScore = $this->computer->evaluate($solidBoard);

        // Solid board should score better
        $this->assertGreaterThan($holesScore, $solidScore);
    }

    public function testEvaluateRewardsCompletedLine(): void
    {
        // One block more completes the bottom row; the completed row must
        // evaluate strictly better despite the extra height unit. Isolates
        // WEIGHT_LINES against two boards that differ in exactly one cell.
        $gapRow = $this->computer->evaluate($this->boardWithRows([23 => range(1, 9)]));
        $completeRow = $this->computer->evaluate($this->boardWithRows([23 => range(0, 9)]));

        $this->assertGreaterThan($gapRow, $completeRow, 'a completed line must be worth more than its height cost');
    }

    public function testEvaluatePunishesHolesDirectly(): void
    {
        // Same total height, same gaps, same lines: board B just hollows
        // row 22 out of a solid block. Isolates WEIGHT_HOLES.
        $clean = $this->computer->evaluate($this->boardWithRows([
            21 => range(0, 8), 22 => range(0, 8), 23 => range(0, 8),
        ]));
        $holey = $this->computer->evaluate($this->boardWithRows([
            21 => range(0, 8), 23 => range(0, 8),
        ]));

        $this->assertGreaterThan($holey, $clean, 'holes must pay the WEIGHT_HOLES penalty at equal height');
    }

    public function testEvaluatePunishesGapsDirectly(): void
    {
        // Same height and holes, mirrored left→right: blocks at cols 1-9
        // leave no null-after-block leg, blocks at cols 0-8 leave one per
        // row. Isolates WEIGHT_GAPS.
        $raggedRight = $this->computer->evaluate($this->boardWithRows([
            21 => range(0, 8), 22 => range(0, 8), 23 => range(0, 8),
        ]));
        $flushRight = $this->computer->evaluate($this->boardWithRows([
            21 => range(1, 9), 22 => range(1, 9), 23 => range(1, 9),
        ]));

        $this->assertGreaterThan($raggedRight, $flushRight, 'a right edge flush with the wall must beat one that leaves gaps');
    }

    public function testBestMoveWithAllRotationsPlacesLegally(): void
    {
        $board = new Board();

        // Try all piece types: the returned move must reconstruct to a
        // placement that fits the board and cannot fall another row.
        foreach (Tetromino::cases() as $kind) {
            $piece = new Piece($kind, 0, 3, 0);
            [$dx, $rotation, $score] = $this->computer->bestMove($board, $piece);

            $moved = $piece->rotated($rotation)->moved($dx, 0);
            self::assertTrue($board->fits($moved), "{$kind->name}: returned move must name a position that fits");

            $dropped = $board->dropPiece($moved);
            self::assertFalse(
                $board->fits($dropped->moved(0, 1)),
                "{$kind->name}: the placed piece must have bottomed out (one more step must not fit)"
            );
            self::assertSame(
                $score,
                $this->computer->evaluate($board->place($dropped)),
                "{$kind->name}: the reported score must be the placed board's score"
            );
        }
    }

    public function testEvaluateBoardWithGarbage(): void
    {
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
     * @param array<int, list<int>> $filledByRow absolute row index → cols filled
     */
    private function boardWithRows(array $filledByRow): Board
    {
        $cells = [];
        $emptyRow = array_fill(0, Board::COLS, null);

        for ($row = 0; $row < Board::ROWS; $row++) {
            if (!isset($filledByRow[$row])) {
                $cells[] = $emptyRow;
                continue;
            }
            $line = $emptyRow;
            foreach ($filledByRow[$row] as $col) {
                $line[$col] = Tetromino::I;
            }
            $cells[] = $line;
        }

        return new Board($cells);
    }

    /**
     * Rebuild the placement bestMove committed to: rotate, shift, drop, place.
     */
    private function place(Board $board, Piece $piece, int $dx, int $rotation): Board
    {
        return $board->place($board->dropPiece($piece->rotated($rotation)->moved($dx, 0)));
    }

    private function countHoles(Board $board): int
    {
        $holes = 0;
        $rows = $board->rows();
        for ($col = 0; $col < Board::COLS; $col++) {
            $blockSeen = false;
            for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
                if ($rows[$row][$col] !== null) {
                    $blockSeen = true;
                } elseif ($blockSeen === true) {
                    $holes++;
                }
            }
        }
        return $holes;
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
}
