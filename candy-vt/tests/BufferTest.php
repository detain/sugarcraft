<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Sgr\Sgr;

final class BufferTest extends TestCase
{
    public function testConstructFillsWithEmptyCells(): void
    {
        $buf = new Buffer(3, 2);
        $this->assertSame(3, $buf->cols);
        $this->assertSame(2, $buf->rows);

        for ($r = 0; $r < 2; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $cell = $buf->cell($r, $c);
                $this->assertSame(' ', $cell->grapheme);
                $this->assertFalse($cell->continuation);
            }
        }
    }

    public function testResizePreservesExistingCells(): void
    {
        $buf = new Buffer(3, 2);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        $buf->put(1, 2, new Cell(grapheme: 'B'));

        $resized = $buf->resize(5, 4);
        $this->assertSame(5, $resized->cols);
        $this->assertSame(4, $resized->rows);
        $this->assertSame('A', $resized->cell(0, 0)->grapheme);
        $this->assertSame('B', $resized->cell(1, 2)->grapheme);
    }

    public function testResizeShrinksAndTruncates(): void
    {
        $buf = new Buffer(3, 2);
        $buf->put(1, 2, new Cell(grapheme: 'X'));

        $resized = $buf->resize(2, 1);
        $this->assertSame(2, $resized->cols);
        $this->assertSame(1, $resized->rows);
        // cell (1,2) is outside new bounds and should be dropped
        $this->assertSame(' ', $resized->cell(0, 0)->grapheme);
        // row 1 is completely outside new bounds
        $this->assertSame(' ', $resized->cell(1, 0)->grapheme);
    }

    public function testResizePadsWithEmptyCells(): void
    {
        $buf = new Buffer(2, 2);
        $resized = $buf->resize(4, 3);
        $this->assertSame(' ', $resized->cell(2, 3)->grapheme);
    }

    public function testPutAndCell(): void
    {
        $buf = new Buffer(10, 10);
        $cell = new Cell(grapheme: 'Z', sgr: Sgr::empty()->withBold(true));
        $buf->put(3, 5, $cell);

        $retrieved = $buf->cell(3, 5);
        $this->assertSame('Z', $retrieved->grapheme);
        $this->assertTrue($retrieved->sgr()->bold);
    }

    public function testPutOutOfBoundsClampedSilently(): void
    {
        $buf = new Buffer(2, 2);
        $buf->put(-1, 0, new Cell(grapheme: 'X'));
        $buf->put(0, 99, new Cell(grapheme: 'Y'));
        $buf->put(99, 0, new Cell(grapheme: 'Z'));

        // No exception; in-bounds cells should be unchanged
        $this->assertSame(' ', $buf->cell(0, 0)->grapheme);
    }

    public function testCellOutOfBoundsReturnsEmptyCell(): void
    {
        $buf = new Buffer(3, 3);
        $this->assertSame(' ', $buf->cell(-1, 0)->grapheme);
        $this->assertSame(' ', $buf->cell(0, -1)->grapheme);
        $this->assertSame(' ', $buf->cell(3, 0)->grapheme);
        $this->assertSame(' ', $buf->cell(0, 3)->grapheme);
    }


    public function testEachIteratesAllCells(): void
    {
        $buf = new Buffer(2, 3);
        $buf->put(1, 1, new Cell(grapheme: 'X'));

        $found = [];
        foreach ($buf->each() as $item) {
            $found[] = $item;
        }

        $this->assertCount(6, $found); // 2 cols × 3 rows
        $xCell = array_values(array_filter($found, fn ($i) => $i['cell']->grapheme === 'X'));
        $this->assertCount(1, $xCell);
        $this->assertSame(1, $xCell[0]['row']);
        $this->assertSame(1, $xCell[0]['col']);
    }

    public function testCopyProducesIndependentGrid(): void
    {
        $buf = new Buffer(2, 2);
        $copy = $buf->copy();
        $copy[0][0] = new Cell(grapheme: 'M');
        $this->assertSame(' ', $buf->cell(0, 0)->grapheme);
    }

    // ─── Dirty-region tracking (absorbed from the retired CellGrid) ───

    public function testDirtyRegionInitiallySentinelValues(): void
    {
        $region = (new Buffer(10, 10))->dirtyRegion();

        $this->assertSame(PHP_INT_MAX, $region['minRow']);
        $this->assertSame(-1, $region['maxRow']);
        $this->assertSame(PHP_INT_MAX, $region['minCol']);
        $this->assertSame(-1, $region['maxCol']);
    }

    public function testDirtyRegionAfterPut(): void
    {
        $buf = new Buffer(10, 10);
        $buf->put(3, 5, new Cell(grapheme: 'X'));

        $region = $buf->dirtyRegion();

        $this->assertSame(3, $region['minRow']);
        $this->assertSame(3, $region['maxRow']);
        $this->assertSame(5, $region['minCol']);
        $this->assertSame(5, $region['maxCol']);
    }

    public function testDirtyRegionExpandsWithMultiplePuts(): void
    {
        $buf = new Buffer(10, 10);
        $buf->put(2, 3, new Cell(grapheme: 'A'));
        $buf->put(7, 8, new Cell(grapheme: 'B'));

        $region = $buf->dirtyRegion();

        $this->assertSame(2, $region['minRow']);
        $this->assertSame(7, $region['maxRow']);
        $this->assertSame(3, $region['minCol']);
        $this->assertSame(8, $region['maxCol']);
    }

    public function testDirtyRegionWithContiguousRange(): void
    {
        $buf = new Buffer(20, 10);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        $buf->put(0, 5, new Cell(grapheme: 'B'));

        $region = $buf->dirtyRegion();

        $this->assertSame(0, $region['minRow']);
        $this->assertSame(0, $region['maxRow']);
        $this->assertSame(0, $region['minCol']);
        $this->assertSame(5, $region['maxCol']);
    }

    public function testPutWithStyledCellTracksDirty(): void
    {
        $buf = new Buffer(10, 10);
        $buf->put(4, 4, new Cell(char: 'M', fg: 1, attrs: Cell::ATTR_BOLD));

        $this->assertSame('M', $buf->cell(4, 4)->char);
        $this->assertSame(1, $buf->cell(4, 4)->fg);
        $region = $buf->dirtyRegion();
        $this->assertSame(4, $region['minRow']);
        $this->assertSame(4, $region['maxRow']);
    }

    public function testPutOutOfBoundsDoesNotTouchDirtyRegion(): void
    {
        $buf = new Buffer(5, 5);
        $buf->put(-1, 0, new Cell(grapheme: 'X'));
        $buf->put(9, 9, new Cell(grapheme: 'Y'));

        $region = $buf->dirtyRegion();
        $this->assertSame(PHP_INT_MAX, $region['minRow'], 'a rejected put must not claim dirty bounds');
        $this->assertSame(-1, $region['maxRow']);
    }

    // ─── clear() — fresh instance, same dimensions (absorbed from CellGrid) ───

    public function testClearReturnsNewInstanceWithSameDimensions(): void
    {
        $buf = new Buffer(15, 20);
        $buf->put(5, 5, new Cell(grapheme: 'X'));

        $cleared = $buf->clear();

        $this->assertSame(15, $cleared->cols);
        $this->assertSame(20, $cleared->rows);
        $this->assertSame(' ', $cleared->cell(5, 5)->grapheme);
        $this->assertSame(PHP_INT_MAX, $cleared->dirtyRegion()['minRow'], 'a cleared grid starts undirtied');
    }

    public function testClearDoesNotMutateOriginal(): void
    {
        $buf = new Buffer(10, 10);
        $buf->put(1, 1, new Cell(grapheme: 'X'));

        $buf->clear();

        $this->assertSame('X', $buf->cell(1, 1)->grapheme);
    }

    public function testResizeDoesNotMutateOriginalAndStartsUndirtied(): void
    {
        $buf = new Buffer(10, 10);
        $buf->put(1, 1, new Cell(grapheme: 'X'));

        $resized = $buf->resize(5, 5);

        $this->assertNotSame($buf, $resized);
        $this->assertSame('X', $buf->cell(1, 1)->grapheme, 'original grid cell preserved');
        $this->assertSame('X', $resized->cell(1, 1)->grapheme);
        $this->assertSame(PHP_INT_MAX, $resized->dirtyRegion()['minRow'], 'resize mints a fresh dirty box');
    }

    // ─── equals() (absorbed from CellGrid) ───

    public function testEqualsTrueForIdenticalGrids(): void
    {
        $a = new Buffer(5, 5);
        $a->put(2, 2, new Cell(grapheme: 'X'));

        $b = new Buffer(5, 5);
        $b->put(2, 2, new Cell(grapheme: 'X'));

        $this->assertTrue($a->equals($b));
    }

    public function testEqualsFalseOnDifferentDimensions(): void
    {
        $this->assertFalse((new Buffer(5, 5))->equals(new Buffer(10, 5)));
    }

    public function testEqualsFalseOnDifferentContent(): void
    {
        $a = new Buffer(5, 5);
        $a->put(1, 1, new Cell(grapheme: 'A'));

        $b = new Buffer(5, 5);
        $b->put(1, 1, new Cell(grapheme: 'B'));

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsFalseOnEmptyGridVsPopulated(): void
    {
        $a = new Buffer(5, 5);
        $b = new Buffer(5, 5);
        $b->put(0, 0, new Cell(grapheme: 'X'));

        $this->assertFalse($a->equals($b));
    }

    public function testEqualsTrueForEmptyGrids(): void
    {
        $this->assertTrue((new Buffer(80, 24))->equals(new Buffer(80, 24)));
    }
}
