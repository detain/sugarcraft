<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\ModeHandler;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Sgr\Sgr;

final class ModeHandlerTest extends TestCase
{
    private function newHandler(int $cols = 10, int $rows = 5): ScreenHandler
    {
        return new ScreenHandler(new Buffer($cols, $rows));
    }

    public function testCursorVisibleSetAndReset(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([25], false, $h);
        $this->assertFalse($h->mode->cursorVisible);
        $this->assertFalse($h->cursor->visible);
        (new ModeHandler())->apply([25], true, $h);
        $this->assertTrue($h->mode->cursorVisible);
        $this->assertTrue($h->cursor->visible);
    }

    public function testMouseAny1000(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([1000], true, $h);
        $this->assertTrue($h->mode->mouseAny);
        (new ModeHandler())->apply([1000], false, $h);
        $this->assertFalse($h->mode->mouseAny);
    }

    public function testMouseHighlights1001(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([1001], true, $h);
        $this->assertTrue($h->mode->mouseHighlights);
        (new ModeHandler())->apply([1001], false, $h);
        $this->assertFalse($h->mode->mouseHighlights);
    }

    public function testMouseHighlights1005(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([1005], true, $h);
        $this->assertTrue($h->mode->mouseHighlights);
    }

    public function testMouseHighlights1015(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([1015], true, $h);
        $this->assertTrue($h->mode->mouseHighlights);
    }

    public function testMouseCellMotion1002(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([1002], true, $h);
        $this->assertTrue($h->mode->mouseCellMotion);
    }

    public function testMouseExtended1003(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([1003], true, $h);
        $this->assertTrue($h->mode->mouseExtended);
    }

    public function testMouseSgr1006(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([1006], true, $h);
        $this->assertTrue($h->mode->mouseSgr);
    }

    public function testBracketedPaste2004(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([2004], true, $h);
        $this->assertTrue($h->mode->bracketedPaste);
    }

    public function testSyncUpdate2026(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([2026], true, $h);
        $this->assertTrue($h->mode->syncUpdate);
    }

    public function testMultipleParamsHandledIndependently(): void
    {
        $h = $this->newHandler();
        (new ModeHandler())->apply([1000, 1006, 2004], true, $h);
        $this->assertTrue($h->mode->mouseAny);
        $this->assertTrue($h->mode->mouseSgr);
        $this->assertTrue($h->mode->bracketedPaste);
    }

    public function testUnknownModeIgnored(): void
    {
        $h = $this->newHandler();
        $before = $h->mode;
        (new ModeHandler())->apply([9999], true, $h);
        $this->assertTrue($h->mode->equals($before));
    }

    public function testZeroAndDefaultParamsSkipped(): void
    {
        $h = $this->newHandler();
        $before = $h->mode;
        (new ModeHandler())->apply([0, -1], true, $h);
        $this->assertTrue($h->mode->equals($before));
    }

    // ─── Alt screen (1049) ─────────────────────────────────────────────────

    public function testAltScreenEnterSavesBufferAndCursor(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $h->cursor = new Cursor(row: 1, col: 2);
        $h->sgr = Sgr::empty()->withBold(true);

        (new ModeHandler())->apply([1049], true, $h);

        $this->assertTrue($h->mode->altScreen);
        // Active buffer is fresh and blank.
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
        $this->assertFalse($h->sgr->bold);
    }

    public function testAltScreenLeaveRestoresBufferCursorAndSgr(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $h->cursor = new Cursor(row: 1, col: 2);
        $h->sgr = Sgr::empty()->withItalic(true);

        $mh = new ModeHandler();
        $mh->apply([1049], true, $h);
        // Mutate alt screen to confirm it isn't bleeding back.
        $h->buffer->put(0, 0, new Cell(grapheme: 'Z'));
        $h->cursor = new Cursor(row: 2, col: 4);
        $h->sgr = Sgr::empty()->withReverse(true);

        $mh->apply([1049], false, $h);

        $this->assertFalse($h->mode->altScreen);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(2, $h->cursor->col);
        $this->assertTrue($h->sgr->italic);
        $this->assertFalse($h->sgr->reverse);
    }

    public function testAltScreenReentryIsNoOp(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));

        $mh = new ModeHandler();
        $mh->apply([1049], true, $h);
        // Now in alt mode. Write 'X' into alt buffer, then re-set 1049.
        $h->buffer->put(0, 0, new Cell(grapheme: 'X'));
        $mh->apply([1049], true, $h);

        // Alt buffer should still have 'X' — re-entry didn't clobber it.
        $this->assertSame('X', $h->buffer->cell(0, 0)->grapheme);

        // Leaving once still gets back to the original 'A'.
        $mh->apply([1049], false, $h);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
    }

    public function testAltScreenLeaveWithoutEnterIsNoOp(): void
    {
        $h = $this->newHandler();
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $before = $h->mode;
        (new ModeHandler())->apply([1049], false, $h);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        $this->assertTrue($h->mode->equals($before));
    }

    /**
     * DECSTBM is per-screen state: entering the alt screen homes the region
     * to the new screen's full height (the main region must not be inherited
     * — htop/vim-style programs issue their own margins on entry and a
     * carried-over [2,4] silently clips their whole-screen scrolls), and
     * margins set inside alt are discarded on exit instead of leaking back
     * into main.
     */
    public function testAltScreenSwapIsolatesDecstbmRegionBothDirections(): void
    {
        $h = $this->newHandler(cols: 5, rows: 5);
        $h->csiDispatch(ord('r'), [2, 4], 0, 0); // main region rows 2-4
        $this->assertSame(1, $h->scrollRegionTop);
        $this->assertSame(3, $h->scrollRegionBottom);

        $mh = new ModeHandler();
        $mh->apply([1049], true, $h);
        $this->assertSame(0, $h->scrollRegionTop, 'alt screen starts with a full-height region');
        $this->assertSame(4, $h->scrollRegionBottom);

        $h->csiDispatch(ord('r'), [1, 2], 0, 0); // DECSTBM inside alt
        $mh->apply([1049], false, $h);
        $this->assertSame(1, $h->scrollRegionTop, "alt-side margins must not leak back to main");
        $this->assertSame(3, $h->scrollRegionBottom);
    }

    /**
     * `?1049h` arriving while 1048 already holds the alt screen is NOT a
     * no-op: 1049 = save cursor + clear + switch to alt, so the upgrade
     * takes the full-swap semantics (fresh blank, homed cursor, SGR reset),
     * and `?1049l` unwinds to the state at upgrade time. The pre-fix guard
     * dropped the sequence entirely, leaving apps that open with 1048h then
     * re-issue 1049h stranded without their clean screen.
     */
    public function testMode1049WhileAlt1048EngagesFullAltAndClear(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $h->cursor = new Cursor(row: 1, col: 2);

        $mh = new ModeHandler();
        $mh->apply([1048], true, $h);              // cursor-only alt
        $h->buffer->put(0, 0, new Cell(grapheme: 'Z'));
        $h->cursor = new Cursor(row: 2, col: 4);
        $h->sgr = Sgr::empty()->withBold(true);

        $mh->apply([1049], true, $h);              // upgrade to full alt
        $this->assertSame(Mode::ALT_FULL, $h->mode->altScreenVariant);
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme, 'the upgrade must clear to a fresh screen');
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
        $this->assertFalse($h->sgr->bold, '1049 enter resets SGR');

        $mh->apply([1049], false, $h);             // unwind to upgrade-time state
        $this->assertFalse($h->mode->isAltScreen());
        $this->assertSame('Z', $h->buffer->cell(0, 0)->grapheme);
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(4, $h->cursor->col);
    }

    /**
     * The upgrade must not double-park the general slot's rendition
     * companions: 1048 parked them once, and a second blind park at the
     * 1049 entry would overwrite that park with the (already nulled) slot
     * and `CSI u` would come back without the saved SGR. (The DECSC
     * POSITION half rides the Cursor value object in this port and the
     * single saved-cursor slot is necessarily replaced by the upgrade —
     * position fidelity across a 1048→1049 nest is NOT promised here,
     * only the companions.)
     */
    public function testUpgradeFrom1048PreservesGeneralCompanions(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->sgr = Sgr::empty()->withBold(true);
        $h->cursor = new Cursor(row: 1, col: 2);
        $h->csiDispatch(ord('s'), [], 0, 0);       // general slot ← bold pen
        $h->cursor = $h->cursor->withRow(0);       // move preserving the saved pair
        $h->sgr = Sgr::empty();

        $mh = new ModeHandler();
        $mh->apply([1048], true, $h);              // parks {bold} once
        $h->sgr = Sgr::empty()->withUnderline(true);
        $h->cursor = $h->cursor->withRow(2)->withCol(4);
        $mh->apply([1049], true, $h);              // upgrade — must NOT re-park
        $this->assertFalse($h->sgr->underline, '1049 enter resets SGR');
        $mh->apply([1049], false, $h);             // unpark must hand {bold} back

        $h->csiDispatch(ord('u'), [], 0, 0);       // DECRC restores the companions
        $this->assertTrue($h->sgr->bold, 'parked SGR companion must survive the upgrade');
        $this->assertFalse($h->sgr->underline);
    }

    // ─── Alt screen modes 47 and 1047 (no save) ─────────────────────────────

    public function testAltScreenMode47NoSaveEnterSavesBufferOnly(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $h->cursor = new Cursor(row: 1, col: 2);
        $h->sgr = Sgr::empty()->withBold(true);

        (new ModeHandler())->apply([47], true, $h);

        $this->assertTrue($h->mode->isAltScreen());
        $this->assertSame(Mode::ALT_NO_SAVE, $h->mode->altScreenVariant);
        // Active buffer is fresh and blank.
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
        // Cursor and SGR are NOT saved (not reset).
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(2, $h->cursor->col);
        $this->assertTrue($h->sgr->bold);
    }

    public function testAltScreenMode47NoSaveLeaveRestoresBufferOnly(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $h->cursor = new Cursor(row: 1, col: 2);
        $h->sgr = Sgr::empty()->withItalic(true);

        $mh = new ModeHandler();
        $mh->apply([47], true, $h);
        // Mutate alt screen.
        $h->buffer->put(0, 0, new Cell(grapheme: 'Z'));
        $h->cursor = new Cursor(row: 2, col: 4);
        $h->sgr = Sgr::empty()->withReverse(true);

        $mh->apply([47], false, $h);

        $this->assertFalse($h->mode->isAltScreen());
        // Buffer is restored.
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
        // Cursor is NOT restored (wasn't saved).
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(4, $h->cursor->col);
        // SGR is NOT restored (wasn't saved).
        $this->assertTrue($h->sgr->reverse);
        $this->assertFalse($h->sgr->italic);
    }

    public function testAltScreenMode1047NoSaveSameAsMode47(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));

        $mh = new ModeHandler();
        $mh->apply([1047], true, $h);

        $this->assertTrue($h->mode->isAltScreen());
        $this->assertSame(Mode::ALT_NO_SAVE, $h->mode->altScreenVariant);
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);

        $mh->apply([1047], false, $h);
        $this->assertFalse($h->mode->isAltScreen());
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
    }

    public function testAltScreenMode47ReentryIsNoOp(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));

        $mh = new ModeHandler();
        $mh->apply([47], true, $h);
        // Write 'X' into alt buffer, then re-set 47.
        $h->buffer->put(0, 0, new Cell(grapheme: 'X'));
        $mh->apply([47], true, $h);

        // Alt buffer should still have 'X'.
        $this->assertSame('X', $h->buffer->cell(0, 0)->grapheme);

        // Leaving once gets back to original 'A'.
        $mh->apply([47], false, $h);
        $this->assertSame('A', $h->buffer->cell(0, 0)->grapheme);
    }

    // ─── Alt screen mode 1048 (cursor save only) ────────────────────────────

    public function testAltScreenMode1048CursorOnlyEnterSavesCursorOnly(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $h->cursor = new Cursor(row: 1, col: 2);
        $h->sgr = Sgr::empty()->withBold(true);

        (new ModeHandler())->apply([1048], true, $h);

        $this->assertTrue($h->mode->isAltScreen());
        $this->assertSame(Mode::ALT_CURSOR_ONLY, $h->mode->altScreenVariant);
        // Active buffer is fresh and blank.
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme);
        // Cursor is reset in new buffer.
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
        // SGR is NOT reset (wasn't saved).
        $this->assertTrue($h->sgr->bold);
    }

    public function testAltScreenMode1048CursorOnlyLeaveRestoresCursorOnly(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $h->cursor = new Cursor(row: 1, col: 2);
        $h->sgr = Sgr::empty()->withItalic(true);

        $mh = new ModeHandler();
        $mh->apply([1048], true, $h);
        // Mutate alt screen.
        $h->buffer->put(0, 0, new Cell(grapheme: 'Z'));
        $h->cursor = new Cursor(row: 2, col: 4);
        $h->sgr = Sgr::empty()->withReverse(true);

        $mh->apply([1048], false, $h);

        $this->assertFalse($h->mode->isAltScreen());
        // Buffer is NOT restored (wasn't saved) — still has 'Z'.
        $this->assertSame('Z', $h->buffer->cell(0, 0)->grapheme);
        // Cursor IS restored.
        $this->assertSame(1, $h->cursor->row);
        $this->assertSame(2, $h->cursor->col);
        // SGR is NOT restored (wasn't saved).
        $this->assertTrue($h->sgr->reverse);
        $this->assertFalse($h->sgr->italic);
    }

    public function testAltScreenMode1048ReentryIsNoOp(): void
    {
        $h = $this->newHandler(cols: 5, rows: 3);
        $h->buffer->put(0, 0, new Cell(grapheme: 'A'));
        $h->cursor = new Cursor(row: 0, col: 0);

        $mh = new ModeHandler();
        $mh->apply([1048], true, $h);
        // Write 'X' into alt buffer, then re-set 1048.
        $h->buffer->put(0, 0, new Cell(grapheme: 'X'));
        $h->cursor = new Cursor(row: 2, col: 4);
        $mh->apply([1048], true, $h);

        // Alt buffer should still have 'X'.
        $this->assertSame('X', $h->buffer->cell(0, 0)->grapheme);
        // Cursor should still be 2,4.
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(4, $h->cursor->col);
    }
}
