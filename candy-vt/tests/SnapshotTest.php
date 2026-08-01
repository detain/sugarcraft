<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;

final class SnapshotTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $grid = new CellGrid(80, 24);
        $cursor = new Cursor(row: 5, col: 10);
        $time = 1.5;

        $snap = new Snapshot($grid, $cursor, $time);

        $this->assertSame($grid, $snap->grid);
        $this->assertSame($cursor, $snap->cursor);
        $this->assertSame(1.5, $snap->time);
    }

    public function testGridAndCursorAreAccessible(): void
    {
        $grid = new CellGrid(80, 24);
        $cursor = new Cursor(row: 0, col: 0);

        $snap = new Snapshot($grid, $cursor, 0.0);

        $this->assertSame($grid, $snap->grid);
        $this->assertSame($cursor, $snap->cursor);
    }

    public function testTimeCanBeZero(): void
    {
        $snap = new Snapshot(new CellGrid(80, 24), new Cursor(), 0.0);

        $this->assertSame(0.0, $snap->time);
    }

    public function testTimeCanBeLarge(): void
    {
        $snap = new Snapshot(new CellGrid(80, 24), new Cursor(), 3600.123);

        $this->assertSame(3600.123, $snap->time);
    }

    public function testEqualsReturnsTrueForIdenticalSnapshots(): void
    {
        $grid1 = new CellGrid(80, 24);
        $grid1->get(0, 0); // populate
        $grid2 = new CellGrid(80, 24);
        $cursor1 = new Cursor(row: 5, col: 10);
        $cursor2 = new Cursor(row: 5, col: 10);

        $snap1 = new Snapshot($grid1, $cursor1, 1.0);
        $snap2 = new Snapshot($grid2, $cursor2, 1.0);

        $this->assertTrue($snap1->equals($snap2));
    }

    public function testEqualsReturnsFalseForDifferentGrids(): void
    {
        $grid1 = new CellGrid(80, 24);
        $grid2 = new CellGrid(80, 24);
        $grid1->set(0, 0, new Cell('X'));

        $snap1 = new Snapshot($grid1, new Cursor(), 1.0);
        $snap2 = new Snapshot($grid2, new Cursor(), 1.0);

        $this->assertFalse($snap1->equals($snap2));
    }

    public function testEqualsReturnsFalseForDifferentCursors(): void
    {
        $grid = new CellGrid(80, 24);
        $cursor1 = new Cursor(row: 5, col: 10);
        $cursor2 = new Cursor(row: 5, col: 20);

        $snap1 = new Snapshot($grid, $cursor1, 1.0);
        $snap2 = new Snapshot($grid, $cursor2, 1.0);

        $this->assertFalse($snap1->equals($snap2));
    }

    public function testEqualsWithTimeReturnsTrueOnlyWhenTimeMatches(): void
    {
        $grid = new CellGrid(80, 24);
        $cursor = new Cursor(row: 5, col: 10);

        $snap1 = new Snapshot($grid, $cursor, 1.5);
        $snap2 = new Snapshot($grid, $cursor, 1.5);
        $snap3 = new Snapshot($grid, $cursor, 2.5);

        $this->assertTrue($snap1->equalsWithTime($snap2));
        $this->assertFalse($snap1->equalsWithTime($snap3));
    }

    public function testEqualsWithTimeReturnsFalseForDifferentGrids(): void
    {
        $grid1 = new CellGrid(80, 24);
        $grid2 = new CellGrid(80, 24);
        $grid1->set(0, 0, new Cell('X'));

        $cursor = new Cursor();
        $time = 1.0;

        $snap1 = new Snapshot($grid1, $cursor, $time);
        $snap2 = new Snapshot($grid2, $cursor, $time);

        $this->assertFalse($snap1->equalsWithTime($snap2));
    }

    public function testEqualsWithTimeReturnsFalseForDifferentCursors(): void
    {
        $grid = new CellGrid(80, 24);
        $cursor1 = new Cursor(row: 5, col: 10);
        $cursor2 = new Cursor(row: 10, col: 5);

        $snap1 = new Snapshot($grid, $cursor1, 1.0);
        $snap2 = new Snapshot($grid, $cursor2, 1.0);

        $this->assertFalse($snap1->equalsWithTime($snap2));
    }

    public function testStaticOfCreatesSnapshotFromTerminal(): void
    {
        $terminal = \SugarCraft\Vt\Terminal::new(80, 24);
        $terminal->feed("Hello");

        $snap = \SugarCraft\Vt\Snapshot::of($terminal, 3.5);

        $this->assertSame('H', $snap->grid->get(0, 0)->char);
        $this->assertSame(0, $snap->cursor->row);
        $this->assertSame(5, $snap->cursor->col);
        $this->assertSame(3.5, $snap->time);
    }

    public function testStaticOfDefaultTimeIsZero(): void
    {
        $terminal = \SugarCraft\Vt\Terminal::new(80, 24);
        $terminal->feed("X");

        $snap = \SugarCraft\Vt\Snapshot::of($terminal);

        $this->assertSame(0.0, $snap->time);
    }
}
