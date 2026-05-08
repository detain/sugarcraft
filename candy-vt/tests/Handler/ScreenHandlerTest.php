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

    public function testPrintsMultiByteUtf8AsSingleCell(): void
    {
        $h = $this->feed("日");
        $this->assertSame('日', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(1, $h->cursor->col);
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
}
