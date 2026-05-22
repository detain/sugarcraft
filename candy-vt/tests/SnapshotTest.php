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
}
