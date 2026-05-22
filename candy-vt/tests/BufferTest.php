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
}
