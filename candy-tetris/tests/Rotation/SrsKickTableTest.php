<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests\Rotation;

use SugarCraft\Tetris\Rotation\SrsKickTable;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class SrsKickTableTest extends TestCase
{
    /**
     * @dataProvider jlstzKicksProvider
     */
    public function testJlstzKicks(string $transition, int $from, int $to, array $expected): void
    {
        $kicks = SrsKickTable::kicks(Tetromino::T, $from, $to);
        $this->assertSame($expected, $kicks, "T-piece $transition kick offsets mismatch");
    }

    /**
     * @dataProvider iPieceKicksProvider
     */
    public function testIPieceKicks(string $transition, int $from, int $to, array $expected): void
    {
        $kicks = SrsKickTable::kicks(Tetromino::I, $from, $to);
        $this->assertSame($expected, $kicks, "I-piece $transition kick offsets mismatch");
    }

    public function testOPieceUsesJlstzTable(): void
    {
        $oKicks = SrsKickTable::kicks(Tetromino::O, 0, 1);
        $tKicks = SrsKickTable::kicks(Tetromino::T, 0, 1);
        $this->assertSame($tKicks, $oKicks, 'O uses JLSTZ table');
    }

    /**
     * @dataProvider jlstzCcwKicksProvider
     */
    public function testJlstzCcwKicks(string $transition, int $from, int $to, array $expected): void
    {
        $kicks = SrsKickTable::kicks(Tetromino::T, $from, $to);
        $this->assertSame($expected, $kicks, "T-piece $transition ccw kick offsets mismatch");
    }

    /**
     * @dataProvider iPieceCcwKicksProvider
     */
    public function testIPieceCcwKicks(string $transition, int $from, int $to, array $expected): void
    {
        $kicks = SrsKickTable::kicks(Tetromino::I, $from, $to);
        $this->assertSame($expected, $kicks, "I-piece $transition ccw kick offsets mismatch");
    }

    public function testUnknownTransitionFallsBackToNaive(): void
    {
        // Direct 180 transitions (0↔2, R↔L) have no SRS spec entry
        $kicks = SrsKickTable::kicks(Tetromino::T, 0, 2);
        $this->assertSame([[0, 0]], $kicks, 'Unknown 180 transition must return naive-only kick');

        $kicks180 = SrsKickTable::kicks(Tetromino::I, 0, 2);
        $this->assertSame([[0, 0]], $kicks180, 'Unknown 180 transition for I-piece must return naive-only kick');
    }

    public static function jlstzKicksProvider(): array
    {
        return [
            '0→R' => ['0→R', 0, 1, [[0, 0], [-1, 0], [-1, +1], [0, -2], [-1, -2]]],
            'R→2' => ['R→2', 1, 2, [[0, 0], [+1, 0], [+1, -1], [0, +2], [+1, +2]]],
            '2→L' => ['2→L', 2, 3, [[0, 0], [+1, 0], [+1, +1], [0, -2], [+1, -2]]],
            'L→0' => ['L→0', 3, 0, [[0, 0], [-1, 0], [-1, -1], [0, +2], [-1, +2]]],
        ];
    }

    public static function jlstzCcwKicksProvider(): array
    {
        // Counter-clockwise: negation of reverse cw pair
        return [
            'R→0' => ['R→0', 1, 0, [[0, 0], [+1, 0], [+1, -1], [0, +2], [+1, +2]]],
            '0→L' => ['0→L', 0, 3, [[0, 0], [+1, 0], [+1, +1], [0, -2], [+1, -2]]],
            'L→2' => ['L→2', 3, 2, [[0, 0], [-1, 0], [-1, -1], [0, +2], [-1, +2]]],
            '2→R' => ['2→R', 2, 1, [[0, 0], [-1, 0], [-1, +1], [0, -2], [-1, -2]]],
        ];
    }

    public static function iPieceKicksProvider(): array
    {
        return [
            '0→R' => ['0→R', 0, 1, [[0, 0], [-2, 0], [+1, 0], [-2, -1], [+1, +2]]],
            'R→2' => ['R→2', 1, 2, [[0, 0], [-1, 0], [+2, 0], [-1, +2], [+2, -1]]],
            '2→L' => ['2→L', 2, 3, [[0, 0], [+2, 0], [-1, 0], [+2, +1], [-1, -2]]],
            'L→0' => ['L→0', 3, 0, [[0, 0], [+1, 0], [-2, 0], [+1, -2], [-2, +1]]],
        ];
    }

    public static function iPieceCcwKicksProvider(): array
    {
        // Counter-clockwise: negation of reverse cw pair
        return [
            'R→0' => ['R→0', 1, 0, [[0, 0], [+2, 0], [-1, 0], [+2, +1], [-1, -2]]],
            '0→L' => ['0→L', 0, 3, [[0, 0], [-1, 0], [+2, 0], [-1, -2], [+2, +1]]],
            'L→2' => ['L→2', 3, 2, [[0, 0], [+1, 0], [-2, 0], [+1, +2], [-2, -1]]],
            '2→R' => ['2→R', 2, 1, [[0, 0], [-2, 0], [+1, 0], [-2, +1], [+1, +2]]],
        ];
    }
}
