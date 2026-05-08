<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cursor\Cursor;

final class CursorTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $c = new Cursor();
        $this->assertSame(0, $c->row);
        $this->assertSame(0, $c->col);
        $this->assertTrue($c->visible);
        $this->assertSame(0, $c->shape);
        $this->assertNull($c->savedRow);
        $this->assertNull($c->savedCol);
    }

    public function testWithRow(): void
    {
        $c = (new Cursor())->withRow(5);
        $this->assertSame(5, $c->row);
        $this->assertSame(0, $c->col);
    }

    public function testWithCol(): void
    {
        $c = (new Cursor())->withCol(12);
        $this->assertSame(12, $c->col);
        $this->assertSame(0, $c->row);
    }

    public function testWithVisible(): void
    {
        $c = (new Cursor())->withVisible(false);
        $this->assertFalse($c->visible);
    }

    public function testWithShape(): void
    {
        $c = (new Cursor())->withShape(2);
        $this->assertSame(2, $c->shape);
    }

    public function testSaveAndRestore(): void
    {
        $c = new Cursor(row: 3, col: 7);
        $saved = $c->save();
        $this->assertSame(3, $saved->savedRow);
        $this->assertSame(7, $saved->savedCol);
        $this->assertSame(3, $saved->row);
        $this->assertSame(7, $saved->col);

        $moved = $saved->withRow(10)->withCol(20);
        $restored = $moved->restore();
        $this->assertSame(3, $restored->row);
        $this->assertSame(7, $restored->col);
    }

    public function testEquals(): void
    {
        $a = new Cursor(row: 1, col: 2, visible: true, shape: 0);
        $b = new Cursor(row: 1, col: 2, visible: true, shape: 0);
        $c = new Cursor(row: 1, col: 3, visible: true, shape: 0);
        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
