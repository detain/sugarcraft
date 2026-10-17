<?php

declare(strict_types=1);

namespace SugarCraft\Tetris;

/**
 * The seven Tetris pieces (a.k.a. tetrominoes), with their
 * Standard-Rotation-System rotation states pre-computed.
 *
 * Each piece's `cells($rotation)` returns a list of `[x, y]`
 * cell offsets within a 4×4 bounding box. (x grows right, y grows
 * *down* — terminal-native coordinates, not Cartesian.) The Piece
 * class adds the global board offset on top.
 *
 * Colours are returned as ANSI 256-color codes for consistency
 * across terminals; a SugarStyle `Style` background does the
 * actual painting.
 */
enum Tetromino: string
{
    case I = 'I';
    case O = 'O';
    case T = 'T';
    case S = 'S';
    case Z = 'Z';
    case J = 'J';
    case L = 'L';

    /**
     * @return array<int,list<array{int,int}>> map rotation→cells
     */
    private function rotations(): array
    {
        return match ($this) {
            self::I => [
                [[0,1],[1,1],[2,1],[3,1]],
                [[2,0],[2,1],[2,2],[2,3]],
                [[0,2],[1,2],[2,2],[3,2]],
                [[1,0],[1,1],[1,2],[1,3]],
            ],
            self::O => [
                [[1,0],[2,0],[1,1],[2,1]],
                [[1,0],[2,0],[1,1],[2,1]],
                [[1,0],[2,0],[1,1],[2,1]],
                [[1,0],[2,0],[1,1],[2,1]],
            ],
            self::T => [
                [[1,0],[0,1],[1,1],[2,1]],
                [[1,0],[1,1],[2,1],[1,2]],
                [[0,1],[1,1],[2,1],[1,2]],
                [[1,0],[0,1],[1,1],[1,2]],
            ],
            self::S => [
                [[1,0],[2,0],[0,1],[1,1]],
                [[1,0],[1,1],[2,1],[2,2]],
                [[1,1],[2,1],[0,2],[1,2]],
                [[0,0],[0,1],[1,1],[1,2]],
            ],
            self::Z => [
                [[0,0],[1,0],[1,1],[2,1]],
                [[2,0],[1,1],[2,1],[1,2]],
                [[0,1],[1,1],[1,2],[2,2]],
                [[1,0],[0,1],[1,1],[0,2]],
            ],
            self::J => [
                [[0,0],[0,1],[1,1],[2,1]],
                [[1,0],[2,0],[1,1],[1,2]],
                [[0,1],[1,1],[2,1],[2,2]],
                [[1,0],[1,1],[0,2],[1,2]],
            ],
            self::L => [
                [[2,0],[0,1],[1,1],[2,1]],
                [[1,0],[1,1],[1,2],[2,2]],
                [[0,1],[1,1],[2,1],[0,2]],
                [[0,0],[1,0],[1,1],[1,2]],
            ],
        };
    }

    /**
     * @return list<array{int,int}> cells in a 4×4 box at the given rotation
     */
    public function cells(int $rotation): array
    {
        $r = (($rotation % 4) + 4) % 4;
        return $this->rotations()[$r];
    }

    /** ANSI 256-color code for this piece. */
    public function color(): int
    {
        return match ($this) {
            self::I => 51,    // cyan
            self::O => 226,   // yellow
            self::T => 129,   // purple
            self::S => 46,    // green
            self::Z => 196,   // red
            self::J => 21,    // blue
            self::L => 208,   // orange
        };
    }
}
