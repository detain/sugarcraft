<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Sgr\UnderlineStyle;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * Interaction matrix for the DECAWM deferred-wrap ("phantom") state.
 *
 * Pins which operations consume the pending wrap and — equally
 * important — which deliberately do not. Roster mirrors
 * charmbracelet/x/vt (`atPhantom` cleared by index/CR/movement/ECH/RIS/
 * Resize, preserved by RI, tabs, ED/EL and reports) and xterm's
 * `_wrapnext` handling.
 *
 * @see https://vt100.net/docs/vt510-rm/chapter4.html (DECAWM)
 */
final class DeferredWrapInteractionTest extends TestCase
{
    private function handler(string $bytes, int $cols = 4, int $rows = 5): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    /** Park the cursor in the phantom cell: 'ABCD' on a 4-col screen. */
    private function phantom(): ScreenHandler
    {
        $h = $this->handler("\x1b[?7hABCD");
        $this->assertTrue($h->wrapPending, 'precondition: glyph in last col arms deferred wrap');
        return $h;
    }

    // ─── Cursor position report reads, never disturbs, the phantom cell ───

    public function testCprReportsPhantomColumnAndKeepsWrapPending(): void
    {
        $t = Terminal::new(4, 5);
        $t->feed("\x1b[?7hABCD\x1b[6n");
        // xterm answers the position the cursor VISIBLY occupies — the
        // last column, not the wrapped one it owes.
        $this->assertSame(["\x1b[1;4R"], $t->replies());
        $this->assertTrue($t->isWrapPending());

        // …and the deferred wrap still fires on the next graphic.
        $t->feed('E');
        $this->assertSame('E', $t->screen()->cell(1, 0)->grapheme);
    }

    // ─── ED / EL leave the flag alone (xterm: erases never touch _wrapnext) ──

    public function testEraseInLineKeepsWrapPending(): void
    {
        $h = $this->phantom();
        (new Parser($h))->feed("\x1b[K");
        $this->assertTrue($h->wrapPending);
        (new Parser($h))->feed('X');
        $this->assertSame('X', $h->buffer->cell(1, 0)->grapheme);
    }

    public function testEraseInDisplayKeepsWrapPending(): void
    {
        $h = $this->phantom();
        (new Parser($h))->feed("\x1b[2J");
        $this->assertTrue($h->wrapPending);
        // Screen blanked, phantom survives → X lands on row 1 col 0.
        (new Parser($h))->feed('X');
        $this->assertSame('X', $h->buffer->cell(1, 0)->grapheme);
    }

    public function testEchDisarmsWrapPending(): void
    {
        // ECH erases starting AT the phantom cell — charmbracelet
        // eraseCharacter() resets the flag (the cell it owes is gone).
        $h = $this->phantom();
        (new Parser($h))->feed("\x1b[X");
        $this->assertFalse($h->wrapPending);
    }

    // ─── Back-index (RI) preserves; vertical LF/IND consumes ────────────────

    public function testReverseIndexKeepsWrapPending(): void
    {
        // charmbracelet x/vt reverseIndex(): "This does not reset the
        // phantom state." RI moves the cursor up in place; the owed wrap
        // survives and still fires on the next graphic print.
        $h = $this->handler("\x1b[?7h", cols: 4, rows: 5);
        (new Parser($h))->feed("\x1b[3;1HABCD"); // phantom on row idx 2
        $this->assertTrue($h->wrapPending);
        $this->assertSame(2, $h->cursor->row);
        (new Parser($h))->feed("\x1bM"); // ESC M = RI
        $this->assertSame(1, $h->cursor->row);
        $this->assertTrue($h->wrapPending);
        (new Parser($h))->feed('X');
        // The deferred wrap advances from the CURRENT row (1) after RI —
        // exactly like xterm, whose _wrapnext ignores which row armed it.
        $this->assertSame('X', $h->buffer->cell(2, 0)->grapheme);
    }

    // ─── Tabs preserve the phantom flag (upstream-verbatim) ────────────────

    public function testTabKeepsWrapPending(): void
    {
        // charmbracelet x/vt nextTab(): "we use t.scr.setCursor here
        // because we don't want to reset the phantom state".
        $h = $this->phantom();
        (new Parser($h))->feed("\x09");
        $this->assertTrue($h->wrapPending);

        // And the phantom is still ALIVE: the next graphic resolves the
        // owed wrap from wherever the tab parked the cursor.
        (new Parser($h))->feed('X');
        $this->assertSame('X', $h->buffer->cell(1, 0)->grapheme);
    }

    // ─── Positioning ops consume it ────────────────────────────────────────

    public function testCupDisarmsWrapPending(): void
    {
        $h = $this->phantom();
        (new Parser($h))->feed("\x1b[1;2H");
        $this->assertFalse($h->wrapPending);
        (new Parser($h))->feed('X');
        $this->assertSame('X', $h->buffer->cell(0, 1)->grapheme);
    }

    public function testCharMovementDisarmsWrapPending(): void
    {
        foreach (["\x1b[C", "\x1b[D", "\x1b[A", "\x1b[B"] as $move) {
            $h = $this->phantom();
            (new Parser($h))->feed($move);
            $this->assertFalse($h->wrapPending, "movement {$move} must consume the phantom cell");
        }
    }

    public function testBackspaceDisarmsWrapPending(): void
    {
        $h = $this->phantom();
        (new Parser($h))->feed("\x08");
        $this->assertFalse($h->wrapPending);
    }

    public function testDecscKeepsAndDecrcDisarmsWrapPending(): void
    {
        // DECSC only snapshots the position — the phantom survives;
        // DECRC repositions the cursor → it is consumed.
        $h = $this->phantom();
        (new Parser($h))->feed("\x1b7");
        $this->assertTrue($h->wrapPending);
        (new Parser($h))->feed("\x1b[1;2H");
        $this->assertFalse($h->wrapPending);
        (new Parser($h))->feed("\x1b8");
        $this->assertFalse($h->wrapPending);
    }

    // ─── The phantom glyph is the combinator host cell ─────────────────────

    public function testCombiningMarkAttachesToPhantomGlyph(): void
    {
        // With the cursor parked ON the just-written last-column glyph,
        // a combining mark must attach to that cell, not the one before.
        $h = $this->handler("\x1b[?7hABC" . "\u{0301}", cols: 3, rows: 3);
        $this->assertSame('C', $h->buffer->cell(0, 2)->grapheme);
        $this->assertStringContainsString("\u{0301}", $h->buffer->cell(0, 2)->combining);
        $this->assertSame('', $h->buffer->cell(0, 1)->combining);
    }

    public function testCloneReattachesSubparamsProviderToOwnParser(): void
    {
        // Emulator terminals are cloned by the with*() builders; the cloned
        // handler must read colon continuation flags from the CLONE's own
        // parser. Left bound to the original it serves stale flags (a prior
        // `4:3` on the original leaking into the clone's `4;3`) or an empty
        // list (a fresh original making the clone read `4:3` as `4;3`).
        $a = Terminal::new(8, 6);
        $a->feed("\x1b[4:3mA");
        $b = clone $a;
        $b->feed("\x1b[2;2H\x1b[0m\x1b[4;3mB");
        $sgr = $b->screen()->cell(1, 1)->sgr();
        $this->assertTrue($sgr->italic, 'stale colon flag must not swallow the independent `3`');

        $fresh = clone Terminal::new(8, 6);
        $fresh->feed("\x1b[3;3H\x1b[4:3mC");
        $sgr2 = $fresh->screen()->cell(2, 2)->sgr();
        $this->assertFalse($sgr2->italic, 'empty flag list must not degrade `4:3` to `4;3`');
        $this->assertSame(UnderlineStyle::Curly, $sgr2->underlineStyle);
    }

    // ─── Resize resolves the phantom cell ─────────────────────────────────

    public function testWideningResizeResolvesPhantomPosition(): void
    {
        // charmbracelet x/vt Emulator.Resize: a phantom cell that is no
        // longer at the (new) right edge becomes a plain in-bounds
        // position — the next print continues on the SAME row.
        $t = Terminal::new(4, 5);
        $t->feed("\x1b[?7hABCD");
        $t->resize(8, 5);
        $t->feed('E');
        $this->assertSame('E', $t->screen()->cell(0, 4)->grapheme);
        $this->assertSame(0, $t->cursor()->row);
        $this->assertSame(5, $t->cursor()->col);
    }

    public function testNarrowingResizeClampsCursorIntoGrid(): void
    {
        $t = Terminal::new(10, 5);
        $t->feed("\x1b[?7l0123456789");
        $t->resize(4, 5);
        $this->assertSame(3, $t->cursor()->col);
    }
}
