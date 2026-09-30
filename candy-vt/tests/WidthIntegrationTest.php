<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * Validates Width::string integration via ScreenHandler::printChar.
 *
 * Wide East-Asian chars take 2 cells (the 2nd is a continuation).
 * Zero-width chars (combining marks, ZWJ) advance the cursor by 0.
 */
final class WidthIntegrationTest extends TestCase
{
    public function testNarrowAsciiTakesOneCell(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed('Hi');
        $this->assertSame('H', $term->screen()->cell(0, 0)->grapheme);
        $this->assertSame('i', $term->screen()->cell(0, 1)->grapheme);
        $this->assertSame(2, $term->cursor()->col);
    }

    public function testCjkTakesTwoCellsWithContinuation(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed('日');
        $s = $term->screen();
        $this->assertSame('日', $s->cell(0, 0)->grapheme);
        $this->assertTrue($s->cell(0, 1)->continuation);
        $this->assertSame('', $s->cell(0, 1)->grapheme);
        $this->assertSame(2, $term->cursor()->col);
    }

    public function testCjkRunSequencesProperly(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed('日本語');
        $s = $term->screen();
        $this->assertSame('日', $s->cell(0, 0)->grapheme);
        $this->assertSame('本', $s->cell(0, 2)->grapheme);
        $this->assertSame('語', $s->cell(0, 4)->grapheme);
        $this->assertSame(6, $term->cursor()->col);
    }

    public function testEmojiTakesTwoCells(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("\u{1F600}"); // 😀
        $s = $term->screen();
        $this->assertSame("\u{1F600}", $s->cell(0, 0)->grapheme);
        $this->assertTrue($s->cell(0, 1)->continuation);
        $this->assertSame(2, $term->cursor()->col);
    }

    public function testZeroWidthCombiningMarkSkipped(): void
    {
        // 'a' (U+0061) + combining acute (U+0301). Combining mark width = 0.
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("a\u{0301}");
        $this->assertSame('a', $term->screen()->cell(0, 0)->grapheme);
        // Combining mark wasn't placed in cell 1 (it has width 0).
        $this->assertSame(' ', $term->screen()->cell(0, 1)->grapheme);
        $this->assertSame(1, $term->cursor()->col);
    }

    public function testZwjJoinerAdvancesByZero(): void
    {
        // ZWJ alone is width 0.
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("\u{200D}");
        $this->assertSame(0, $term->cursor()->col);
        $this->assertSame(' ', $term->screen()->cell(0, 0)->grapheme);
    }

    public function testWideCharAtRightEdgeClampsWithoutWriting(): void
    {
        // Buffer width 3; write 'A' (col 0→1), 'B' (col 1→2), then '日' would
        // need cols 2+3 — col 3 is out of range. Don't write; clamp at 2.
        // DECAWM is the power-on default now; this test pins the DECAWM-off
        // discard path, so turn wrapping off explicitly.
        $term = Terminal::create(cols: 3, rows: 1);
        $term->feed("\x1b[?7lAB日");
        $s = $term->screen();
        $this->assertSame('A', $s->cell(0, 0)->grapheme);
        $this->assertSame('B', $s->cell(0, 1)->grapheme);
        // Col 2 should NOT be the start of '日' since it didn't fit.
        $this->assertNotSame('日', $s->cell(0, 2)->grapheme);
        $this->assertSame(2, $term->cursor()->col);
    }

    public function testContinuationCellInheritsHyperlink(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("\x1b]8;;https://example.com\x07日\x1b]8;;\x07");
        $s = $term->screen();
        $this->assertNotNull($s->cell(0, 0)->hyperlink);
        $this->assertSame('https://example.com', $s->cell(0, 0)->hyperlink->uri);
        // Continuation cell carries the same hyperlink.
        $this->assertNotNull($s->cell(0, 1)->hyperlink);
        $this->assertSame('https://example.com', $s->cell(0, 1)->hyperlink->uri);
    }

    // ─── Wide-pair partner erasure on overwrite (xterm eraseDoubleCell) ──────

    /**
     * Narrow over the BASE of a wide glyph: xterm erases the exposed
     * continuation half, so the old pair is fully gone — the cell under the
     * new glyph plus a blank partner, never a styled ghost.
     */
    public function testNarrowOverWideBaseErasesGhostTail(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("漢\x1b[1;1HA");
        $s = $term->screen();
        $this->assertSame('A', $s->cell(0, 0)->grapheme);
        $this->assertFalse($s->cell(0, 1)->continuation, 'ghost tail must not survive the overwrite');
        $this->assertTrue($s->cell(0, 1)->equals(\SugarCraft\Vt\Cell::empty()));
    }

    /**
     * Narrow over the CONTINUATION of a wide glyph: the orphaned base is
     * erased on the other side (cont→head direction of the same law).
     */
    public function testNarrowOverWideTailErasesOrphanHead(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("漢\x1b[1;2HB");
        $s = $term->screen();
        $this->assertTrue($s->cell(0, 0)->equals(\SugarCraft\Vt\Cell::empty()), 'orphaned base must be erased');
        $this->assertSame('B', $s->cell(0, 1)->grapheme);
    }

    /**
     * Wide over wide, one column apart: exactly ONE glyph survives — base +
     * its own continuation — plus one erase clearing the doubled orphan tail.
     */
    public function testWideOverWideOffsetLeavesSingleGlyphAndOneErase(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("\x1b[1;2H漢\x1b[1;1H字");
        $s = $term->screen();
        $this->assertSame('字', $s->cell(0, 0)->grapheme);
        $this->assertTrue($s->cell(0, 1)->continuation);
        $this->assertFalse($s->cell(0, 2)->continuation, 'doubled orphan tail must be erased');
        $this->assertTrue($s->cell(0, 2)->equals(\SugarCraft\Vt\Cell::empty()));
        // Exactly one continuation pair exists in the row.
        $tails = 0;
        for ($c = 0; $c < 10; $c++) {
            if ($s->cell(0, $c)->continuation) {
                $tails++;
            }
        }
        $this->assertSame(1, $tails);
    }

    /**
     * The erased partner takes the BCE blank, not a bare default cell:
     * overwriting with a red-background pen leaves the partner blank RED,
     * with foreground/attributes reset — the same erase colour law every
     * ED/EL blank follows.
     */
    public function testErasedWidePartnerCarriesBceBackground(): void
    {
        $term = Terminal::create(cols: 10, rows: 1);
        $term->feed("漢\x1b[1;1H\x1b[41mA");
        $bg = $term->screen()->cell(0, 1)->background();
        $this->assertNotNull($bg, 'partner erase must carry the pen background (BCE)');
        // SGR 41 stores the logical palette index, not the xterm register.
        $this->assertTrue($bg->equals(\SugarCraft\Vt\Color\Color::indexed16(1)));
    }
}
