<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;
use SugarCraft\Ansi\Parser\Parser;

/**
 * Reset matrix: RIS (ESC c), DECSTR (CSI ! p), DECALN (CSI # 8).
 *
 * RESET MATRIX (see also the ScreenHandler::hardReset()/softReset()
 * doc-blocks):
 *
 * | State              | RIS (ESC c) | DECSTR (CSI !p) |
 * |--------------------|-------------|-----------------|
 * | screen contents    | cleared     | preserved       |
 * | scrollback ring    | preserved   | preserved       |
 * | cursor position    | home        | home            |
 * | saved cursor       | cleared     | preserved       |
 * | SGR pen            | default     | default         |
 * | DECAWM             | ON          | ON              |
 * | DECOM              | off         | off             |
 * | DECTCEM (25)       | visible     | visible         |
 * | DECSTBM margins    | full screen | preserved       |
 * | tab stops          | default 8   | preserved       |
 * | SCS G0-G3 / GL     | ASCII / G0  | preserved       |
 * | DEC 2026 sync      | off (queue discarded) | off (queue flushed) |
 * | alt screen         | main screen | preserved       |
 * | window title       | preserved   | preserved       |
 * | indexed palette    | preserved   | preserved       |
 *
 * RIS preserves the scrollback (charmbracelet/x/vt `Emulator.fullReset`
 * resets both Screen buffers but never the ring — only ED 3 clears it)
 * and clears the saved cursor (upstream `Screen.Reset` zeroes `saved`).
 * DECSTR is the soft variant: margins, tabs, charsets, saved cursor and
 * the ring all survive, per xterm ctlseqs.
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

    public function testDecstrResetsModesHomesCursorKeepsEverythingElse(): void
    {
        $h = $this->handler(self::DIRTY . "\x1b[!p");
        // Soft-reset targets:
        $this->assertTrue($h->mode->autoWrap, 'DECAWM returns to its power-on ON');
        $this->assertFalse($h->mode->originMode);
        $this->assertTrue($h->mode->cursorVisible);
        $this->assertSame(0, $h->cursor->row);
        $this->assertFalse($h->wrapPending);
        // Explicitly preserved by DECSTR (xterm ctlseqs):
        $this->assertSame(1, $h->scrollRegionTop, 'DECSTBM untouched');
        $this->assertSame(3, $h->scrollRegionBottom);
        $this->assertStringContainsString('DIRTY', $this->cellRun($h, 0, 8), 'screen contents untouched');
    }

    public function testDecstrKeepsScrollbackAndCharsetsAndTabs(): void
    {
        $h = $this->handler(
            "FIRST\r\nSECOND\r\n\x1b(0\x1b[3g\x1b[1;6H\x1bH\x1b[1;40H\x1b[!p",
            cols: 40,
            rows: 2,
        );
        $this->assertGreaterThan(0, $h->scrollback->count(), 'soft reset preserves scrollback');
        $this->assertSame('0', $h->charsets[0], 'charset designations survive DECSTR');
        // Tab stops survive verbatim: custom stop kept, defaults NOT restored.
        $this->assertArrayHasKey(5, $h->tabStops, 'custom stop survives DECSTR');
        $this->assertArrayNotHasKey(8, $h->tabStops, 'cleared defaults stay cleared');
    }

    public function testDecstrKeepsSavedCursor(): void
    {
        // Unlike RIS, DECSTR must not disturb DECSC state — DECRC after
        // the soft reset still lands on the saved spot.
        $h = $this->handler("\x1b[3;3H\x1b7\x1b[!p\x1b8");
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(2, $h->cursor->col);
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

    // ─── DECALN ─────────────────────────────────────────────────────────────

    public function testDecalnFillsScreenWithEAndHomesCursor(): void
    {
        // Wire-level `CSI # 8` cannot be dispatched by the shared
        // candy-ansi VT500 parser ('8' is a param byte, not a final — the
        // sequence drops to Ground), so DECALN is exercised through its
        // programmatic entry point, like enableAltScreen().
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
