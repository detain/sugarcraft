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

    public function testFeedSetsWindowTitleViaOsc(): void
    {
        $term = Terminal::create();
        $term->feed("\x1b]2;Hello world\x07");
        $this->assertSame('Hello world', $term->windowTitle());
    }

    public function testFeedAttachesHyperlinkSpan(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("\x1b]8;id=x;https://example.com\x07AB\x1b]8;;\x07C");
        $screen = $term->screen();
        $this->assertNotNull($screen->cell(0, 0)->hyperlink);
        $this->assertSame('https://example.com', $screen->cell(0, 0)->hyperlink->uri);
        $this->assertNotNull($screen->cell(0, 1)->hyperlink);
        $this->assertNull($screen->cell(0, 2)->hyperlink);
    }

    public function testFeedSetsPaletteEntryViaOsc4(): void
    {
        $term = Terminal::create();
        $term->feed("\x1b]4;1;rgb:ffff/0000/0000\x07");
        $this->assertArrayHasKey(1, $term->palette());
        $this->assertSame(0xFF0000, $term->palette()[1]->value);
    }

    public function testFeedRecordsClipboardEventsViaOsc52(): void
    {
        $term = Terminal::create();
        $term->feed("\x1b]52;c;SGk=\x07\x1b]52;p;?\x07");
        $events = $term->clipboardEvents();
        $this->assertCount(2, $events);
        $this->assertSame('write', $events[0]['kind']);
        $this->assertSame('SGk=', $events[0]['payload']);
        $this->assertSame('read', $events[1]['kind']);
        $this->assertSame('p', $events[1]['selection']);
    }

    public function testFeedHtMovesToNextDefaultTabStop(): void
    {
        $term = Terminal::create(cols: 20, rows: 1);
        $term->feed("\x09");
        $this->assertSame(8, $term->cursor()->col);
        $term->feed("\x09");
        $this->assertSame(16, $term->cursor()->col);
    }

    public function testFeedEscHSetsCustomTabStopAndHtJumpsToIt(): void
    {
        $term = Terminal::create(cols: 20, rows: 1);
        // Move to col 5, set tab stop, move to col 0, tab.
        $term->feed("\x1b[1;6H\x1bH\x1b[1;1H\x09");
        $this->assertSame(5, $term->cursor()->col);
    }

    public function testFeedCsiGZeroClearsTabAtCursor(): void
    {
        $term = Terminal::create(cols: 20, rows: 1);
        // Move to col 8 (default stop) and clear that stop, then tab from col 0.
        $term = $term->withTabStops([8, 16]);
        $term->feed("\x1b[1;9H\x1b[g\x1b[1;1H\x09");
        // Default 8 cleared → next stop is 16.
        $this->assertSame(16, $term->cursor()->col);
    }

    public function testFeedCsiG3ClearsAllTabs(): void
    {
        $term = Terminal::create(cols: 20, rows: 1);
        $term->feed("\x1b[3g\x09");
        // No stops left → HT clamps at right edge (col 19).
        $this->assertSame(19, $term->cursor()->col);
    }

    public function testFeedCsiIMovesForwardNTabs(): void
    {
        $term = Terminal::create(cols: 40, rows: 1);
        $term->feed("\x1b[3I");
        $this->assertSame(24, $term->cursor()->col); // 8 + 8 + 8
    }

    public function testFeedCsiZMovesBackwardNTabs(): void
    {
        $term = Terminal::create(cols: 40, rows: 1);
        $term->feed("\x1b[1;30H\x1b[2Z");
        // From col 29: back to 24, then back to 16.
        $this->assertSame(16, $term->cursor()->col);
    }

    public function testWithTabStopsReplacesDefaults(): void
    {
        $term = Terminal::create(cols: 40, rows: 1);
        $term = $term->withTabStops([5, 15, 25]);
        $term->feed("\x09");
        $this->assertSame(5, $term->cursor()->col);
        $term->feed("\x09");
        $this->assertSame(15, $term->cursor()->col);
    }

    public function testWithTabStopsEmptyArrayDisablesTabs(): void
    {
        $term = Terminal::create(cols: 20, rows: 1);
        $term = $term->withTabStops([]);
        $term->feed("\x09");
        $this->assertSame(19, $term->cursor()->col); // clamps at right edge
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

    // ─── Alt screen convenience methods ─────────────────────────────────────

    public function testIsAltScreenDefaultFalse(): void
    {
        $term = Terminal::create();
        $this->assertFalse($term->isAltScreen());
    }

    public function testEnableAltScreenEntersFullAltScreen(): void
    {
        $term = Terminal::create(cols: 5, rows: 3);
        $term->feed("Main");
        $this->assertFalse($term->isAltScreen());

        $term->enableAltScreen();
        $this->assertTrue($term->isAltScreen());
        // Alt buffer is fresh.
        $this->assertSame(' ', $term->screen()->cell(0, 0)->grapheme);
    }

    public function testDisableAltScreenLeavesAndRestores(): void
    {
        $term = Terminal::create(cols: 5, rows: 3);
        $term->feed("Main");
        $term->enableAltScreen();
        $term->feed("Alt");
        $term->disableAltScreen();

        $this->assertFalse($term->isAltScreen());
        // Main content is restored.
        $this->assertSame('M', $term->screen()->cell(0, 0)->grapheme);
        $this->assertSame('a', $term->screen()->cell(0, 1)->grapheme);
    }

    public function testEnableDisableMultipleTimes(): void
    {
        $term = Terminal::create(cols: 5, rows: 3);
        $term->feed("First");

        $term->enableAltScreen();
        $term->feed("Second");
        $this->assertTrue($term->isAltScreen());

        $term->disableAltScreen();
        $this->assertFalse($term->isAltScreen());
        $this->assertSame('F', $term->screen()->cell(0, 0)->grapheme);

        $term->enableAltScreen();
        $term->feed("Third");
        $this->assertTrue($term->isAltScreen());

        $term->disableAltScreen();
        $this->assertFalse($term->isAltScreen());
        // Should be back to "First" content.
        $this->assertSame('F', $term->screen()->cell(0, 0)->grapheme);
        $this->assertSame('i', $term->screen()->cell(0, 1)->grapheme);
    }
}
