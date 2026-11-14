<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Terminal\Terminal;

/**
 * vterm-24: streaming-reader pins for the emulator façade.
 *
 * `cellAt()`/`rowCells()` read the LIVE buffer so per-row consumers never
 * pay the O(rows × cols) rebuild of `screen()`; `screen()` keeps its
 * snapshot semantics (deep-detached, fresh copy per call).  These pins
 * lock both halves: the new accessors reflect writes immediately and
 * detach on caller mutation, while old snapshots stay frozen.
 */
final class StreamingAccessorsTest extends TestCase
{
    private const COLS = 6;
    private const ROWS = 2;

    private function terminal(): Terminal
    {
        return Terminal::new(self::COLS, self::ROWS);
    }

    public function testRowCellsAndCellAtReadTheLiveBuffer(): void
    {
        $t = $this->terminal();
        $t->feed('AB');

        $this->assertSame('A', $t->cellAt(0, 0)->grapheme);
        $this->assertSame('B', $t->cellAt(0, 1)->grapheme);
        $this->assertSame(' ', $t->cellAt(0, 2)->grapheme);

        $t->feed("\x1b[1;1HXY");
        $this->assertSame('X', $t->cellAt(0, 0)->grapheme, 'accessors must see writes without a rebuild');
        $row = $t->rowCells(0);
        $this->assertCount(self::COLS, $row);
        $this->assertSame('Y', $row[1]->grapheme);
    }

    public function testStreamingReadsDetachAtTheCaller(): void
    {
        $t = $this->terminal();
        $t->feed('AB');

        $row = $t->rowCells(0);
        $row[0] = new Cell(grapheme: 'Z');

        $this->assertSame('A', $t->cellAt(0, 0)->grapheme, 'a caller-side row write must not reach the grid');
    }

    public function testStreamingReadsHonourTheBufferBoundsPolicy(): void
    {
        $t = $this->terminal();

        $this->assertSame(' ', $t->cellAt(99, 99)->grapheme);
        $oob = $t->rowCells(-1);
        $this->assertCount(self::COLS, $oob);
        $this->assertSame(' ', $oob[0]->grapheme);
    }

    public function testScreenSnapshotStaysDetachedAndIsRecopiedPerCall(): void
    {
        $t = $this->terminal();
        $t->feed('AB');

        $snapshot = $t->screen();
        $again = $t->screen();
        $this->assertNotSame($snapshot, $again, 'same revision still returns a fresh copy, not a cached object');

        $t->feed("\x1b[1;1HXY");
        $this->assertSame('A', $snapshot->cell(0, 0)->grapheme, 'an old snapshot is frozen');
        $this->assertSame('A', $again->cell(0, 0)->grapheme, 'and so is its same-revision sibling');
        $this->assertSame('X', $t->screen()->cell(0, 0)->grapheme);
    }
}
