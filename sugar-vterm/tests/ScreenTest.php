<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Screen\Screen;
use SugarCraft\Vterm\Sgr\Sgr;

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

        $change1 = array_filter($diff, fn ($d) => $d['row'] === 0 && $d['col'] === 1);
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

    public function testLinesReAppendsStoredCombiningMarks(): void
    {
        $buf = new Buffer(4, 1);
        // 'e' + COMBINING ACUTE ACCENT carried the way the screen stores it:
        // base grapheme in the cell, the mark in the combining payload.
        $buf->put(0, 0, new Cell(grapheme: 'e', combining: "\u{301}"));
        $buf->put(0, 1, new Cell(grapheme: 'x'));

        $lines = iterator_to_array(Screen::fromBuffer($buf)->lines());

        $this->assertSame("e\u{301}x  ", $lines[0]);
    }

    public function testCombiningMarksSurviveAnEmulatorFeedRoundTrip(): void
    {
        $terminal = new \SugarCraft\Vterm\Terminal\Terminal(cols: 10, rows: 3);
        $terminal->feed("e\u{301}x");

        $lines = iterator_to_array($terminal->screen()->lines());

        $this->assertSame("e\u{301}x", rtrim($lines[0], ' '));
        // The base column carries ONE cell holding base+mark — not a lost mark.
        $this->assertSame("e\u{301}", $terminal->screen()->cell(0, 0)->grapheme . $terminal->screen()->cell(0, 0)->combining);
    }

    public function testLinesIsAGeneratorWithFullWidthPadding(): void
    {
        $screen = Screen::fromBuffer(new Buffer(4, 2));

        // m1 contract: the channel is lazy — row 0 arrives on demand with
        // its key, and the generator is single-pass (a fresh call feeds the
        // array assertion below rather than rewinding this one).
        $lines = $screen->lines();
        $this->assertSame(0, $lines->key());
        $this->assertSame('    ', $lines->current());
        $this->assertSame(['    ', '    '], iterator_to_array($screen->lines()));
    }

    public function testLinesWithHistoryYieldsScrolledOffRowsOldestFirst(): void
    {
        $terminal = new \SugarCraft\Vterm\Terminal\Terminal(cols: 4, rows: 2, scrollbackSize: 10);
        $terminal->feed("1\r\n2\r\n3\r\n4");

        $screen = $terminal->screen();
        $this->assertNotNull($screen->scrollback());
        $this->assertSame(['3   ', '4   '], iterator_to_array($screen->lines()));
        $this->assertSame(
            ['1   ', '2   ', '3   ', '4   '],
            iterator_to_array($screen->linesWithHistory()),
        );
    }

    public function testScreenSnapshotFreezesScrollbackAgainstLaterScrolling(): void
    {
        $terminal = new \SugarCraft\Vterm\Terminal\Terminal(cols: 4, rows: 2, scrollbackSize: 10);
        $terminal->feed("1\r\n2\r\n3\r\n4");

        $snapshot = $terminal->screen();
        $frozenRing = $snapshot->scrollback();
        $this->assertNotNull($frozenRing);
        $frozenRows = $frozenRing->all();
        $frozenLines = iterator_to_array($snapshot->linesWithHistory());
        $this->assertSame(['1   ', '2   ', '3   ', '4   '], $frozenLines);

        // More scrolling: the emulator's live ring grows, the captured
        // snapshot must not.
        $terminal->feed("\r\n5\r\n6");
        $later = $terminal->screen();

        $this->assertSame($frozenRows, $frozenRing->all());
        $this->assertSame($frozenLines, iterator_to_array($snapshot->linesWithHistory()));
        // The second capture saw the extra history — without this leg the
        // equality pins above would stay green even if feed() never scrolled.
        $this->assertNotSame($frozenLines, iterator_to_array($later->linesWithHistory()));
        $this->assertCount(6, iterator_to_array($later->linesWithHistory()));
        // Identity: each capture carries its OWN ring (pre-clone both
        // screens held the single live ring and this assert went red).
        $this->assertNotSame($snapshot->scrollback(), $later->scrollback());
    }

    public function testLinesWithHistoryWithoutRingEqualsLines(): void
    {
        $buf = new Buffer(3, 2);
        $buf->put(0, 0, new Cell(grapheme: 'A'));
        $screen = Screen::fromBuffer($buf);

        $this->assertNull($screen->scrollback());
        $this->assertSame(
            iterator_to_array($screen->lines()),
            iterator_to_array($screen->linesWithHistory()),
        );
    }
}
