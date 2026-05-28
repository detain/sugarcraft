<?php

declare(strict_types=1);

namespace SugarCraft\Buffer\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\DiffOp;
use SugarCraft\Buffer\Style;

/**
 * @covers \SugarCraft\Buffer\DiffOp
 */
final class DiffOpTest extends TestCase
{
    public function testReplaceConstant(): void
    {
        $this->assertSame('replace', DiffOp::TYPE_REPLACE);
    }

    public function testInsertConstant(): void
    {
        $this->assertSame('insert', DiffOp::TYPE_INSERT);
    }

    public function testDeleteConstant(): void
    {
        $this->assertSame('delete', DiffOp::TYPE_DELETE);
    }

    public function testNewReplace(): void
    {
        $cell = Cell::new('X', Style::bold());
        $op = new DiffOp(DiffOp::TYPE_REPLACE, 5, 10, $cell);

        $this->assertSame(DiffOp::TYPE_REPLACE, $op->type);
        $this->assertSame(5, $op->col);
        $this->assertSame(10, $op->row);
        $this->assertSame($cell, $op->cell);
    }

    public function testNewInsert(): void
    {
        $cell = Cell::new('Y');
        $op = new DiffOp(DiffOp::TYPE_INSERT, 0, 0, $cell);

        $this->assertSame(DiffOp::TYPE_INSERT, $op->type);
        $this->assertSame(0, $op->col);
        $this->assertSame(0, $op->row);
        $this->assertSame($cell, $op->cell);
    }

    public function testNewDelete(): void
    {
        $op = new DiffOp(DiffOp::TYPE_DELETE, 3, 7);

        $this->assertSame(DiffOp::TYPE_DELETE, $op->type);
        $this->assertSame(3, $op->col);
        $this->assertSame(7, $op->row);
        $this->assertNull($op->cell);
    }

    public function testNewWithNullCell(): void
    {
        $op = new DiffOp(DiffOp::TYPE_REPLACE, 1, 2, null);

        $this->assertNull($op->cell);
    }
}
