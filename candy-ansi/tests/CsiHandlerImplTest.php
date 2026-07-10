<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Tests;

use SugarCraft\Ansi\Parser\CsiHandlerImpl;
use PHPUnit\Framework\TestCase;

final class CsiHandlerImplTest extends TestCase
{
    private CsiHandlerImpl $handler;

    protected function setUp(): void
    {
        $this->handler = new CsiHandlerImpl();
    }

    public function testPrintableIsNoOp(): void
    {
        // Should not throw
        $this->handler->printable('A');
        $this->assertTrue(true);
    }

    public function testCuuIsNoOp(): void
    {
        $this->handler->cuu(3);
        $this->assertTrue(true);
    }

    public function testCudIsNoOp(): void
    {
        $this->handler->cud(5);
        $this->assertTrue(true);
    }

    public function testCufIsNoOp(): void
    {
        $this->handler->cuf(2);
        $this->assertTrue(true);
    }

    public function testCubIsNoOp(): void
    {
        $this->handler->cub(1);
        $this->assertTrue(true);
    }

    public function testCupIsNoOp(): void
    {
        $this->handler->cup(10, 20);
        $this->assertTrue(true);
    }

    public function testSgrIsNoOp(): void
    {
        $this->handler->sgr([1, 31, 40]);
        $this->assertTrue(true);
    }

    public function testEdIsNoOp(): void
    {
        $this->handler->ed(2);
        $this->assertTrue(true);
    }

    public function testElIsNoOp(): void
    {
        $this->handler->el(0);
        $this->assertTrue(true);
    }

    public function testDecsetIsNoOp(): void
    {
        $this->handler->decset(25, 0);
        $this->assertTrue(true);
    }

    public function testDecrstIsNoOp(): void
    {
        $this->handler->decrst(25, 0);
        $this->assertTrue(true);
    }

    public function testDecstbmIsNoOp(): void
    {
        $this->handler->decstbm(1, 20);
        $this->assertTrue(true);
    }

    public function testTbcIsNoOp(): void
    {
        $this->handler->tbc(0);
        $this->assertTrue(true);
    }

    public function testCbtIsNoOp(): void
    {
        $this->handler->cbt(3);
        $this->assertTrue(true);
    }

    public function testChtIsNoOp(): void
    {
        $this->handler->cht(2);
        $this->assertTrue(true);
    }

    public function testHvpIsNoOp(): void
    {
        $this->handler->hvp(3, 4);
        $this->assertTrue(true);
    }

    public function testCrIsNoOp(): void
    {
        $this->handler->cr();
        $this->assertTrue(true);
    }

    public function testLfIsNoOp(): void
    {
        $this->handler->lf();
        $this->assertTrue(true);
    }

    public function testSuIsNoOp(): void
    {
        $this->handler->su(2);
        $this->assertTrue(true);
    }

    public function testSdIsNoOp(): void
    {
        $this->handler->sd(2);
        $this->assertTrue(true);
    }

    public function testIlIsNoOp(): void
    {
        $this->handler->il(2);
        $this->assertTrue(true);
    }

    public function testDlIsNoOp(): void
    {
        $this->handler->dl(2);
        $this->assertTrue(true);
    }

    public function testIchIsNoOp(): void
    {
        $this->handler->ich(2);
        $this->assertTrue(true);
    }

    public function testDchIsNoOp(): void
    {
        $this->handler->dch(2);
        $this->assertTrue(true);
    }

    public function testRepIsNoOp(): void
    {
        $this->handler->rep(2);
        $this->assertTrue(true);
    }

    public function testScoscIsNoOp(): void
    {
        $this->handler->scosc();
        $this->assertTrue(true);
    }

    public function testScorcIsNoOp(): void
    {
        $this->handler->scorc();
        $this->assertTrue(true);
    }

    public function testGridRowsReturnsZero(): void
    {
        $this->assertSame(0, $this->handler->gridRows());
    }

    public function testGridColsReturnsZero(): void
    {
        $this->assertSame(0, $this->handler->gridCols());
    }

    public function testAllPublicMethodsReturnWithoutError(): void
    {
        // Exercise every public method to verify no exceptions
        $this->handler->printable('X');
        $this->handler->cuu();
        $this->handler->cud();
        $this->handler->cuf();
        $this->handler->cub();
        $this->handler->cup(1, 1);
        $this->handler->hvp(1, 1);
        $this->handler->sgr([]);
        $this->handler->ed();
        $this->handler->el();
        $this->handler->decset(0);
        $this->handler->decrst(0);
        $this->handler->decstbm(1, 0);
        $this->handler->tbc();
        $this->handler->cbt();
        $this->handler->cht();
        $this->handler->cr();
        $this->handler->lf();
        $this->handler->su();
        $this->handler->sd();
        $this->handler->il();
        $this->handler->dl();
        $this->handler->ich();
        $this->handler->dch();
        $this->handler->rep();
        $this->handler->scosc();
        $this->handler->scorc();

        // Final check
        $this->assertSame(0, $this->handler->gridRows());
        $this->assertSame(0, $this->handler->gridCols());
    }
}
