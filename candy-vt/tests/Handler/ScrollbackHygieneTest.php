<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * Scrollback is the history of what scrolled off the TOP OF THE SCREEN.
 * Any motion whose effect stays inside a strict DECSTBM sub-region — SU,
 * SD, LF/IND at the region bottom, RI at the region top, DL — must leave
 * the ring empty; only full-screen geometry feeds it. ED 3 (`CSI 3 J`)
 * drains the ring without disturbing the visible screen or cursor.
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (CSI Ps J)
 */
final class ScrollbackHygieneTest extends TestCase
{
    private function feed(string $bytes, int $cols = 4, int $rows = 6): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    /** Six distinct marker rows — one letter each, row index encoded. */
    private const FILL = "aaaa\r\nbbbb\r\ncccc\r\ndddd\r\neeee\r\nffff";

    private function rowGraphemes(ScreenHandler $h, int $row): string
    {
        $s = '';
        for ($c = 0; $c < $h->buffer->cols; $c++) {
            $s .= $h->buffer->cell($row, $c)->grapheme;
        }
        return $s;
    }

    /** Render one stored scrollback row (a list of Cells) as plain text. */
    private function rowText(array $row): string
    {
        return implode('', array_map(static fn (Cell $c): string => $c->grapheme, $row));
    }

    // ─── Positive control: full-screen scroll still feeds the ring ──────────

    public function testFullScreenLfScrollFeedsScrollback(): void
    {
        // rows=6: seven LFs' worth of prints push row "aaaa" off the top.
        $h = $this->feed(self::FILL . "\r\ngggg");
        $this->assertSame(0, $h->scrollRegionTop);
        $this->assertSame(5, $h->scrollRegionBottom);
        $this->assertSame(1, $h->scrollback->count(), 'full-screen scroll must feed history');
        $this->assertSame('aaaa', $this->rowText($h->scrollback->at(0)));
    }

    public function testFullScreenSuFeedsScrollback(): void
    {
        $h = $this->feed(self::FILL . "\x1b[2S");
        $this->assertSame(2, $h->scrollback->count(), 'full-screen SU feeds history');
        $this->assertSame('aaaa', $this->rowText($h->scrollback->at(0)));
        $this->assertSame('bbbb', $this->rowText($h->scrollback->at(1)));
    }

    // ─── Sub-region motion must NOT pollute the ring ────────────────────────

    public function testLfAtSubRegionBottomDoesNotFeedScrollback(): void
    {
        // Region rows 1..4 of a 6-row screen; LF chain inside the region
        // scrolls only rows 1..4 — nothing crosses the screen top.
        $h = $this->feed(self::FILL . "\x1b[2;5r\x1b[5;1H\r\n\r\n\r\n");
        $this->assertSame(1, $h->scrollRegionTop);
        $this->assertSame(4, $h->scrollRegionBottom);
        $this->assertSame(0, $h->scrollback->count(), 'sub-region LF must not touch history');
        $this->assertSame('aaaa', $this->rowGraphemes($h, 0), 'row above the region untouched');
    }

    public function testRiAtSubRegionTopDoesNotFeedScrollback(): void
    {
        $h = $this->feed(self::FILL . "\x1b[2;5r\x1b[2;1H\x1bM\x1bM");
        $this->assertSame(0, $h->scrollback->count(), 'reverse-index inside a sub-region must not feed history');
    }

    public function testSuInSubRegionDoesNotFeedScrollback(): void
    {
        $h = $this->feed(self::FILL . "\x1b[2;5r\x1b[2S");
        $this->assertSame(0, $h->scrollback->count(), 'SU inside a sub-region must not feed history');
        $this->assertSame('aaaa', $this->rowGraphemes($h, 0), 'line above region intact');
    }

    public function testSdInSubRegionDoesNotFeedScrollback(): void
    {
        $h = $this->feed(self::FILL . "\x1b[2;5r\x1b[3T");
        $this->assertSame(0, $h->scrollback->count(), 'SD inside a sub-region must not feed history');
    }

    public function testBottomTruncatedRegionDoesNotFeedScrollback(): void
    {
        // top == 0 but bottom short of the last row is still a sub-region:
        // rows scrolled "off" its bottom vanish into blanks, they never
        // reach the screen top either — no history.
        $h = $this->feed(self::FILL . "\x1b[1;4r\x1b[4;1H\r\n\r\n");
        $this->assertSame(0, $h->scrollRegionTop);
        $this->assertSame(3, $h->scrollRegionBottom);
        $this->assertSame(0, $h->scrollback->count(), 'truncated-height region must not feed history');
    }

    public function testDlInSubRegionTopDoesNotFeedScrollback(): void
    {
        // DL at the top of a region that starts below row 0: the full-width
        // charm PushN guard additionally requires the region to span the
        // whole screen (sub-region content never crossed the screen edge).
        $h = $this->feed(self::FILL . "\x1b[2;6r\x1b[2;1H\x1b[2M");
        $this->assertSame(0, $h->scrollback->count(), 'sub-region DL must not feed history');
    }

    // ─── ED 3 — clear scrollback (CSI 3 J) ──────────────────────────────────

    public function testEdMode3ClearsScrollbackOnly(): void
    {
        // Fill history (full-screen scroll), scribble the screen, park the
        // cursor mid-page, then CSI 3 J: ring drains; screen + cursor stay.
        $h = $this->feed(self::FILL . "\r\ngggg\x1b[3;2H");
        $this->assertGreaterThan(0, $h->scrollback->count(), 'precondition: history present');
        $screenBefore = $this->rowGraphemes($h, 0);

        (new Parser($h))->feed("\x1b[3J");

        $this->assertSame(0, $h->scrollback->count(), 'ED 3 drains the ring');
        $this->assertSame($screenBefore, $this->rowGraphemes($h, 0), 'visible screen untouched');
        $this->assertSame(2, $h->cursor->row, 'cursor untouched');
        $this->assertSame(1, $h->cursor->col);
    }

    public function testEdMode3OnEmptyRingIsHarmless(): void
    {
        $h = $this->feed("abc\x1b[3J");
        $this->assertSame(0, $h->scrollback->count());
        $this->assertSame('abc ', $this->rowGraphemes($h, 0), 'print survives');
    }

    public function testRingKeepsWorkingAfterEd3(): void
    {
        // clear() rewinds head/tail/count — pushes after it append fresh.
        $h = $this->feed(self::FILL . "\r\ngggg\x1b[3J");
        $this->assertSame(0, $h->scrollback->count());
        (new Parser($h))->feed("\r\nhhhh");
        $this->assertSame(1, $h->scrollback->count(), 'post-ED3 scroll feeds again');
        $this->assertSame('bbbb', $this->rowText($h->scrollback->at(0) ?? []), 'the row scrolled OFF the top enters the ring');
    }

    public function testEdMode3DoesNotErasePrimaryScreen(): void
    {
        // Distinguishes 3 from 2: the visible grid keeps every glyph.
        $h = $this->feed(self::FILL . "\x1b[H\x1b[3J");
        $this->assertSame('aaaa', $this->rowGraphemes($h, 0));
        $this->assertSame('ffff', $this->rowGraphemes($h, 5));
    }

    public function testPrivatePrefixedEd3AlsoClearsScrollback(): void
    {
        // xterm routes ED on the Ps alone — a stray DEC-private marker does
        // not disable the clear (documented interpretation, ctlseqs lists
        // only the un-prefixed form).
        $h = $this->feed(self::FILL . "\r\ngggg\x1b[?3J");
        $this->assertSame(0, $h->scrollback->count(), '?3J drains the ring like 3J');
    }

    public function testIntermediatePrefixedEd3IsNotTheScrollbackClear(): void
    {
        // `CSI 3 $ J` / `CSI 3 SP J` are not ED 3 — intermediates bind the
        // sequence to other (unimplemented) families, ring stays.
        $h = $this->feed(self::FILL . "\r\ngggg\x1b[3\$J");
        $this->assertGreaterThan(0, $h->scrollback->count(), 'intermediate-prefixed 3J must not drain the ring');
    }

    public function testResizeGrowKeepsFullScreenRegionFeedingScrollback(): void
    {
        // Regression (review w4-vt #1): after a GROW, a never-touched
        // full-screen region must stay full-screen — otherwise the
        // full-screen gate silently mutes history forever for shells that
        // never re-issue DECSTBM.
        $t = new Terminal(4, 3);
        $t->feed("aa\r\nbb\r\ncc\r\ndd");          // scroll 1 → ring ['aa']
        $this->assertSame(1, $t->screen()->scrollback()?->count());
        $t->resize(4, 5);                          // grow
        $t->feed("\r\nee");                        // cursor at row 2 → plain move…
        $t->feed("\r\nff\r\ngg");                  // …then walk to the new bottom and scroll
        $this->assertGreaterThanOrEqual(2, $t->screen()->scrollback()?->count(), 'history still flows after a resize grow');
    }

    public function testResizeGrowKeepsSubRegionOutOfScrollback(): void
    {
        // A strict sub-region stays strict across a grow — the gate holds.
        $t = new Terminal(4, 6);
        $t->feed("aa\r\nbb\r\ncc\r\ndd\r\nee\r\nff");
        $t->feed("\x1b[2;5r\x1b[5;1H");            // region rows 1..4, cursor at its bottom
        $t->resize(4, 8);                          // grow
        $t->feed("\r\n\r\n");                      // scroll inside the sub-region
        $this->assertSame(0, $t->screen()->scrollback()?->count(), 'sub-region never feeds, even post-grow');
    }
}
