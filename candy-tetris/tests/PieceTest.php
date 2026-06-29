<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Tests;

use SugarCraft\Tetris\Piece;
use SugarCraft\Tetris\Tetromino;
use PHPUnit\Framework\TestCase;

final class PieceTest extends TestCase
{
    public function testCellsAreOffsetByPiecePosition(): void
    {
        $p = new Piece(Tetromino::T, 0, x: 5, y: 3);
        $cells = $p->cells();
        $this->assertContains([6, 3], $cells); // [1,0] + (5,3) = (6,3)
        $this->assertContains([5, 4], $cells); // [0,1] + (5,3)
    }

    public function testWithersReturnNewInstance(): void
    {
        $a = new Piece(Tetromino::T, x: 0, y: 0);
        $b = $a->withX(7);
        $c = $b->withY(2);
        $d = $c->rotated();
        $this->assertSame(0, $a->x, 'original Piece must remain unchanged');
        $this->assertSame(7, $b->x);
        $this->assertSame(2, $c->y);
        $this->assertSame(1, $d->rotation);
        $this->assertSame(7, $d->x);
    }

    public function testRotationWrapsModulo4(): void
    {
        $p = new Piece(Tetromino::T);
        $this->assertSame(0, $p->rotation);
        $this->assertSame(1, $p->rotated(1)->rotation);
        $this->assertSame(0, $p->rotated(4)->rotation);
        $this->assertSame(3, $p->rotated(-1)->rotation);
        $this->assertSame(0, $p->rotated(-4)->rotation);
    }

    public function testMovedAddsDelta(): void
    {
        $p = new Piece(Tetromino::T, 0, 5, 3);
        $q = $p->moved(-2, 4);
        $this->assertSame(3, $q->x);
        $this->assertSame(7, $q->y);
    }

    /**
     * @dataProvider tetrominoKindsProvider
     */
    public function testRotationsWithKicksClockwise(Tetromino $kind): void
    {
        $p = new Piece($kind, 0, 3, 3);
        $candidates = $p->rotationsWithKicks(1);

        // First element is the naive rotation (same x/y, rotation == 1)
        $this->assertSame($p->x, $candidates[0]->x);
        $this->assertSame($p->y, $candidates[0]->y);
        $this->assertSame(1, $candidates[0]->rotation);
        $this->assertSame($kind, $candidates[0]->kind);

        // JLSTZ/O have 5 kick offsets → 6 candidates; I has 5 offsets → 6 candidates
        $expectedCount = $kind === Tetromino::I ? 6 : 6;
        $this->assertCount($expectedCount, $candidates);
    }

    /**
     * @dataProvider tetrominoKindsProvider
     */
    public function testRotationsWithKicksCounterClockwise(Tetromino $kind): void
    {
        $p = new Piece($kind, 1, 3, 3);
        $candidates = $p->rotationsWithKicks(-1);

        // First element is the naive rotation (same x/y, rotation == 0)
        $this->assertSame($p->x, $candidates[0]->x);
        $this->assertSame($p->y, $candidates[0]->y);
        $this->assertSame(0, $candidates[0]->rotation);
        $this->assertSame($kind, $candidates[0]->kind);

        $expectedCount = $kind === Tetromino::I ? 6 : 6;
        $this->assertCount($expectedCount, $candidates);
    }

    public function testCounterClockwiseDoesNotThrowForAllPieces(): void
    {
        $kinds = [Tetromino::I, Tetromino::O, Tetromino::T, Tetromino::S, Tetromino::Z, Tetromino::J, Tetromino::L];
        foreach ($kinds as $kind) {
            for ($fromRot = 0; $fromRot < 4; $fromRot++) {
                $p = new Piece($kind, $fromRot, 3, 3);
                $candidates = $p->rotationsWithKicks(-1);
                $this->assertIsArray($candidates);
                $this->assertNotEmpty($candidates);
            }
        }
    }

    public function testRotationsWithKicksDoesNotThrow(): void
    {
        $p = new Piece(Tetromino::T, 0, 3, 3);
        $this->assertNotEmpty($p->rotationsWithKicks(1));
        $this->assertNotEmpty($p->rotationsWithKicks(-1));
    }

    public static function tetrominoKindsProvider(): array
    {
        return [
            'I' => [Tetromino::I],
            'O' => [Tetromino::O],
            'T' => [Tetromino::T],
            'S' => [Tetromino::S],
            'Z' => [Tetromino::Z],
            'J' => [Tetromino::J],
            'L' => [Tetromino::L],
        ];
    }
}
