<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Rotation;

use SugarCraft\Tetris\Tetromino;

/**
 * Official Tetris Association Super Rotation System wall-kick offsets.
 *
 * Mirrors charmbracelet/bubbletea Tetris implementation — see
 * https://tetris.fandom.com/wiki/SRS
 *
 * Two tables exist:
 *   - J/L/S/T/Z (the "basic" table, shared by all non-I/O pieces)
 *   - I         (the I-piece has its own, larger offsets)
 *
 * Each entry maps a rotation transition to a list of (dx, dy) deltas to
 * try in order. The piece is first rotated, then each kick is applied;
 * the first kick that results in a valid board position is used.
 */
final class SrsKickTable
{
    /** @var array<string,list<array{int,int}>> J/L/S/T/Z kicks */
    private const array JLSTZ_KICKS = [
        '0→R' => [[0, 0], [-1, 0], [-1, +1], [0, -2], [-1, -2]],
        'R→2' => [[0, 0], [+1, 0], [+1, -1], [0, +2], [+1, +2]],
        '2→L' => [[0, 0], [+1, 0], [+1, +1], [0, -2], [+1, -2]],
        'L→0' => [[0, 0], [-1, 0], [-1, -1], [0, +2], [-1, +2]],
    ];

    /** @var array<string,list<array{int,int}>> I-piece kicks */
    private const array I_KICKS = [
        '0→R' => [[0, 0], [-2, 0], [+1, 0], [-2, -1], [+1, +2]],
        'R→2' => [[0, 0], [-1, 0], [+2, 0], [-1, +2], [+2, -1]],
        '2→L' => [[0, 0], [+2, 0], [-1, 0], [+2, +1], [-1, -2]],
        'L→0' => [[0, 0], [+1, 0], [-2, 0], [+1, -2], [-2, +1]],
    ];

    /**
     * Return the kick offsets for a given rotation state transition.
     *
     * @return list<array{int,int}>
     */
    public static function kicks(Tetromino $piece, int $from, int $to): array
    {
        $key = self::transitionKey($from, $to);

        return match ($piece) {
            Tetromino::I => self::I_KICKS[$key],
            default => self::JLSTZ_KICKS[$key],
        };
    }

    /**
     * Return all kick offsets for all four transitions as an associative array.
     *
     * @return array<string,list<array{int,int}>>
     */
    public static function allKicks(Tetromino $piece): array
    {
        return match ($piece) {
            Tetromino::I => self::I_KICKS,
            default => self::JLSTZ_KICKS,
        };
    }

    private static function transitionKey(int $from, int $to): string
    {
        $labels = ['0', 'R', '2', 'L'];

        return $labels[$from] . '→' . $labels[$to];
    }
}
