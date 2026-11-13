<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Scoring;

use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Tetromino;

/**
 * T-Spin detector using the guideline rotation rule + corner rule.
 *
 * Mirrors Broderick-Westrope/tetrigo T-Spin scoring intent, tightened to
 * the SRS guideline: a T-Spin counts iff the piece's LAST SUCCESSFUL
 * INPUT before lock was a rotation.
 *
 * The four corner cells are the DIAGONALS OF THE T'S CENTRE — the centre
 * cell sits at box position (1,1) in every rotation state, so the corners
 * are (centre ±1, ±1) and travel with the piece, never with the occupied
 * bounding box (which changes shape per rotation). Off-board cells count
 * as filled (walls/floor), as the guideline requires.
 *
 * A T-Spin is active when a T piece is locked AND:
 *   - $lastActionWasRotation is true (a slide, soft drop, hard drop or
 *     hold into the slot is NOT a spin; gravity steps never clear the
 *     flag), AND
 *   - at least 2 of the 4 centre diagonals are filled and at least one
 *     of them is a FRONT corner — the nub-side pair for the current
 *     rotation (two back corners never engaged the slot: not a spin).
 *
 * The full/mini split follows the guideline NUB-PAIR rule
 * (candy-tetris-022): a full T-Spin needs at least 3 filled corners AND
 * BOTH front corners. The classic kick-out — 3 corners where only one
 * front is filled (two backs + one nub-side) — is the shape competitive
 * implementations score as T-Spin Mini, and it must not farm the 400
 * award or arm back-to-back. `Game` still promotes a Mini that clears
 * two or more lines to full difficulty.
 */
final class TSpin
{
    public const T_SPIN_MINI_POINTS = 100;
    public const T_SPIN_POINTS      = 400;

    private function __construct(
        public readonly bool $active = false,
        public readonly bool $mini   = false,
    ) {}

    /**
     * Detect T-Spin state after a T piece has just locked.
     *
     * @param Board $board    Board *before* the piece was placed (the board
     *                        state the T piece fell into, so corners reflect
     *                        already-locked cells)
     * @param Piece $piece    The locked piece position/rotation
     * @param bool  $lastActionWasRotation  Whether the piece's last
     *                           successful player input before locking was
     *                           a rotation (the SRS guideline T-Spin gate)
     */
    public static function detect(Board $board, Piece $piece, bool $lastActionWasRotation): self
    {
        if ($piece->kind !== Tetromino::T) {
            return new self();
        }

        if ($lastActionWasRotation === false) {
            // Last input was a move/drop (or none) → not a spin
            return new self();
        }

        $filled = self::filledCorners($board, $piece);
        $frontCount = count(array_intersect(self::frontPair($piece->rotation), $filled));

        // Fewer than two corners, or no nub-side touch at all, never
        // engaged the slot — not a spin of any kind (TET-GX-03).
        if (count($filled) < 2 || $frontCount === 0) {
            return new self();
        }

        // Guideline nub-pair rule: FULL requires 3+ corners with BOTH
        // front corners filled; one front (the kick-out pocket) is a Mini.
        if (count($filled) >= 3 && $frontCount === 2) {
            return new self(true, false);
        }

        return new self(true, true);
    }

    /**
     * The four guideline corner cells: the diagonals around the T's centre.
     * The centre of every T rotation state is box offset (1, 1), so the
     * corners are absolute (piece.x + 1 ± 1, piece.y + 1 ± 1).
     *
     * @return list<string> names of corners that are filled (TL, TR, BL, BR)
     */
    private static function filledCorners(Board $board, Piece $piece): array
    {
        $centreX = $piece->x + 1;
        $centreY = $piece->y + 1;

        $cornerDefs = [
            'TL' => [$centreX - 1, $centreY - 1],
            'TR' => [$centreX + 1, $centreY - 1],
            'BL' => [$centreX - 1, $centreY + 1],
            'BR' => [$centreX + 1, $centreY + 1],
        ];

        $filled = [];
        foreach ($cornerDefs as $name => [$cx, $cy]) {
            // Board::isOccupied() already answers true for every
            // off-board cell (walls + floor count as filled per guideline).
            if ($board->isOccupied($cx, $cy) === true) {
                $filled[] = $name;
            }
        }

        return $filled;
    }

    /**
     * The FRONT (nub-side) corner pair for a rotation state: the two
     * centre diagonals the T's nub points between.
     *
     * @return list<string> the two front corner names
     */
    private static function frontPair(int $rotation): array
    {
        return match ($rotation) {
            0 => ['TL', 'TR'], // nub up  → front is the top pair
            1 => ['TR', 'BR'], // nub right → front is the right pair
            2 => ['BL', 'BR'], // nub down → front is the bottom pair
            default => ['TL', 'BL'], // nub left → front is the left pair
        };
    }
}
