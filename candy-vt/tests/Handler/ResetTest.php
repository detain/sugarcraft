<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Vt\Rendition;
use SugarCraft\Ansi\Parser\Parser;

/**
 * Reset matrix: RIS (ESC c), DECSTR (CSI ! p), DECALN (ESC # 8).
 *
 * RESET MATRIX (see also the ScreenHandler::hardReset()/softReset()
 * doc-blocks):
 *
 * | State              | RIS (ESC c) | DECSTR (CSI !p) |
 * |--------------------|-------------|-----------------|
 * | screen contents    | cleared     | preserved       |
 * | scrollback ring    | preserved   | preserved       |
 * | cursor position    | home        | home            |
 * | DECSCUSR shape     | 0           | 0               |
 * | saved cursor       | cleared     | home (0,0)      |
 * | SGR pen            | default     | default         |
 * | DECAWM             | ON          | ON              |
 * | DECOM              | off         | off             |
 * | DECTCEM (25)       | visible     | visible         |
 * | DECSTBM margins    | full screen | full screen     |
 * | tab stops          | default 8   | preserved       |
 * | SCS G0-G3 / GL     | ASCII / G0  | ASCII / G0      |
 * | line rendition ESC #3–#6 | cleared (cells rewritten) | preserved |
 * | DEC 2026 sync      | off (queue discarded) | off (queue flushed) |
 * | alt screen         | main screen | preserved       |
 * | window title       | preserved   | preserved       |
 * | indexed palette    | preserved   | preserved       |
 *
 * RIS preserves the scrollback (charmbracelet/x/vt `Emulator.fullReset`
 * resets both Screen buffers but never the ring — only ED 3 clears it)
 * and clears the saved cursor (upstream `Screen.Reset` zeroes `saved`).
 * DECSTR is the soft variant, and now MATCHES xterm-411 on the four buffer
 * geometry items that an earlier narrower subset used to preserve:
 * `ReallyReset()` resets the scrolling region (`charproc.c:14398`) and the
 * character sets (`charproc.c:14410`) above the RIS-only `if (full)` gate at
 * `charproc.c:14432`, and the DECSTR branch overwrites the DECSC slot with
 * home (`charproc.c:14559-14561`). Tab stops and the scrollback stay
 * preserved — and there DECSTR genuinely agrees with xterm: `TabReset` sits
 * inside `if (full)` (`charproc.c:14449`) and the ring is flushed on the
 * separate `saved` argument (`charproc.c:14372-14375`), which DECSTR passes
 * False. Cursor shape is reset by
 * BOTH variants because xterm's shared cursor block at `charproc.c:14377-14387`
 * runs for the soft reset too — see the CURSOR SHAPE paragraph on
 * {@see ScreenHandler::softReset()} and CursorShapeAgreementTest.
 *
 * @see https://vt100.net/docs/vt510-rm/chapter4.html (RIS)
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (DECSTR, DECALN)
 */
final class ResetTest extends TestCase
{
    private function handler(string $bytes, int $cols = 8, int $rows = 5): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    /** Dirty enough state that any reset must touch something. */
    private const DIRTY =
        "\x1b[HDIRTY\x1b[K" . // content first — origin mode below shifts addressing
        "\x1b[?7l" .          // DECAWM off (non-default)
        "\x1b[?6h" .          // DECOM on
        "\x1b[?25l" .         // cursor hidden
        "\x1b[2;4r" .         // DECSTBM 2..4
        "\x1b[10m" .          // SGR non-default
        "\x1b[3g" .           // TBC 3: wipe the default tab stops…
        "\x1b[1;6H\x1bH" .    // …and set one custom stop at col 5 (ESC H = HTS)
        "\x1b[3;3H";          // cursor parked mid-region

    // ─── RIS ────────────────────────────────────────────────────────────────

    public function testRisRestoresPowerOnModes(): void
    {
        $h = $this->handler(self::DIRTY . "\x1bc");
        $this->assertTrue($h->mode->autoWrap, 'DECAWM is a power-on SET mode');
        $this->assertFalse($h->mode->originMode);
        $this->assertTrue($h->mode->cursorVisible);
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
        $this->assertFalse($h->wrapPending);
    }

    public function testRisClearsScreenButKeepsScrollbackRing(): void
    {
        // Feed enough to push a line into scrollback, then RIS.
        $h = $this->handler("FIRST\r\nSECOND\r\n\x1bc", rows: 2);
        $this->assertSame('     ', $this->cellRun($h, 0, 5));
        // The ring survives the hard reset (charmbracelet x/vt parity).
        $this->assertGreaterThan(0, $h->scrollback->count());
    }

    public function testRisResetsMarginsAndTabStops(): void
    {
        $h = $this->handler(self::DIRTY . "\x1bc", cols: 20);
        $this->assertSame(0, $h->scrollRegionTop);
        $this->assertSame(4, $h->scrollRegionBottom);
        // Default tab stops every 8 columns — meaningful because DIRTY
        // cleared them all and left exactly one custom stop at col 5.
        $this->assertArrayHasKey(8, $h->tabStops);
        $this->assertArrayHasKey(16, $h->tabStops);
        $this->assertArrayNotHasKey(5, $h->tabStops, 'custom stop replaced by defaults');
    }

    public function testRisResetsCharsetsAndGl(): void
    {
        $h = $this->handler("\x1b(0\x1b)0\x0e\x1bc");
        $this->assertSame(['B', 'B', 'B', 'B'], $h->charsets);
        $this->assertSame(0, $h->gl);
        (new Parser($h))->feed('l');
        $this->assertSame('l', $h->buffer->cell(0, 0)->grapheme, 'G0 back to ASCII after RIS');
    }

    public function testRisClearsSavedCursor(): void
    {
        // charmbracelet x/vt Screen.Reset zeroes `saved` alongside `cur`.
        $h = $this->handler("\x1b[3;3H\x1b7\x1bc\x1b8");
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
    }

    public function testRisLeavesAltScreenOnMain(): void
    {
        $h = $this->handler(self::DIRTY . "\x1b[?1049h\x1bc");
        $this->assertFalse($h->mode->isAltScreen());
        $this->assertSame('     ', $this->cellRun($h, 0, 5));
    }

    public function testRisExitsSyncAndDiscardsQueuedMutations(): void
    {
        $h = $this->handler("\x1b[?2026h\x1b[?2026lQ", cols: 8, rows: 5);
        // Sanity: with sync toggled on/off properly the Q landed.
        $this->assertSame('Q', $h->buffer->cell(0, 0)->grapheme);

        $h2 = $this->handler("\x1b[?2026hZ\x1bc", cols: 8, rows: 5);
        $this->assertFalse($h2->mode->syncUpdate);
        $this->assertSame(' ', $h2->buffer->cell(0, 0)->grapheme, 'queued mutation discarded by RIS');
    }

    // ─── DECSTR ─────────────────────────────────────────────────────────────

    public function testDecstrResetsModesAndMarginsAndHomesCursorKeepsContent(): void
    {
        $h = $this->handler(self::DIRTY . "\x1b[!p");
        // Soft-reset targets:
        $this->assertTrue($h->mode->autoWrap, 'DECAWM returns to its power-on ON');
        $this->assertFalse($h->mode->originMode);
        $this->assertTrue($h->mode->cursorVisible);
        $this->assertSame(0, $h->cursor->row);
        $this->assertFalse($h->wrapPending);
        // DECSTBM margins are now reset to full screen (matching xterm's
        // `resetMarginMode()` above the `if (full)` gate, charproc.c:14398).
        // DIRTY set `CSI 2;4r` → top 1 / bottom 3; default grid here is 8x5,
        // so a full reset lands top 0 / bottom 4.
        $this->assertSame(0, $h->scrollRegionTop, 'DECSTBM reset to full by DECSTR');
        $this->assertSame(4, $h->scrollRegionBottom);
        // Screen contents stay preserved (this is the SOFT reset — no clear).
        $this->assertStringContainsString('DIRTY', $this->cellRun($h, 0, 8), 'screen contents untouched');
    }

    public function testDecstrResetsCharsetsToAscii(): void
    {
        // xterm's DECSTR runs `resetCharsets(screen)` (charproc.c:14410) above
        // the RIS-only gate, so G0/G1 return to ASCII and G2/G3 to the UPSS
        // default — ASCII in this UTF-8-only port (charproc.c:1271-1274,
        // 1259-1261) — with GL back to G0 (curgl, charproc.c:1277). xterm's
        // `curgr = 2` has no candy-vt counterpart (no GR model).
        $h = $this->handler("\x1b(0\x1b)0\x1b[!p");
        $this->assertSame(['B', 'B', 'B', 'B'], $h->charsets, 'designations back to ASCII');
        $this->assertSame(0, $h->gl);
        // Functional proof: with G0 still DEC_SPECIAL, `l` would render as the
        // line-drawing ⎿ glyph; after the reset it lands as plain ASCII 'l'.
        (new Parser($h))->feed('l');
        $this->assertSame('l', $h->buffer->cell(0, 0)->grapheme, 'G0 ASCII after DECSTR');
    }

    public function testDecstrKeepsScrollbackAndTabStops(): void
    {
        $h = $this->handler(
            "FIRST\r\nSECOND\r\n\x1b[3g\x1b[1;6H\x1bH\x1b[1;40H\x1b[!p",
            cols: 40,
            rows: 2,
        );
        $this->assertGreaterThan(0, $h->scrollback->count(), 'soft reset preserves scrollback');
        // Tab stops survive verbatim: custom stop kept, defaults NOT restored.
        // This AGREES with xterm — `TabReset` is inside `if (full)`
        // (charproc.c:14449), so DECSTR is the reset that leaves them alone.
        $this->assertArrayHasKey(5, $h->tabStops, 'custom stop survives DECSTR');
        $this->assertArrayNotHasKey(8, $h->tabStops, 'cleared defaults stay cleared');
    }

    public function testDecstrResetsSavedCursorToHome(): void
    {
        // xterm's DECSTR branch issues `CursorSave` then forces the saved
        // position to (0,0) (charproc.c:14559-14561) — so a DECRC after the
        // soft reset lands on HOME. A live move is inserted between DECSTR and
        // DECRC so the assertion is non-vacuous: a no-op DECRC would leave the
        // cursor at (2,3), and the old slot-preserving behaviour would restore
        // the saved (5,5) — both distinct from the correct (0,0).
        $h = $this->handler("\x1b[6;6H\x1b7\x1b[!p\x1b[3;4H\x1b8", cols: 8, rows: 8);
        $this->assertSame(0, $h->cursor->row, 'DECRC lands home after DECSTR overwrote the slot');
        $this->assertSame(0, $h->cursor->col);
    }

    public function testDecstrResetsSavedSlotCompanions(): void
    {
        // DECSC's rendition companions (SGR, G0-G3/GL, DECOM — xterm's whole
        // `sc[]` struct) must follow the save: DECSTR re-saves them from the
        // post-reset DEFAULT state, so DECRC restores home/default-pen/ASCII/GL0/
        // origin-off, never the pre-reset dirty save nor a no-op. The live state
        // is deliberately re-dirtied after DECSTR (green pen, G0 ASCII, LS2, origin
        // on) so both a no-op DECRC and the old preserve-the-dirty-slot behaviour
        // fail — only the re-saved default snapshot passes.
        $dirty = "\x1b[?6h\x1b(0\x1bn\x1b[41m";   // origin ON, G0=DEC, GL=G2, red pen
        $after = "\x1b(B\x1b[42m\x1bn\x1b[?6h";    // re-dirty: G0 ASCII, green pen, GL=G2, origin ON
        $h = $this->handler($dirty . "\x1b7\x1b[!p" . $after . "\x1b8");
        $this->assertNull($h->sgr->background, 'DECRC restores default pen, not pre-DECSTR red or post-DECSTR green');
        $this->assertSame(['B', 'B', 'B', 'B'], $h->charsets, 'DECRC restores ASCII designations, not the saved DEC_SPECIAL');
        $this->assertSame(0, $h->gl, 'DECRC restores GL to G0, not the saved/armed LS2');
        $this->assertFalse($h->mode->originMode, 'DECRC restores origin off');
    }

    public function testDecstrClearsArmedSingleShift(): void
    {
        // xterm's resetCharsets() ends with `curss = 0` (charproc.c:1279), so an
        // armed SS2/SS3 single shift is dropped by DECSTR. Only the 8-bit C1 form
        // arms it, so set the state directly rather than thread a bare C1 through
        // the parser — this pins the reset independently of designation handling.
        $h = $this->handler('', cols: 8, rows: 5);
        $h->singleShift = 3;               // as `ESC ... SS3` would leave it
        $h->softReset();
        $this->assertNull($h->singleShift, 'DECSTR drops an armed single shift (xterm curss = 0)');
    }

    public function testDecstrOnAltScreenReSavesActiveSlotAndKeepsMainParked(): void
    {
        // DECSTR's DECSC-slot overwrite writes the ACTIVE buffer's companions
        // (xterm's `sc[screen->whichBuf]`, cursor.c:419-423, forced to home at
        // charproc.c:14559-14561) and must not disturb the MAIN-screen slot
        // parked by `CSI ?1049 h`'s parkGeneralCompanions(). The 8x8 grid keeps
        // the `CSI 6;6H` saves off the default 5-row clamp.
        $h = new ScreenHandler(new Buffer(8, 8));
        $p = new Parser($h);
        $p->feed("\x1b[6;6H\x1b[?6h\x1b7");           // MAIN: save (5,5) + origin ON
        $p->feed("\x1b[?1049h");                       // enter alt → main slot parked
        $p->feed("\x1b[2;3r\x1b[4;4H\x1b[?6h\x1b7");   // ALT: margins 1..2, save (3,3) + origin ON
        $p->feed("\x1b[!p");                           // DECSTR on the alt screen
        $this->assertTrue($h->mode->isAltScreen(), 'DECSTR must not leave the alt screen');
        $this->assertSame(0, $h->scrollRegionTop, 'alt-screen margins reset to full');
        $this->assertSame(7, $h->scrollRegionBottom, '…to the bottom of the ACTIVE screen');
        $p->feed("\x1b8");
        $this->assertSame(0, $h->cursor->row, 'DECRC on alt restores DECSTR-home, not the alt (3,3) save');
        $this->assertSame(0, $h->cursor->col);
        // …and the parked MAIN slot survived intact: origin ON + position (5,5).
        $p->feed("\x1b[?1049l\x1b8");
        $this->assertFalse($h->mode->isAltScreen(), 'back on the main screen');
        $this->assertTrue($h->mode->originMode, 'DECRC restored the parked MAIN origin-ON snapshot');
        $this->assertSame(5, $h->cursor->row, 'DECRC lands the main spot, not alt-home');
        $this->assertSame(5, $h->cursor->col);
    }

    public function testDecstrOnMainScreenLeavesAltScreenStoresUntouched(): void
    {
        // Symmetry of the alt test: while on the MAIN screen there is no parked
        // slot and no AUX save — `parkedGeneral` and the `saved*` alt fields are
        // only armed inside `CSI ?1049 h` (parkGeneralCompanions /
        // enterAltScreen). DECSTR on main must write the GENERAL live slot only:
        // a softReset() that fabricated or clobbered either store would leak a
        // reset-invented state into the next alt-screen save/restore pair.
        $h = $this->handler("\x1b[3;3H\x1b7\x1b[!p");
        foreach (['parkedGeneral', 'savedCursor', 'savedSgr', 'savedCharsets'] as $field) {
            $ref = new \ReflectionProperty(ScreenHandler::class, $field);
            $ref->setAccessible(true);
            $this->assertNull($ref->getValue($h), "DECSTR must leave {$field} null while on main");
        }
        // The live MAIN slot did take the reset save: move, then DECRC → home.
        (new Parser($h))->feed("\x1b[5;5H\x1b8");
        $this->assertSame(0, $h->cursor->row, 'DECRC restores the DECSTR home save');
        $this->assertSame(0, $h->cursor->col);
    }

    public function testDecstrResetsSgrPen(): void
    {
        $h = $this->handler("\x1b[41m\x1b[!pX", cols: 4, rows: 2);
        $this->assertNull($h->sgr->background, 'pen back to default rendition');
    }

    public function testDecstrFlushesSyncQueue(): void
    {
        $h = $this->handler("\x1b[?2026hQ\x1b[!p", cols: 4, rows: 4);
        $this->assertFalse($h->mode->syncUpdate);
        $this->assertSame('Q', $h->buffer->cell(0, 0)->grapheme, 'pending mutations flushed, not dropped');
    }

    public function testDecstrPreservesLineRendition(): void
    {
        // DECDHL/DECSWL/DECDWL are immediate per-cell stamps, not a pending
        // mode; DECSTR clears no screen content so it clears no rendition
        // either (agrees with xterm — see the softReset() doc-block).
        $h = $this->handler("\x1b[2;1Habcdef\x1b#6\x1b[!p", cols: 8, rows: 3);
        // Row 1 was parked on by `ESC # 6` before the cursor moved to it and
        // printed; DECSTR must leave that double-width stamp standing.
        $this->assertSame(
            Rendition::DoubleWidth,
            $h->buffer->cell(1, 0)->rendition,
            'soft reset must not forget a stamped line rendition',
        );
        $this->assertSame('a', $h->buffer->cell(1, 0)->grapheme, 'content preserved too');
    }

    public function testDecalnClearsLineRendition(): void
    {
        // DECALN rewrites every cell to a default-rendition 'E', so it is the
        // reset that DOES clear double-size lines (xterm runs resetDouble here).
        $h = $this->handler("\x1b#6\x1b#8", cols: 4, rows: 2);
        for ($r = 0; $r < 2; $r++) {
            for ($c = 0; $c < 4; $c++) {
                $this->assertSame(
                    Rendition::None,
                    $h->buffer->cell($r, $c)->rendition,
                    "DECALN clears the rendition at {$r},{$c}",
                );
                $this->assertSame('E', $h->buffer->cell($r, $c)->grapheme);
            }
        }
    }

    // ─── DECALN ─────────────────────────────────────────────────────────────

    public function testDecalnFillsScreenWithEAndHomesCursor(): void
    {
        // Programmatic entry point only — the wire form `ESC # 8` and its
        // parser seam are pinned end-to-end in DecalnWireTest.
        // Designate non-default sets FIRST so the charsets assertion below
        // proves DECALN reset them (it would be vacuously true otherwise).
        $h = $this->handler(self::DIRTY . "\x1b(0\x1b)U", cols: 6, rows: 3);
        $h->displayAlignmentTest();
        for ($r = 0; $r < 3; $r++) {
            $this->assertSame('EEEEEE', $this->cellRun($h, $r, 6), "row {$r} filled");
        }
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col);
        $this->assertSame(0, $h->scrollRegionTop, 'margins reset to full');
        $this->assertSame(2, $h->scrollRegionBottom);
        $this->assertSame(['B', 'B', 'B', 'B'], $h->charsets);
    }

    public function testDecalnKeepsModeFlagsAndScrollback(): void
    {
        $h = $this->handler("X\r\nY\r\n\x1b[?25l", cols: 4, rows: 2);
        $h->displayAlignmentTest();
        $this->assertFalse($h->mode->cursorVisible, 'DECTCEM not part of DECALN');
        $this->assertGreaterThan(0, $h->scrollback->count());
    }

    private function cellRun(ScreenHandler $h, int $row, int $len): string
    {
        $line = '';
        for ($c = 0; $c < $len; $c++) {
            $line .= $h->buffer->cell($row, $c)->grapheme;
        }
        return $line;
    }
}
