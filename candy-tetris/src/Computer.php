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
        // candy-tetris-051: O ships four identical rotation states and
        // every kind repeats at least one shape — each duplicate
        // re-scanned the whole board for a score that the strict '>'
        // tie-break can never promote over the first arrival. Remember
        // the shapes; skip the echoes.
        $seenShapes = [];

        foreach (range(0, 3) as $rotation) {
            $rotated = $piece;
            for ($r = 0; $r < $rotation; $r++) {
                $rotated = $rotated->rotated(1);
            }

            $cells = $rotated->cells();
            $shapeKey = serialize($cells);
            if (isset($seenShapes[$shapeKey]) === true) {
                continue;
            }
            $seenShapes[$shapeKey] = true;

            $minX = PHP_INT_MAX;
            $maxX = PHP_INT_MIN;
            foreach ($cells as [$x,]) {
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
            }

            // candy-tetris-073: the right bound is where the RIGHTMOST
            // cell still lands inside the wall — the old count-style
            // COLS - pieceWidth over-shot by minX whenever the shape did
            // not touch its box's left edge, feeding guaranteed-fail
            // probes to fits(). Coverage was complete (the extras always
            // refused); they were only wasteful, and the name said
            // "position" while holding a count.
            $maxDx = Board::COLS - 1 - $maxX;
            for ($dx = -$minX; $dx <= $maxDx; $dx++) {
                $moved = $rotated->moved($dx, 0);
                if ($board->fits($moved) === false) {
                    continue;
                }

                $dropped = $board->dropPiece($moved);
                $placed = $board->place($dropped);
                $score = $this->evaluate($placed);

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestDx = $dx;
                    $bestRot = $rotation;
                }
            }
        }

        return [$bestDx, $bestRot, $bestScore];
    }

    /**
     * Evaluate a board state and return a score. Higher is better.
     *
     * candy-tetris-051: one row-major pass replaces the four scans this
     * method used to dispatch (height, holes, gaps, lines each walked the
     * board on its own). The per-column facts — first fill (height) and
     * block-seen (holes) — ride a ten-slot state array down the rows; the
     * per-row facts (nulls, block presence, null-after-block) are decided
     * inside the same column sweep that already reads the row. This runs
     * once per candidate placement, which made the quadruple walk the
     * AI's hot back.
     *
     * The gap law preserved verbatim from the old countGaps: a row earns
     * its gap penalty when it holds a block AND a null that follows a
     * block — and then EVERY null of the row counts, leading nulls
     * included. PerfContractTest cross-checks the pass against the
     * original four-scan formulas on seeded boards.
     */
    public function evaluate(Board $board): float
    {
        $rows = $board->rows();

        /** @var array<int, bool> $blockSeen */
        $blockSeen = array_fill(0, Board::COLS, false);
        $height = 0;
        $holes = 0;
        $gaps = 0;
        $lines = 0;

        for ($row = Board::HIDDEN_ROWS; $row < Board::ROWS; $row++) {
            $rowCells = $rows[$row];
            $rowNulls = 0;
            $blockInRow = false;
            $gapAfterBlock = false;

            for ($col = 0; $col < Board::COLS; $col++) {
                if ($rowCells[$col] !== null) {
                    if ($blockSeen[$col] === false) {
                        $blockSeen[$col] = true;
                        $height += Board::ROWS - $row;
                    }
                    $blockInRow = true;
                } else {
                    $rowNulls++;
                    // The shipped gap fact is a null that FOLLOWS a block
                    // in the row — the old countGaps order preserved.
                    if ($blockInRow === true) {
                        $gapAfterBlock = true;
                    }
                    if ($blockSeen[$col] === true) {
                        $holes++;
                    }
                }
            }

            if ($rowNulls === 0) {
                $lines++;
            } elseif ($blockInRow === true && $gapAfterBlock === true) {
                $gaps += $rowNulls;
            }
        }

        return self::WEIGHT_HEIGHT * $height
            + self::WEIGHT_HOLES * $holes
            + self::WEIGHT_GAPS * $gaps
            + self::WEIGHT_LINES * $lines;
    }
}
