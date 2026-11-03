<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cursor;
use SugarCraft\Vterm\Snapshot;

final class SnapshotTest extends TestCase
{
    public function testConstructorStoresValues(): void
    {
        $grid = new Buffer(80, 24);
        $cursor = new Cursor(row: 5, col: 10);
        $time = 1.5;

        $snap = new Snapshot($grid, $cursor, $time);

        $this->assertSame($grid, $snap->grid);
        $this->assertSame($cursor, $snap->cursor);
        $this->assertSame(1.5, $snap->time);
    }

    public function testGridAndCursorAreAccessible(): void
    {
        $grid = new Buffer(80, 24);
        $cursor = new Cursor(row: 0, col: 0);

        $snap = new Snapshot($grid, $cursor, 0.0);

        $this->assertSame($grid, $snap->grid);
        $this->assertSame($cursor, $snap->cursor);
    }

    public function testTimeCanBeZero(): void
    {
        $snap = new Snapshot(new Buffer(80, 24), new Cursor(), 0.0);

        $this->assertSame(0.0, $snap->time);
    }

    public function testTimeCanBeLarge(): void
    {
        $snap = new Snapshot(new Buffer(80, 24), new Cursor(), 3600.123);

        $this->assertSame(3600.123, $snap->time);
    }

    public function testEqualsReturnsTrueForIdenticalSnapshots(): void
    {
        $grid1 = new Buffer(80, 24);
        $grid1->cell(0, 0); // populate
        $grid2 = new Buffer(80, 24);
        $cursor1 = new Cursor(row: 5, col: 10);
        $cursor2 = new Cursor(row: 5, col: 10);

        $snap1 = new Snapshot($grid1, $cursor1, 1.0);
        $snap2 = new Snapshot($grid2, $cursor2, 1.0);

        $this->assertTrue($snap1->equals($snap2));
    }

    public function testEqualsReturnsFalseForDifferentGrids(): void
    {
        $grid1 = new Buffer(80, 24);
        $grid2 = new Buffer(80, 24);
        $grid1->put(0, 0, new Cell('X'));

        $snap1 = new Snapshot($grid1, new Cursor(), 1.0);
        $snap2 = new Snapshot($grid2, new Cursor(), 1.0);

        $this->assertFalse($snap1->equals($snap2));
    }

    public function testEqualsReturnsFalseForDifferentCursors(): void
    {
        $grid = new Buffer(80, 24);
        $cursor1 = new Cursor(row: 5, col: 10);
        $cursor2 = new Cursor(row: 5, col: 20);

        $snap1 = new Snapshot($grid, $cursor1, 1.0);
        $snap2 = new Snapshot($grid, $cursor2, 1.0);

        $this->assertFalse($snap1->equals($snap2));
    }

    public function testEqualsWithTimeReturnsTrueOnlyWhenTimeMatches(): void
    {
        $grid = new Buffer(80, 24);
        $cursor = new Cursor(row: 5, col: 10);

        $snap1 = new Snapshot($grid, $cursor, 1.5);
        $snap2 = new Snapshot($grid, $cursor, 1.5);
        $snap3 = new Snapshot($grid, $cursor, 2.5);

        $this->assertTrue($snap1->equalsWithTime($snap2));
        $this->assertFalse($snap1->equalsWithTime($snap3));
    }

    public function testEqualsWithTimeReturnsFalseForDifferentGrids(): void
    {
        $grid1 = new Buffer(80, 24);
        $grid2 = new Buffer(80, 24);
        $grid1->put(0, 0, new Cell('X'));

        $cursor = new Cursor();
        $time = 1.0;

        $snap1 = new Snapshot($grid1, $cursor, $time);
        $snap2 = new Snapshot($grid2, $cursor, $time);

        $this->assertFalse($snap1->equalsWithTime($snap2));
    }

    public function testEqualsWithTimeReturnsFalseForDifferentCursors(): void
    {
        $grid = new Buffer(80, 24);
        $cursor1 = new Cursor(row: 5, col: 10);
        $cursor2 = new Cursor(row: 10, col: 5);

        $snap1 = new Snapshot($grid, $cursor1, 1.0);
        $snap2 = new Snapshot($grid, $cursor2, 1.0);

        $this->assertFalse($snap1->equalsWithTime($snap2));
    }

    public function testStaticOfCreatesSnapshotFromTerminal(): void
    {
        $terminal = \SugarCraft\Vterm\Terminal::new(80, 24);
        $terminal->feed("Hello");

        $snap = \SugarCraft\Vterm\Snapshot::of($terminal, 3.5);

        $this->assertSame('H', $snap->grid->cell(0, 0)->char);
        $this->assertSame(0, $snap->cursor->row);
        $this->assertSame(5, $snap->cursor->col);
        $this->assertSame(3.5, $snap->time);
    }

    public function testStaticOfDefaultTimeIsZero(): void
    {
        $terminal = \SugarCraft\Vterm\Terminal::new(80, 24);
        $terminal->feed("X");

        $snap = \SugarCraft\Vterm\Snapshot::of($terminal);

        $this->assertSame(0.0, $snap->time);
    }

    public function testCaptureDeepCopiesTheGridSoLaterFeedsCannotRetroMutate(): void
    {
        $terminal = \SugarCraft\Vterm\Terminal::new(80, 24);
        $terminal->feed("hello\r\n");

        $snap = Snapshot::of($terminal);

        // The capture is a copy — not the live grid object.
        $this->assertNotSame($terminal->grid(), $snap->grid);

        $terminal->feed("world\r\n");

        // Frozen frame: the snapshot never saw the second feed — row 1
        // stayed blank where the live grid now prints 'world'.
        $this->assertSame('h', $snap->grid->cell(0, 0)->grapheme);
        $this->assertSame(' ', $snap->grid->cell(1, 0)->grapheme);
        $this->assertSame('e', $snap->grid->cell(0, 1)->grapheme);

        // The live grid moved on.
        $this->assertSame('w', $terminal->grid()->cell(1, 0)->grapheme);
    }

    public function testSnapshotMethodAlsoFreezesAgainstInPlaceMutation(): void
    {
        $terminal = \SugarCraft\Vterm\Terminal::new(10, 3);
        $terminal->feed("A");
        $snap = $terminal->snapshot();

        // put() mutates the live Buffer in place — the exact retro-mutation
        // surface the aliasing defect exposed.
        $terminal->grid()->put(0, 0, new Cell(char: 'B'));

        $this->assertSame('B', $terminal->grid()->cell(0, 0)->char);
        $this->assertSame('A', $snap->grid->cell(0, 0)->char);
    }

    public function testRevisionCountsWritePathCalls(): void
    {
        $terminal = \SugarCraft\Vterm\Terminal::new(80, 24);
        $this->assertSame(0, $terminal->revision());

        $terminal->feed('a');
        $this->assertSame(1, $terminal->revision());

        $terminal->feed('b')->feed('c');
        $this->assertSame(3, $terminal->revision());

        $before = $terminal->snapshot();
        $this->assertSame(3, $before->revision);

        $terminal->flush();
        $after = $terminal->snapshot();
        $this->assertSame(4, $terminal->revision());
        $this->assertSame(4, $after->revision);
    }

    public function testConstructorCarriesRevisionDefaultZero(): void
    {
        $snap = new Snapshot(new Buffer(10, 3), new Cursor(), 0.0);

        $this->assertSame(0, $snap->revision);
    }
}
