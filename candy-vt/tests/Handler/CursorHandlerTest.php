<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\CursorHandler;

final class CursorHandlerTest extends TestCase
{
    private Buffer $buf;
    private CursorHandler $handler;

    protected function setUp(): void
    {
        $this->buf = new Buffer(80, 24);
        $this->handler = new CursorHandler();
    }

    private function move(int $final, array $params, ?Cursor $start = null): Cursor
    {
        return $this->handler->apply($final, $params, $start ?? new Cursor(row: 5, col: 10), $this->buf);
    }

    public function testCuuMovesUp(): void
    {
        $c = $this->move(ord('A'), [3]);
        $this->assertSame(2, $c->row);
        $this->assertSame(10, $c->col);
    }

    public function testCuuDefaultParamMovesByOne(): void
    {
        $c = $this->move(ord('A'), []);
        $this->assertSame(4, $c->row);
    }

    public function testCuuClampsAtTop(): void
    {
        $c = $this->move(ord('A'), [99], new Cursor(row: 2, col: 0));
        $this->assertSame(0, $c->row);
    }

    public function testCudMovesDown(): void
    {
        $c = $this->move(ord('B'), [3]);
        $this->assertSame(8, $c->row);
    }

    public function testCudClampsAtBottom(): void
    {
        $c = $this->move(ord('B'), [999], new Cursor(row: 5, col: 0));
        $this->assertSame(23, $c->row);
    }

    public function testCufMovesRight(): void
    {
        $c = $this->move(ord('C'), [5]);
        $this->assertSame(15, $c->col);
    }

    public function testCubMovesLeft(): void
    {
        $c = $this->move(ord('D'), [4]);
        $this->assertSame(6, $c->col);
    }

    public function testCubClampsAtLeftEdge(): void
    {
        $c = $this->move(ord('D'), [99], new Cursor(row: 0, col: 3));
        $this->assertSame(0, $c->col);
    }

    public function testCnlMovesDownAndToColZero(): void
    {
        $c = $this->move(ord('E'), [2]);
        $this->assertSame(7, $c->row);
        $this->assertSame(0, $c->col);
    }

    public function testCplMovesUpAndToColZero(): void
    {
        $c = $this->move(ord('F'), [2]);
        $this->assertSame(3, $c->row);
        $this->assertSame(0, $c->col);
    }

    public function testChaSetsAbsoluteColumn(): void
    {
        $c = $this->move(ord('G'), [20]);
        $this->assertSame(19, $c->col); // 1-based input
        $this->assertSame(5, $c->row); // unchanged
    }

    public function testChaDefaultGoesToColumnOne(): void
    {
        $c = $this->move(ord('G'), []);
        $this->assertSame(0, $c->col);
    }

    public function testVpaSetsAbsoluteRow(): void
    {
        $c = $this->move(ord('d'), [10]);
        $this->assertSame(9, $c->row);
        $this->assertSame(10, $c->col); // unchanged
    }

    public function testCupSetsRowAndColumn(): void
    {
        $c = $this->move(ord('H'), [3, 5]);
        $this->assertSame(2, $c->row);
        $this->assertSame(4, $c->col);
    }

    public function testCupDefaultsToOrigin(): void
    {
        $c = $this->move(ord('H'), []);
        $this->assertSame(0, $c->row);
        $this->assertSame(0, $c->col);
    }

    public function testCupAcceptsHvpAlias(): void
    {
        $c = $this->move(ord('f'), [2, 8]);
        $this->assertSame(1, $c->row);
        $this->assertSame(7, $c->col);
    }

    public function testCupClampsOversizedCoordinates(): void
    {
        $c = $this->move(ord('H'), [999, 999]);
        $this->assertSame(23, $c->row);
        $this->assertSame(79, $c->col);
    }

    public function testSaveAndRestore(): void
    {
        $start = new Cursor(row: 5, col: 10);
        $saved = $this->move(ord('s'), [], $start);
        $moved = $saved->withRow(0)->withCol(0);
        $restored = $this->move(ord('u'), [], $moved);
        $this->assertSame(5, $restored->row);
        $this->assertSame(10, $restored->col);
    }
}
