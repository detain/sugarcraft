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

    public static function jlstzKicksProvider(): array
    {
        return [
            '0→R' => ['0→R', 0, 1, [[0, 0], [-1, 0], [-1, +1], [0, -2], [-1, -2]]],
            'R→2' => ['R→2', 1, 2, [[0, 0], [+1, 0], [+1, -1], [0, +2], [+1, +2]]],
            '2→L' => ['2→L', 2, 3, [[0, 0], [+1, 0], [+1, +1], [0, -2], [+1, -2]]],
            'L→0' => ['L→0', 3, 0, [[0, 0], [-1, 0], [-1, -1], [0, +2], [-1, +2]]],
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
}
