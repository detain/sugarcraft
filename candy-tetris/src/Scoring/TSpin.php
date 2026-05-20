<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Scoring;

use SugarCraft\Tetris\Board;
use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Tetromino;

/**
 * T-Spin detector using the "3-corner rule".
 *
 * Mirrors charmbracelet/bubbletea Tetris T-Spin detection.
 *
 * A T-Spin is active when a T piece is locked AND:
 *   - The piece was rotated before locking (final piece position differs
 *     in rotation from $wasRotated), AND
 *   - 2 or more of the 4 diagonal "corner" cells around the T are
 *     already filled on the board (wall = filled).
 *
 * T-Spin Mini: exactly 2 front corners filled and piece entered from
 * that side (rotation 0 → top front; rotation 2 → bottom front).
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
     * @param int   $wasRotated  Rotation index the piece had before the
     *                           final rotation that led to locking
     */
    public static function detect(Board $board, Piece $piece, int $wasRotated): self
    {
        if ($piece->kind !== Tetromino::T) {
            return new self();
        }

        if ($piece->rotation === $wasRotated) {
            // No rotation occurred → not a spin
            return new self();
        }

        $filled = self::filledCorners($board, $piece);

        if (count($filled) < 2) {
            return new self(false, false);
        }

        // T-Spin Mini: exactly 2 corners filled AND they are the front pair
        $mini = count($filled) === 2
            && self::isFrontCornerPair($piece->rotation, $filled);

        return new self(true, $mini);
    }

    /**
     * @return list<string> names of corners that are filled (TL, TR, BL, BR)
     */
    private static function filledCorners(Board $board, Piece $piece): array
    {
        $cells = $piece->cells();
        $xs = array_column($cells, 0);
        $ys = array_column($cells, 1);

        $minX = min($xs);
        $maxX = max($xs);
        $minY = min($ys);
        $maxY = max($ys);

        // The four diagonal corner positions adjacent to the bounding box
        $cornerDefs = [
            'TL' => [$minX - 1, $minY - 1],
            'TR' => [$maxX + 1, $minY - 1],
            'BL' => [$minX - 1, $maxY + 1],
            'BR' => [$maxX + 1, $maxY + 1],
        ];

        $filled = [];
        foreach ($cornerDefs as $name => [$cx, $cy]) {
            if ($cx < 0 || $cx >= Board::COLS || $cy < 0 || $cy >= Board::ROWS) {
                // Out of bounds = wall = filled
                $filled[] = $name;
            } elseif ($board->isOccupied($cx, $cy)) {
                $filled[] = $name;
            }
        }

        return $filled;
    }

    /**
     * Whether $filled contains exactly the two "front" corners for
     * the given rotation (the entry side for a mini-spin).
     *
     * @param list<string> $filled
     */
    private static function isFrontCornerPair(int $rotation, array $filled): bool
    {
        $front = match ($rotation) {
            // Rotation 0: entered from top → front = top-left, top-right
            0 => ['TL', 'TR'],
            // Rotation 2: entered from bottom → front = bottom-left, bottom-right
            2 => ['BL', 'BR'],
            default => null,
        };

        if ($front === null) {
            return false;
        }

        if (count($filled) !== 2) {
            return false;
        }

        sort($front);
        $sorted = $filled;
        sort($sorted);

        return $front === $sorted;
    }
}
