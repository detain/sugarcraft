<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Screen\Screen;
use SugarCraft\Vt\Sgr\Sgr;

final class ScreenTest extends TestCase
{
    public function testFromBuffer(): void
    {
        $buf = new Buffer(3, 2);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        $buf->put(0, 2, new Cell(grapheme: 'C'));

        $screen = Screen::fromBuffer($buf);
        $this->assertSame(3, $screen->cols);
        $this->assertSame(2, $screen->rows);
        $this->assertSame('A', $screen->cell(0, 0)->grapheme);
        $this->assertSame('C', $screen->cell(0, 2)->grapheme);
        $this->assertSame(' ', $screen->cell(1, 1)->grapheme);
    }

    public function testCellOutOfBoundsReturnsEmptyCell(): void
    {
        $buf = new Buffer(2, 2);
        $screen = Screen::fromBuffer($buf);

        $this->assertSame(' ', $screen->cell(-1, 0)->grapheme);
        $this->assertSame(' ', $screen->cell(0, 2)->grapheme);
        $this->assertSame(' ', $screen->cell(2, 0)->grapheme);
    }

    public function testDiffDetectsChanges(): void
    {
        $buf1 = new Buffer(3, 1);
        $buf1->put(0, 0, new Cell(grapheme: 'A'));
        $buf1->put(0, 1, new Cell(grapheme: 'B'));
        $buf1->put(0, 2, new Cell(grapheme: ' '));
        $s1 = Screen::fromBuffer($buf1);

        $buf2 = new Buffer(3, 1);
        $buf2->put(0, 0, new Cell(grapheme: 'A'));
        $buf2->put(0, 1, new Cell(grapheme: 'X'));
        $buf2->put(0, 2, new Cell(grapheme: 'C'));
        $s2 = Screen::fromBuffer($buf2);

        $diff = $s1->diff($s2);
        $this->assertCount(2, $diff); // B→X and ' '→C at col 2

        $change1 = array_filter($diff, fn($d) => $d['row'] === 0 && $d['col'] === 1);
        $this->assertCount(1, $change1);
        $this->assertSame('B', $change1[array_key_first($change1)]['prev']->grapheme);
        $this->assertSame('X', $change1[array_key_first($change1)]['next']->grapheme);
    }

    public function testDiffReturnsEmptyWhenIdentical(): void
    {
        $buf = new Buffer(2, 2);
        $s1 = Screen::fromBuffer($buf);
        $s2 = Screen::fromBuffer($buf);
        $this->assertEmpty($s1->diff($s2));
    }

    public function testLinesSkipsContinuationCells(): void
    {
        $buf = new Buffer(4, 1);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        $buf->put(0, 1, new Cell(grapheme: '', continuation: true));
        $buf->put(0, 2, new Cell(grapheme: 'B'));
        $buf->put(0, 3, new Cell(grapheme: '', continuation: true));

        $screen = Screen::fromBuffer($buf);
        $lines = iterator_to_array($screen->lines());
        $this->assertCount(1, $lines);
        // Continuation cells (continuation=true) have empty graphemes and are skipped
        $this->assertSame('AB', $lines[0]);
    }

    public function testLinesReturnsGraphemeStrings(): void
    {
        $buf = new Buffer(3, 2);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        $buf->put(0, 1, new Cell(grapheme: 'B'));
        $buf->put(1, 0, new Cell(grapheme: 'C'));
        $buf->put(1, 1, new Cell(grapheme: 'D'));
        $buf->put(1, 2, new Cell(grapheme: 'E'));

        $screen = Screen::fromBuffer($buf);
        $lines = iterator_to_array($screen->lines());

        $this->assertCount(2, $lines);
        $this->assertSame('AB ', $lines[0]);
        $this->assertSame('CDE', $lines[1]);
    }
}
