<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Parser\Parser;
use SugarCraft\Vt\Sgr\Sgr;

final class ScreenHandlerTest extends TestCase
{
    private function feed(string $bytes, int $cols = 20, int $rows = 5): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    // ─── Print behaviour ───────────────────────────────────────────────────

    public function testPrintsAtCursorAndAdvances(): void
    {
        $h = $this->feed('Hi');
        $this->assertSame('H', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('i', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame(2, $h->cursor->col);
    }

    public function testPrintsWideCjkAsTwoCells(): void
    {
        $h = $this->feed("日");
        $this->assertSame('日', $h->buffer->cell(0, 0)->grapheme);
        $this->assertTrue($h->buffer->cell(0, 1)->continuation);
        $this->assertSame(2, $h->cursor->col);
    }

    public function testCursorClampsAtRightEdgeWithoutWrap(): void
    {
        $h = $this->feed('ABCDEF', cols: 4);
        // 'A','B','C','D' fill cols 0-3; 'E' overwrites col 3 (clamp); 'F' overwrites col 3.
        $this->assertSame('F', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame(3, $h->cursor->col);
    }

    // ─── C0 controls ───────────────────────────────────────────────────────

    public function testBackspaceMovesCursorLeft(): void
    {
        $h = $this->feed("AB\x08");
        $this->assertSame(1, $h->cursor->col);
    }

    public function testBackspaceClampsAtZero(): void
    {
        $h = $this->feed("\x08");
        $this->assertSame(0, $h->cursor->col);
    }

    public function testCarriageReturnSendsCursorToColZero(): void
    {
        $h = $this->feed("ABC\x0D");
        $this->assertSame(0, $h->cursor->col);
    }

    public function testLinefeedAdvancesRow(): void
    {
        $h = $this->feed("\x0A");
        $this->assertSame(1, $h->cursor->row);
    }

    public function testHorizontalTabMovesToNextEightBoundary(): void
    {
        $h = $this->feed("AB\x09");
        $this->assertSame(8, $h->cursor->col);
    }

    public function testHorizontalTabFromBoundaryAdvancesByEight(): void
    {
        $h = $this->feed("\x09\x09");
        $this->assertSame(16, $h->cursor->col);
    }

    public function testHorizontalTabClampsAtRightEdge(): void
    {
        $h = $this->feed("\x09", cols: 5);
        $this->assertSame(4, $h->cursor->col);
    }

    // ─── SGR through CSI dispatch ──────────────────────────────────────────

    public function testCsiMUpdatesPenAndPaintsCells(): void
    {
        $h = $this->feed("\x1b[1;31mAB\x1b[0mC");
        $this->assertTrue($h->buffer->cell(0, 0)->sgr->bold);
        $this->assertSame(1, $h->buffer->cell(0, 0)->sgr->foreground->value);
        // After CSI 0 m the pen resets, so 'C' has no fg/bold.
        $this->assertFalse($h->buffer->cell(0, 2)->sgr->bold);
    }

    // ─── Cursor moves through CSI dispatch ─────────────────────────────────

    public function testCsiHMovesCursor(): void
    {
        $h = $this->feed("\x1b[3;5H");
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(4, $h->cursor->col);
    }

    public function testCursorMovesThenWritesAtNewPosition(): void
    {
        $h = $this->feed("\x1b[2;3HX");
        $this->assertSame('X', $h->buffer->cell(1, 2)->grapheme);
    }

    // ─── DEC mode 25 (cursor visibility) ────────────────────────────────────

    public function testDecMode25HideShowCursor(): void
    {
        $h = $this->feed("\x1b[?25l");
        $this->assertFalse($h->cursor->visible);
        $this->assertFalse($h->mode->cursorVisible);

        // Reset and re-show.
        $p = new Parser($h);
        $p->feed("\x1b[?25h");
        $this->assertTrue($h->cursor->visible);
        $this->assertTrue($h->mode->cursorVisible);
    }

    public function testNonQuestionPrefixedHIgnored(): void
    {
        // Standard mode (not DEC private) — currently no-op in PR3.
        $h = $this->feed("\x1b[20h");
        $this->assertTrue($h->cursor->visible); // unaffected
    }

    // ─── ESC dispatch — DECSC / DECRC ──────────────────────────────────────

    public function testEsc7SavesEsc8Restores(): void
    {
        $h = $this->feed("\x1b[3;5H\x1b7\x1b[1;1H\x1b8");
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(4, $h->cursor->col);
    }

    public function testEsc8WithNoSaveIsNoOp(): void
    {
        $h = $this->feed("\x1b[3;5H\x1b8");
        // No prior save — restore returns the same row/col.
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(4, $h->cursor->col);
    }

    // ─── Direct sub-handler injection round-trip ───────────────────────────

    public function testHandlerIsClonable(): void
    {
        $orig = new ScreenHandler(new Buffer(5, 5));
        $orig->cursor = new Cursor(row: 2, col: 3);
        $clone = clone $orig;
        $clone->cursor = new Cursor(row: 0, col: 0);
        $this->assertSame(2, $orig->cursor->row);
        $this->assertSame(0, $clone->cursor->row);
    }

    // ─── Linefeed scrolling at bottom (PR4) ───────────────────────────────

    public function testLinefeedAtBottomScrollsAndStays(): void
    {
        // Place 'AB' at row 0, 'CD' at row 1 via explicit moves, then LF from
        // bottom row to trigger scroll. Cursor stays at last row; previous
        // row 1 contents move up to row 0.
        $h = $this->feed("\x1b[1;1HAB\x1b[2;1HCD\x0A", cols: 3, rows: 2);
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame('C', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('D', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(1, 0)->grapheme);
    }

    // ─── IND / RI / NEL via ESC dispatch (PR4) ─────────────────────────────

    public function testEscDIsIndex(): void
    {
        // Move to row 1, then ESC D => row 2.
        $h = $this->feed("\x1b[2;1H\x1bD", cols: 5, rows: 5);
        $this->assertSame(2, $h->cursor->row);
    }

    public function testEscMIsReverseIndex(): void
    {
        // ESC M from row 1 → row 0.
        $h = $this->feed("\x1b[2;3H\x1bM", cols: 5, rows: 5);
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(2, $h->cursor->col);
    }

    public function testEscEIsNextLine(): void
    {
        $h = $this->feed("\x1b[2;3H\x1bE", cols: 5, rows: 5);
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
    }

    // ─── Erase via CSI dispatch ────────────────────────────────────────────

    public function testCsiKErasesLineToEnd(): void
    {
        $h = $this->feed("ABCDE\x1b[3G\x1b[K", cols: 5, rows: 1);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(0, 4)->grapheme);
    }

    public function testCsiJ2ErasesEntireScreen(): void
    {
        $h = $this->feed("ABC\x0AXYZ\x1b[2J", cols: 3, rows: 2);
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(1, 0)->grapheme);
    }

    // ─── Scroll via CSI dispatch ───────────────────────────────────────────

    public function testCsiSScrollsUp(): void
    {
        $h = $this->feed("\x1b[1;1HABC\x1b[2;1HDEF\x1b[1S", cols: 3, rows: 2);
        // After scroll up by 1: previous row 1 ('DEF') moves to row 0; row 1 blank.
        $this->assertSame('D', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('E', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame('F', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(1, 0)->grapheme);
    }

    public function testCsiTScrollsDown(): void
    {
        $h = $this->feed("\x1b[1;1HABC\x1b[2;1HDEF\x1b[1T", cols: 3, rows: 2);
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('A', $h->buffer->cell(1, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(1, 1)->grapheme);
    }

    // ─── DCH / ICH ─────────────────────────────────────────────────────────

    public function testCsiPDeletesChars(): void
    {
        $h = $this->feed("ABCDEF\x1b[3G\x1b[2P", cols: 6, rows: 1);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame('E', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame('F', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(0, 4)->grapheme);
    }

    public function testCsiAtSignInsertsBlanks(): void
    {
        $h = $this->feed("ABCDEF\x1b[3G\x1b[2@", cols: 6, rows: 1);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame('C', $h->buffer->cell(0, 4)->grapheme);
        $this->assertSame('D', $h->buffer->cell(0, 5)->grapheme);
    }
}
