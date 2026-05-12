<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

/**
 * Computer AI opponent for VS Computer mode.
 *
 * Evaluates board states using a weighted heuristic:
 *   - Aggregate height: penalize tall columns
 *   - Holes: penalize empty cells with blocks above
 *   - Gaps: penalize horizontal gaps in filled rows
 *   - Lines cleared: reward completed rows
 *
 * The AI considers all possible placements (x position + rotation)
 * and picks the move with the best score.
 */
final class Computer
{
    /** Weights for board evaluation heuristic. */
    private const WEIGHT_HEIGHT  = -4.5;
    private const WEIGHT_HOLES   = -7.5;
    private const WEIGHT_GAPS    = -3.5;
    private const WEIGHT_LINES   = 9.0;

    /**
     * Find the best move for the given piece on the given board.
     *
     * @return array{0:int,1:int,2:float} [deltaX, rotationDelta, score]
     */
    public function bestMove(Board $board, Piece $piece): array
    {
        $bestScore = -INF;
        $bestDx = 0;
        $bestRot = 0;

        // Try all rotations (0-3)
        foreach (range(0, 3) as $rotations) {
            $rotated = $piece;
            for ($r = 0; $r < $rotations; $r++) {
                $rotated = $rotated->rotated(1);
            }

            // Get the piece cells to find width
            $cells = $rotated->cells();
            $minX = PHP_INT_MAX;
            $maxX = PHP_INT_MIN;
            foreach ($cells as [$x,]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
            }
            $pieceWidth = $maxX - $minX + 1;

            // Try all x positions (leftmost valid to rightmost valid)
            $maxXPos = Board::COLS - $pieceWidth;
            for ($dx = -$minX; $dx <= $maxXPos; $dx++) {
                $moved = $rotated->moved($dx, 0);
                if (!$board->fits($moved)) {
                    continue;
                }

                // Find landing position
                $dropped = $board->dropPiece($moved);
                $placed = $board->place($dropped);
                $score = $this->evaluate($placed);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestDx = $dx;
                    $bestRot = $rotations;
                }
            }
        }

        return [$bestDx, $bestRot, $bestScore];
    }

    /**
     * Evaluate a board state and return a score.
     * Higher is better.
     */
    public function evaluate(Board $board): float
    {
        $rows = $board->rows();

        // Aggregate height: sum of all column heights
        $height = $this->aggregateHeight($rows);

        // Count holes: empty cells with at least one block above
        $holes = $this->countHoles($rows);

        // Count gaps: horizontal gaps in filled rows
        $gaps = $this->countGaps($rows);

        // Count lines (would be cleared externally)
        $lines = $this->countCompleteLines($rows);

        return self::WEIGHT_HEIGHT * $height
            + self::WEIGHT_HOLES * $holes
            + self::WEIGHT_GAPS * $gaps
            + self::WEIGHT_LINES * $lines;
    }

    /**
     * @param list<list<?Tetromino>> $rows
     */
    private function aggregateHeight(array $rows): int
    {
        $height = 0;
        // Only count visible rows (from HIDDEN_ROWS to ROWS)
        for ($col = 0; $col < Board::COLS; $col++) {
            for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
                if ($rows[$row][$col] !== null) {
                    // Height from bottom (visible rows = 20)
                    $height += Board::ROWS - $row;
                    break;
                }
            }
        }
        return $height;
    }

    /**
     * @param list<list<?Tetromino>> $rows
     */
    private function countHoles(array $rows): int
    {
        $holes = 0;
        // Only look at visible rows
        for ($col = 0; $col < Board::COLS; $col++) {
            $blockSeen = false;
            for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
                if ($rows[$row][$col] !== null) {
                    $blockSeen = true;
                } elseif ($blockSeen) {
                    $holes++;
                }
            }
        }
        return $holes;
    }

    /**
     * @param list<list<?Tetromino>> $rows
     */
    private function countGaps(array $rows): int
    {
        $gaps = 0;
        // Only look at visible rows
        for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
            $rowCells = $rows[$row];
            // Only check rows that are partially filled
            $hasBlock = false;
            $hasGap = false;
            for ($col = 0; $col < Board::COLS; $col++) {
                if ($rowCells[$col] !== null) {
                    $hasBlock = true;
                } elseif ($hasBlock) {
                    $hasGap = true;
                }
            }
            if ($hasBlock && $hasGap) {
                // Count individual gap cells
                for ($col = 0; $col < Board::COLS; $col++) {
                    if ($rowCells[$col] === null) {
                        $gaps++;
                    }
                }
            }
        }
        return $gaps;
    }

    /**
     * @param list<list<?Tetromino>> $rows
     */
    private function countCompleteLines(array $rows): int
    {
        $lines = 0;
        for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
            $full = true;
            for ($col = 0; $col < Board::COLS; $col++) {
                if ($rows[$row][$col] === null) {
                    $full = false;
                    break;
                }
            }
            if ($full) {
                $lines++;
            }
        }
        return $lines;
    }
}
