<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Terminal;
use SugarCraft\Vt\Theme;

final class TerminalTest extends TestCase
{
    public function testNewCreatesTerminal(): void
    {
        $t = Terminal::new(80, 24);

        $this->assertSame(80, $t->cols);
        $this->assertSame(24, $t->rows);
    }

    public function testFeedAcceptsBytes(): void
    {
        $t = Terminal::new();

        $t->feed("Hello");

        $cell = $t->grid()->get(0, 0);
        $this->assertSame('H', $cell->char);
    }

    public function testFeedReturnsTerminalForChaining(): void
    {
        $t = Terminal::new();

        $result = $t->feed("X");

        $this->assertSame($t, $result);
    }

    public function testFeedCSICup(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b[3;5H");

        $this->assertSame(2, $t->cursor()->row);
        $this->assertSame(4, $t->cursor()->col);
    }

    public function testFeedCSIRedForeground(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b[31mX");

        $cell = $t->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(1, $cell->fg);
    }

    public function testFeedCSIBold(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b[1mX");

        $cell = $t->grid()->get(0, 0);
        $this->assertSame('X', $cell->char);
        $this->assertSame(Cell::ATTR_BOLD, $cell->attrs & Cell::ATTR_BOLD);
    }

    public function testFeedCSISgrReset(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b[1;31mX\x1b[0mY");

        $xCell = $t->grid()->get(0, 0);
        $yCell = $t->grid()->get(0, 1);

        $this->assertSame('X', $xCell->char);
        $this->assertSame(Cell::ATTR_BOLD, $xCell->attrs & Cell::ATTR_BOLD);
        $this->assertSame(1, $xCell->fg);

        $this->assertSame('Y', $yCell->char);
        $this->assertSame(0, $yCell->attrs & Cell::ATTR_BOLD);
        $this->assertSame(7, $yCell->fg);
    }

    public function testFeedCSIEdClearScreen(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b[2J");

        $this->assertSame(' ', $t->grid()->get(0, 0)->char);
        $this->assertSame(' ', $t->grid()->get(23, 79)->char);
    }

    public function testFeedCSIElClearLine(): void
    {
        $t = Terminal::new();

        $t->feed("Hello\x1b[K");

        $this->assertSame('H', $t->grid()->get(0, 0)->char);
        $this->assertSame(' ', $t->grid()->get(0, 5)->char);
    }

    public function testFeedCSIDecsetCursorHidden(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b[?25h");

        $this->assertFalse($t->cursor()->visible);
    }

    public function testFeedCSIDecrstCursorVisible(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b[?25h\x1b[?25l");

        $this->assertTrue($t->cursor()->visible);
    }

    public function testSnapshotCreatesSnapshot(): void
    {
        $t = Terminal::new();

        $t->feed("X");

        $snap = $t->snapshot(1.5);

        $this->assertSame('X', $snap->grid->get(0, 0)->char);
        $this->assertSame(1.5, $snap->time);
    }

    public function testCursorReturnsCurrentCursor(): void
    {
        $t = Terminal::new();
        $t->feed("\x1b[5;10H");

        $cursor = $t->cursor();

        $this->assertSame(4, $cursor->row);
        $this->assertSame(9, $cursor->col);
    }

    public function testGridReturnsCurrentGrid(): void
    {
        $t = Terminal::new();
        $t->feed("ABC");

        $grid = $t->grid();

        $this->assertSame('A', $grid->get(0, 0)->char);
        $this->assertSame('B', $grid->get(0, 1)->char);
        $this->assertSame('C', $grid->get(0, 2)->char);
    }

    public function testWindowTitleFromOSC(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b]2;My Title\x07");

        $this->assertSame('My Title', $t->windowTitle());
    }

    public function testFeedWithTheme(): void
    {
        $theme = new Theme(defaultFg: 0, defaultBg: 15);
        $t = Terminal::new(80, 24, $theme);

        $cell = $t->grid()->get(0, 0);
        $this->assertSame(' ', $cell->char);
    }

    public function testFeedComplexSequence(): void
    {
        $t = Terminal::new();

        $t->feed("\x1b[31m\x1b[1mRed Bold\x1b[0m Normal");

        $this->assertSame('R', $t->grid()->get(0, 0)->char);
        $this->assertSame(1, $t->grid()->get(0, 0)->fg);
        $this->assertSame(Cell::ATTR_BOLD, $t->grid()->get(0, 0)->attrs & Cell::ATTR_BOLD);
    }
}
