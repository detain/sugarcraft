<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Screen;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Screen\Scrollback;
use SugarCraft\Vt\Terminal\Terminal;

final class ScrollbackTest extends TestCase
{
    /**
     * Write 1100 lines into a 24-row screen and verify that lines
     * from the initial screen fill are accessible in scrollback.
     *
     * A 24-row screen fills at row 23. Each subsequent newline triggers
     * a scroll and pushes the top row to scrollback.
     *
     * After 1100 lines:
     * - 23 lines fill the screen (no scroll yet)
     * - 1077 subsequent newlines each trigger one scroll
     * - scrollback is 1000 lines; first 77 entries are overwritten
     * - the 1000 retained entries are lines 77-1076, accessible at
     *   scrollback positions 0-999
     */
    public function testScrollbackStoresScrolledLinesAfter1100Lines(): void
    {
        $term = Terminal::create(cols: 80, rows: 24);

        // Write 1100 lines of text. Use \r\n to ensure each line starts at col 0.
        for ($line = 0; $line < 1100; $line++) {
            $text = sprintf('line%04d', $line);
            $term->feed($text);
            $term->feed("\r\n");
        }

        $sb = $term->screen()->scrollback();
        $this->assertNotNull($sb);

        // Count should be at max since we pushed 1077 rows into a 1000-line buffer
        $this->assertSame(1000, $sb->count());

        // After wrapping, the first retained entries start at line 77.
        // Verify a few entries in the middle (e.g., lines 500-505).
        for ($i = 0; $i < 6; $i++) {
            $lineNum = 500 + $i;
            $expectedText = sprintf('line%04d', $lineNum);
            $offset = $lineNum - 77; // offset 0 = line 77, offset 423 = line 500
            $row = $sb->at($offset);
            $this->assertNotNull($row, "scrollback at offset $offset should not be null (line $lineNum)");
            $actual = $this->rowToString($row);
            $this->assertSame(
                str_pad($expectedText, 80, ' '),
                $actual,
                "scrollback at offset $offset: expected '$expectedText', got '$actual' (line $lineNum)",
            );
        }

        // Also verify the oldest retained entry (line 77) is at offset 0
        $row = $sb->at(0);
        $this->assertNotNull($row);
        $actual = $this->rowToString($row);
        $expectedText = sprintf('line%04d', 77);
        $this->assertSame(
            str_pad($expectedText, 80, ' '),
            $actual,
            "scrollback at offset 0: expected '$expectedText', got '$actual'",
        );
    }

    public function testScrollbackEmptyByDefault(): void
    {
        $term = Terminal::create();
        $sb = $term->screen()->scrollback();
        $this->assertNotNull($sb);
        $this->assertSame(0, $sb->count());
    }

    public function testScrollbackPushAndRetrieve(): void
    {
        $sb = new Scrollback(5);

        $row0 = $this->makeRow('AAA');
        $row1 = $this->makeRow('BBB');
        $row2 = $this->makeRow('CCC');

        $sb->push($row0);
        $this->assertSame(1, $sb->count());

        $sb->push($row1);
        $this->assertSame(2, $sb->count());

        $sb->push($row2);
        $this->assertSame(3, $sb->count());

        $this->assertSame('AAA', $this->rowToString($sb->at(0)));
        $this->assertSame('BBB', $this->rowToString($sb->at(1)));
        $this->assertSame('CCC', $this->rowToString($sb->at(2)));
    }

    public function testScrollbackRingBufferOverwrite(): void
    {
        $sb = new Scrollback(3);

        $sb->push($this->makeRow('AAA'));
        $sb->push($this->makeRow('BBB'));
        $sb->push($this->makeRow('CCC'));

        // Buffer is now full: [AAA, BBB, CCC], head=0, tail=0, count=3
        $this->assertSame(3, $sb->count());

        // Push a new row — oldest (AAA) is overwritten
        $sb->push($this->makeRow('DDD'));

        // count stays at maxSize (can't exceed 3), head=1, tail=1
        $this->assertSame(3, $sb->count());

        // AAA is gone; BBB is now at index 0 (oldest), then CCC, then DDD
        $this->assertSame('BBB', $this->rowToString($sb->at(0)));
        $this->assertSame('CCC', $this->rowToString($sb->at(1)));
        $this->assertSame('DDD', $this->rowToString($sb->at(2)));
        $this->assertNull($sb->at(3));
    }

    public function testScrollbackAllReturnsOldestToNewest(): void
    {
        $sb = new Scrollback(100);
        for ($i = 0; $i < 5; $i++) {
            $sb->push($this->makeRow(sprintf('L%02d', $i)));
        }

        $all = $sb->all();
        $this->assertCount(5, $all);
        $this->assertSame('L00', $this->rowToString($all[0]));
        $this->assertSame('L04', $this->rowToString($all[4]));
    }

    public function testScrollbackAllAfterWrap(): void
    {
        $sb = new Scrollback(3);
        for ($i = 0; $i < 5; $i++) {
            $sb->push($this->makeRow(sprintf('L%02d', $i)));
        }

        // Only last 3 entries retained: L02, L03, L04
        $all = $sb->all();
        $this->assertCount(3, $all);
        $this->assertSame('L02', $this->rowToString($all[0]));
        $this->assertSame('L03', $this->rowToString($all[1]));
        $this->assertSame('L04', $this->rowToString($all[2]));
    }

    public function testScrollbackMaxSize(): void
    {
        $sb = new Scrollback(500);
        $this->assertSame(500, $sb->maxSize());
        $this->assertSame(0, $sb->count());
    }

    public function testTerminalWithScrollbackSize(): void
    {
        $term = Terminal::create(cols: 80, rows: 24);
        $term = $term->withScrollbackSize(500);

        // Verify scrollback was replaced with new size
        $sb = $term->screen()->scrollback();
        $this->assertNotNull($sb);
        $this->assertSame(500, $sb->maxSize());
    }

    public function testTerminalWithScrollbackSizeThrowsOnZero(): void
    {
        $term = Terminal::create();
        $this->expectException(\InvalidArgumentException::class);
        $term->withScrollbackSize(0);
    }

    public function testScrollbackSurvivesMultipleScreenSnapshots(): void
    {
        $term = Terminal::create(cols: 80, rows: 24);

        // Feed 100 lines
        for ($i = 0; $i < 100; $i++) {
            $term->feed("line$i\r\n");
        }

        // First screen snapshot
        $screen1 = $term->screen();
        $sb1 = $screen1->scrollback();
        $count1 = $sb1->count();

        // Feed 100 more lines
        for ($i = 100; $i < 200; $i++) {
            $term->feed("line$i\r\n");
        }

        // Second screen snapshot
        $screen2 = $term->screen();
        $sb2 = $screen2->scrollback();
        $count2 = $sb2->count();

        // Scrollback grew as more content was added
        $this->assertGreaterThanOrEqual($count1, $count2);
    }

    // ─── helpers ─────────────────────────────────────────────────────────────

    /** Build a 3-cell row with the given grapheme repeated in each cell. */
    private function makeRow(string $text): array
    {
        return [
            new Cell(grapheme: $text[0] ?? ' '),
            new Cell(grapheme: $text[1] ?? ' '),
            new Cell(grapheme: $text[2] ?? ' '),
        ];
    }

    /** Convert a row (array<int, Cell>) to a string of graphemes. */
    private function rowToString(array $row): string
    {
        $s = '';
        foreach ($row as $cell) {
            $s .= $cell->grapheme;
        }
        return $s;
    }
}
