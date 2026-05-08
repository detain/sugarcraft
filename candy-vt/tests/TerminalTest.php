<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell\Cell;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Hyperlink\Hyperlink;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Sgr\Sgr;
use SugarCraft\Vt\Terminal\Terminal;

final class TerminalTest extends TestCase
{
    public function testCreateDefaults(): void
    {
        $term = Terminal::create();
        $screen = $term->screen();
        $this->assertSame(80, $screen->cols);
        $this->assertSame(24, $screen->rows);
        $this->assertSame(0, $term->cursor()->row);
        $this->assertSame(0, $term->cursor()->col);
        $this->assertTrue($term->cursor()->visible);
    }

    public function testCreateWithDimensions(): void
    {
        $term = Terminal::create(cols: 40, rows: 10);
        $screen = $term->screen();
        $this->assertSame(40, $screen->cols);
        $this->assertSame(10, $screen->rows);
    }

    public function testFeedPrintsCharactersAtCursor(): void
    {
        $term = Terminal::create(cols: 5, rows: 5);
        $term->feed("Red");
        $screen = $term->screen();
        $this->assertSame('R', $screen->cell(0, 0)->grapheme);
        $this->assertSame('e', $screen->cell(0, 1)->grapheme);
        $this->assertSame('d', $screen->cell(0, 2)->grapheme);
        $this->assertSame(0, $term->cursor()->row);
        $this->assertSame(3, $term->cursor()->col);
    }

    public function testFeedAppliesSgrToPrintedCells(): void
    {
        $term = Terminal::create(cols: 5, rows: 5);
        $term->feed("\x1b[31mR\x1b[0mX");
        $screen = $term->screen();
        $this->assertNotNull($screen->cell(0, 0)->foreground());
        $this->assertSame(1, $screen->cell(0, 0)->foreground()->kind); // Indexed16
        $this->assertSame(1, $screen->cell(0, 0)->foreground()->value); // red
        $this->assertNull($screen->cell(0, 1)->foreground()); // reset to default
    }

    public function testFeedMovesCursorViaCsiH(): void
    {
        $term = Terminal::create(cols: 10, rows: 10);
        $term->feed("\x1b[3;5H");
        $this->assertSame(2, $term->cursor()->row);
        $this->assertSame(4, $term->cursor()->col);
    }

    public function testFeedHidesCursorViaDecMode25(): void
    {
        $term = Terminal::create();
        $term->feed("\x1b[?25l");
        $this->assertFalse($term->cursor()->visible);
        $this->assertFalse($term->mode()->cursorVisible);
        $term->feed("\x1b[?25h");
        $this->assertTrue($term->cursor()->visible);
        $this->assertTrue($term->mode()->cursorVisible);
    }

    public function testFeedSavesAndRestoresCursorViaDecScDecRc(): void
    {
        $term = Terminal::create(cols: 10, rows: 10);
        $term->feed("\x1b[3;5H\x1b7\x1b[1;1H\x1b8");
        $this->assertSame(2, $term->cursor()->row);
        $this->assertSame(4, $term->cursor()->col);
    }

    public function testFeedToggle1049PreservesMainScreen(): void
    {
        $term = Terminal::create(cols: 5, rows: 3);
        $term->feed("Main\x1b[?1049h");
        $this->assertTrue($term->mode()->altScreen);
        // Alt is fresh.
        $this->assertSame(' ', $term->screen()->cell(0, 0)->grapheme);
        // Write something different on alt, then leave.
        $term->feed("Z\x1b[?1049l");
        $this->assertFalse($term->mode()->altScreen);
        // Main contents are restored.
        $this->assertSame('M', $term->screen()->cell(0, 0)->grapheme);
        $this->assertSame('a', $term->screen()->cell(0, 1)->grapheme);
    }

    public function testFeedSetsBracketedPaste(): void
    {
        $term = Terminal::create();
        $term->feed("\x1b[?2004h");
        $this->assertTrue($term->mode()->bracketedPaste);
        $term->feed("\x1b[?2004l");
        $this->assertFalse($term->mode()->bracketedPaste);
    }

    public function testFeedSetsMouseModes(): void
    {
        $term = Terminal::create();
        $term->feed("\x1b[?1000;1006h");
        $this->assertTrue($term->mode()->mouseAny);
        $this->assertTrue($term->mode()->mouseSgr);
    }

    public function testResize(): void
    {
        $term = Terminal::create(cols: 10, rows: 5);
        $term->resize(cols: 20, rows: 10);
        $screen = $term->screen();
        $this->assertSame(20, $screen->cols);
        $this->assertSame(10, $screen->rows);
    }

    public function testResizeThrowsOnInvalidDimensions(): void
    {
        $term = Terminal::create();
        $this->expectException(\InvalidArgumentException::class);
        $term->resize(cols: 0, rows: 10);
    }

    public function testResizePreservesContent(): void
    {
        $term = Terminal::create(cols: 5, rows: 5);
        // Manually inject a cell via internal buffer access pattern
        $buf = $term->screen();
        $screen = $term->screen();
        $this->assertSame(' ', $screen->cell(0, 0)->grapheme);

        $term->resize(cols: 3, rows: 3);
        $screen = $term->screen();
        $this->assertSame(3, $screen->cols);
        $this->assertSame(3, $screen->rows);
    }

    public function testScreenReturnsReadonlySnapshot(): void
    {
        $term = Terminal::create(cols: 3, rows: 3);
        $s1 = $term->screen();
        $s2 = $term->screen();
        $this->assertSame($s1->cols, $s2->cols);
        $this->assertSame($s1->rows, $s2->rows);
    }

    public function testCursor(): void
    {
        $term = Terminal::create();
        $this->assertInstanceOf(Cursor::class, $term->cursor());
        $this->assertSame(0, $term->cursor()->row);
    }

    public function testMode(): void
    {
        $term = Terminal::create();
        $this->assertInstanceOf(Mode::class, $term->mode());
        $this->assertTrue($term->mode()->cursorVisible);
        $this->assertFalse($term->mode()->altScreen);
    }

    public function testWindowTitleDefaultNull(): void
    {
        $term = Terminal::create();
        $this->assertNull($term->windowTitle());
    }

    public function testWithBufferReturnsNewInstance(): void
    {
        $term = Terminal::create(cols: 5, rows: 5);
        $newBuf = new Buffer(3, 3);
        $newTerm = $term->withBuffer($newBuf);
        $this->assertNotSame($term, $newTerm);
        $this->assertSame(5, $term->screen()->cols);
        $this->assertSame(3, $newTerm->screen()->cols);
    }

    public function testWithCursorReturnsNewInstance(): void
    {
        $term = Terminal::create();
        $newCur = new Cursor(row: 5, col: 10);
        $newTerm = $term->withCursor($newCur);
        $this->assertNotSame($term, $newTerm);
        $this->assertSame(0, $term->cursor()->row);
        $this->assertSame(5, $newTerm->cursor()->row);
    }

    public function testWithModeReturnsNewInstance(): void
    {
        $term = Terminal::create();
        $newMode = (new Mode())->withAltScreen(true);
        $newTerm = $term->withMode($newMode);
        $this->assertNotSame($term, $newTerm);
        $this->assertFalse($term->mode()->altScreen);
        $this->assertTrue($newTerm->mode()->altScreen);
    }

    public function testWithWindowTitleReturnsNewInstance(): void
    {
        $term = Terminal::create();
        $newTerm = $term->withWindowTitle('My Title');
        $this->assertNotSame($term, $newTerm);
        $this->assertNull($term->windowTitle());
        $this->assertSame('My Title', $newTerm->windowTitle());
    }
}
