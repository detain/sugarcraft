<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Ansi\Parser\Parser;

/**
 * Tests for DECAWM (DEC Auto-Wrap Mode) — CSI ? 7 h enable, CSI ? 7 l reset.
 *
 * DECAWM powers on SET (VT100-and-up documented initial state) and the
 * wrap is DEFERRED: a glyph landing in the last column leaves the cursor
 * parked there with the phantom flag set; the advance happens when the
 * next graphic print arrives. Mirrors charmbracelet/x/vt Emulator.atPhantom.
 *
 * @see https://vt100.net/docs/vt510-rm/chapter4.html (DECAWM)
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

    public function testAutoWrapDefaultsToTrue(): void
    {
        // DEC documents DECAWM as one of the few modes ON at power-on;
        // a terminal starting with it off mis-renders every program that
        // relies on line wrap.
        $m = new Mode();
        $this->assertTrue($m->autoWrap);
    }

    public function testWithAutoWrapReturnsNewInstance(): void
    {
        $m = new Mode();
        $m2 = $m->withAutoWrap(false);
        $this->assertTrue($m->autoWrap);
        $this->assertFalse($m2->autoWrap);
    }

    public function testAutoWrapIncludedInEquals(): void
    {
        $a = (new Mode())->withAutoWrap(false);
        $b = new Mode();
        $this->assertFalse($a->equals($b));
        $this->assertTrue($a->equals($a));
        $this->assertTrue($b->equals($b));
    }

    // ─── CSI ? 7 h / l ─────────────────────────────────────────────────────

    public function testCsiQuestion7lDisablesAutoWrap(): void
    {
        $h = $this->handler();
        $this->assertTrue($h->mode->autoWrap);
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);
    }

    public function testCsiQuestion7hReEnablesAutoWrap(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);
        (new Parser($h))->feed("\x1b[?7h");
        $this->assertTrue($h->mode->autoWrap);
    }

    public function testAutoWrapEnableIsIdempotent(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[?7h");
        $this->assertTrue($h->mode->autoWrap);
        (new Parser($h))->feed("\x1b[?7h");
        $this->assertTrue($h->mode->autoWrap);
    }

    public function testAutoWrapDisableIsIdempotent(): void
    {
        $h = $this->handler();
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);
    }

    // ─── Print behaviour with auto-wrap OFF ─────────────────────────────────

    public function testAutoWrapOffClampOverwritesLastColumn(): void
    {
        // 4 cols, wrap disabled: write 5 chars — last char 'E' overwrites col 3.
        $h = $this->feed("\x1b[?7lABCDE", cols: 4);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame('C', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame('E', $h->buffer->cell(0, 3)->grapheme); // 'D' overwritten
        $this->assertSame(3, $h->cursor->col); // cursor clamped at last col
    }

    public function testAutoWrapOffCursorStaysAtLastColumn(): void
    {
        $h = $this->feed("\x1b[?7lABCD", cols: 4);
        $this->assertSame(3, $h->cursor->col);
        // Next char would also overwrite col 3.
        (new Parser($h))->feed('X');
        $this->assertSame('X', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame(3, $h->cursor->col);
    }

    // ─── Print behaviour with auto-wrap ON (deferred wrap) ─────────────────

    public function testAutoWrapOnWrapsToNextLine(): void
    {
        // 4 cols, write 5 chars with DECAWM on (power-on default).
        $h = $this->feed("\x1b[?7hABCDE", cols: 4);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame('B', $h->buffer->cell(0, 1)->grapheme);
        $this->assertSame('C', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame('D', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame('E', $h->buffer->cell(1, 0)->grapheme); // wrapped to row 1, col 0
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col);
    }

    public function testAutoWrapLastGlyphParksCursorInThePhantomCell(): void
    {
        // Writing exactly to the last column must NOT advance yet — the
        // glyph lands at col 3, the cursor stays there with the phantom
        // flag armed, and only the next graphic consumes the wrap.
        $h = $this->feed("\x1b[?7hABCD", cols: 4);
        $this->assertSame('D', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(3, $h->cursor->col);
        $this->assertTrue($h->wrapPending);
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
        // Write 4 chars (fills row) — with deferred wrap the cursor parks
        // at (0, 3) phantom-flagged instead of jumping to (1, 0).
        $h = $this->feed("\x1b[?7hABCD", cols: 4);
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(3, $h->cursor->col);
        $this->assertTrue($h->wrapPending);

        // Disable auto-wrap.
        (new Parser($h))->feed("\x1b[?7l");
        $this->assertFalse($h->mode->autoWrap);

        // With DECAWM off the phantom wrap is not consumed: 'E' overwrites
        // the last cell in place (xterm behavior with wrap disabled).
        (new Parser($h))->feed('E');
        $this->assertSame('E', $h->buffer->cell(0, 3)->grapheme);
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(3, $h->cursor->col);
    }

    public function testAutoWrapReEnabledMidWrapResumesDeferredAdvance(): void
    {
        // The phantom flag is geometry-set, not mode-gated (xterm DECAWM
        // toggle semantics): turning wrap off and back on while parked in
        // the last column must still wrap on the next print.
        $h = $this->feed("\x1b[?7hABCD", cols: 4);
        (new Parser($h))->feed("\x1b[?7l");
        (new Parser($h))->feed("\x1b[?7h");
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

    public function testWideCharEndingAtLastColumnArmsPhantom(): void
    {
        // A 2-wide glyph in cols 2-3 of a 4-col screen leaves the cursor
        // parked at col 3 with the phantom flag set.
        $h = $this->feed("\x1b[?7hAB日", cols: 4);
        $this->assertSame('日', $h->buffer->cell(0, 2)->grapheme);
        $this->assertSame(3, $h->cursor->col);
        $this->assertTrue($h->wrapPending);
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

    // ─── Phantom consumption by line operations ───────────────────────────

    public function testLineFeedWhilePhantomArmedMovesDownWithoutExtraWrap(): void
    {
        // LF from the phantom cell moves down one row and disarms the
        // flag — it must not double-advance. LF is vertical-only, so the
        // column survives (only NEL/CR home it), matching xterm index().
        $h = $this->feed("\x1b[?7hABCD\n", cols: 4);
        $this->assertFalse($h->wrapPending);
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(3, $h->cursor->col);

        // X lands on row 1 col 3 (column survived the LF) and re-arms
        // the phantom at the right edge, as any last-column glyph does.
        (new Parser($h))->feed('X');
        $this->assertSame('X', $h->buffer->cell(1, 3)->grapheme);
        $this->assertTrue($h->wrapPending);
    }

    public function testCarriageReturnDisarmsPhantom(): void
    {
        $h = $this->feed("\x1b[?7hABCD\rX", cols: 4);
        $this->assertSame('X', $h->buffer->cell(0, 0)->grapheme);
        $this->assertFalse($h->wrapPending);
    }
}
