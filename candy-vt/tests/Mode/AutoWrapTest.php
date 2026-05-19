<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Parser\Parser;

/**
 * Tests for DECAWM (DEC Auto-Wrap Mode) — CSI ? 7 h enable, CSI ? 7 l disable.
 */
final class AutoWrapTest extends TestCase
{
    private function handler(int $cols = 20, int $rows = 5): ScreenHandler
    {
        return new ScreenHandler(new Buffer($cols, $rows));
    }

    private function feed(string $bytes, int $cols = 20, int $rows = 5): ScreenHandler
    {
        $h = $this->handler($cols, $rows);
        (new Parser($h))->feed($bytes);
        return $h;
    }

    // ─── Mode field & wither ────────────────────────────────────────────────

    public function testAutoWrapDefaultsToFalse(): void
    {
        $m = new Mode();
        $this->assertFalse($m->autoWrap);
    }

    public function testWithAutoWrapReturnsNewInstance(): void
    {
        $m = new Mode();
        $m2 = $m->withAutoWrap(true);
        $this->assertFalse($m->autoWrap);
        $this->assertTrue($m2->autoWrap);
    }

    public function testAutoWrapIncludedInEquals(): void
    {
        $a = (new Mode())->withAutoWrap(true);
        $b = new Mode();
        $this->assertFalse($a->equals($b));
        $this->assertTrue($a->equals($a));
        $this->assertTrue($b->equals($b));
    }

    // ─── CSI ? 7 h / l ─────────────────────────────────────────────────────

    public function testCsiQuestion7hEnablesAutoWrap(): void
    {
        $h = $this->handler();
        $this->assertFalse($h->mode->autoWrap);
        (new Parser($h))->feed("\x1b[?7h");
        $this->assertTrue($h->mode->autoWrap);
    }

    public function testCsiQuestion7lDisablesAutoWrap(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[?7h");
        $this->assertTrue($h->mode->autoWrap);
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);
    }

    public function testAutoWrapDisableIsIdempotent(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);
    }

    // ─── Print behaviour with auto-wrap OFF (default) ─────────────────────

    public function testAutoWrapOffClampOverwritesLastColumn(): void
    {
        // 4 cols, write 5 chars — last char 'E' overwrites col 3.
        $h = $this->feed('ABCDE', cols: 4);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame('C', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame('E', $h->buffer->cell(0, 3)->grapheme); // 'D' overwritten
        $this->assertSame(3, $h->cursor->col); // cursor clamped at last col
    }

    public function testAutoWrapOffCursorStaysAtLastColumn(): void
    {
        $h = $this->feed('ABCD', cols: 4);
        $this->assertSame(3, $h->cursor->col);
        // Next char would also overwrite col 3.
        (new Parser($h))->feed('X');
        $this->assertSame('X', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame(3, $h->cursor->col);
    }

    // ─── Print behaviour with auto-wrap ON ─────────────────────────────────

    public function testAutoWrapOnWrapsToNextLine(): void
    {
        // 4 cols, auto-wrap ON, write 5 chars.
        $h = $this->feed("\x1b[?7hABCDE", cols: 4);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame('C', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame('D', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame('E', $h->buffer->cell(1, 0)->grapheme); // wrapped to row 1, col 0
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col);
    }

    public function testAutoWrapOnMultipleLines(): void
    {
        // 4 cols, auto-wrap ON, write 9 chars → wraps at cols 4 and 8.
        $h = $this->feed("\x1b[?7hABCDEFGHI", cols: 4);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('D', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame('E', $h->buffer->cell(1, 0)->grapheme);
        $this->assertSame('H', $h->buffer->cell(1, 3)->grapheme);
        $this->assertSame('I', $h->buffer->cell(2, 0)->grapheme);
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col);
    }

    public function testAutoWrapOnFollowedByDisableStopsWrapping(): void
    {
        // Enable, write 4 chars (fills row) — wraps after D, cursor at (1, 0).
        $h = $this->feed("\x1b[?7hABCD", cols: 4);
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);

        // Disable auto-wrap.
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);

        // Write 'E' at current cursor (1, 0) — no wrap since auto-wrap is off.
        (new Parser($h))->feed('E');
        $this->assertSame('E', $h->buffer->cell(1, 0)->grapheme);
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col);
    }

    // ─── Wide char handling ────────────────────────────────────────────────

    public function testAutoWrapOnWideCharDoesNotCauseDoubleWrap(): void
    {
        // CJK wide char takes 2 cells. With 4 cols and auto-wrap ON,
        // 日 at col 0 fits (needs cols 0+1). Cursor advances by width=2.
        $h = $this->feed("\x1b[?7h" . "日", cols: 4);
        $this->assertSame('日', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(2, $h->cursor->col); // 2 cells used, cursor at col 2
    }

    public function testAutoWrapOnWideCharAtLastColumnWrap(): void
    {
        // Place cursor at col 3 (last col), auto-wrap ON, write CJK wide char.
        // Wide char doesn't fit at col 3, so wraps to next line where it fits.
        $h = $this->feed("\x1b[?7h\x1b[1;4H日", cols: 4);
        $this->assertSame('日', $h->buffer->cell(1, 0)->grapheme); // wrapped to row 1, col 0
        $this->assertSame(2, $h->cursor->col); // after 2-cell wide char
    }

    // ─── Interaction with scroll region ───────────────────────────────────

    public function testAutoWrapRespectsScrollRegion(): void
    {
        // 5 rows, scroll region rows 1-4 (1-indexed) = 0-3 (0-indexed).
        // Auto-wrap ON at col 3 (last col) of row 0 (inside scroll region).
        // A wraps to row 1 after being written at (0, 3).
        $h = $this->feed("\x1b[?7h\x1b[1;4r\x1b[1;4HABCD", cols: 4, rows: 5);
        $this->assertSame('A', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame('B', $h->buffer->cell(1, 0)->grapheme);
        $this->assertSame('D', $h->buffer->cell(1, 2)->grapheme);
        $this->assertSame(1, $h->cursor->row);
    }

    public function testAutoWrapAtBottomOfScrollRegionTriggersScroll(): void
    {
        // Scroll region rows 2-4 (1-indexed) = 1-3 (0-indexed).
        // Cursor at row 4 (1-indexed) = row 3 (0-indexed), col 1 (1-indexed) = col 0 (0-indexed).
        // A,B,C,D fill bottom of scroll region at row 3. E wraps and triggers scroll.
        $h = $this->feed("\x1b[?7h\x1b[2;4r\x1b[4;1HABCDE", cols: 4, rows: 5);
        // After scroll: row 2 gets A,B,C,D (shifted up), row 3 gets E, row 1 blank.
        $this->assertSame('A', $h->buffer->cell(2, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(2, 1)->grapheme);
        $this->assertSame('C', $h->buffer->cell(2, 2)->grapheme);
        $this->assertSame('D', $h->buffer->cell(2, 3)->grapheme);
        $this->assertSame('E', $h->buffer->cell(3, 0)->grapheme);
        $this->assertSame(' ', $h->buffer->cell(1, 0)->grapheme);
    }
}
