<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Terminal;

/**
 * `CSI s` / `CSI u` are the SCO save/restore-cursor aliases of DECSC/DECRC —
 * but only in their UNMARKED form. A private marker routes the final byte
 * through a different action set in both reference implementations:
 *
 * - xterm switches the whole final-byte table on `?` (VTPrsTbl.c:558 `?` =
 *   CASE_DEC_STATE → charproc.c:3898-3900 `sp->parsestate = dec_table`); in
 *   dec_table, 's' saves the DEC private MODE settings (VTPrsTbl.c:4195
 *   CASE_XTERM_SAVE → savemodes(), charproc.c:6209-6210 — not modelled
 *   here, our documented gap) and 'u' is
 *   ignored outright (VTPrsTbl.c:4198 CASE_GROUND_STATE).
 * - tmux collects marker bytes 0x3c-0x3f into the table key's interm_buf
 *   (input.c:577) and its CSI table carries ('s', "") and ('u', "") only
 *   (input.c:345,347); `CSI ? u` finds no row, logs unknown, and returns
 *   before the dispatch switch (input.c:1483-1487).
 *
 * That makes `CSI ? u` — the kitty keyboard-protocol capability QUERY — inert
 * in both references, and it must be inert here too: before the prefix gate
 * this emulator executed it as SCO restore-cursor, so a query a program
 * merely expected an answer to instead teleported the cursor and (via the
 * same hole, `CSI ? s`) could clobber the GENERAL save slot shared with
 * ESC 7/8. candy-vt still does NOT answer the query — no kitty reply
 * channel, by design; the fix is that queries must not corrupt state.
 *
 * The marked forms differ from each other only in WHY they are inert in
 * xterm (own action vs ignored); for this emulator, which models neither the
 * save-modes action nor a reply, the observable behaviour is identical:
 * cursor and slot untouched. `CSI > u` is pinned with them because the gate
 * is `prefix === 0`, not `prefix === '?'` — matching the file's established
 * `case 'q'` DECSCUSR arm.
 *
 * Unmarked `CSI Ps s` / `CSI Ps u` (params, no SP intermediate) remain
 * save/restore: xterm routes them to the SAME ANSI_SC/ANSI_RC cases
 * (VTPrsTbl.c:623,626) and merely no-ops the marked-param spellings via
 * only_default() (charproc.c:4899, 4912-4913, 2219-2223; its LR-margin
 * DECSLRM branch at charproc.c:4885-4898 is unmodelled — candy-vt has no
 * DECLRMM), while tmux ignores params entirely (input.c:1820-1831 →
 * input_save_state/input_restore_state at input.c:849-871). It is NEVER a
 * cursor-shape request: DECSCUSR requires the SP intermediate + 'q' (xterm
 * csi_sp_table, VTPrsTbl.c:1917-1918; tmux input.c:342 `{ 'q', " ",
 * INPUT_CSI_DECSCUSR }`). We follow tmux: `CSI 4 s` saves, the swallowed
 * shape request being the price of that parity.
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (SCO sc/rc; DECSCUSR)
 * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/ (progressive enhancement: `CSI ? u`)
 */
final class CsiSaveRestorePrivateMarkerTest extends TestCase
{
    private function handler(int $cols = 12, int $rows = 4): ScreenHandler
    {
        return new ScreenHandler(new Buffer($cols, $rows));
    }

    private function feed(ScreenHandler $h, string $bytes): void
    {
        (new Parser($h))->feed($bytes);
    }

    /** Park ESC 7's slot at (3,8), cursor back home. */
    private const SAVE_CORNER = "\x1b[9;9H\x1b7\x1b[1;1H";

    public function testKittyCapabilityQueryDoesNotRestoreCursor(): void
    {
        $h = $this->handler();
        $this->feed($h, self::SAVE_CORNER);
        $this->assertSame([0, 0], [$h->cursor->row, $h->cursor->col], 'armed: cursor home, slot holds (3,8)');

        $this->feed($h, "\x1b[?u");

        $this->assertSame(0, $h->cursor->row, 'CSI ?u (kitty query) must not restore the cursor');
        $this->assertSame(0, $h->cursor->col, 'CSI ?u (kitty query) must not restore the cursor');
    }

    public function testPrivateSaveMarkerDoesNotClobberGeneralSlot(): void
    {
        $h = $this->handler();
        $this->feed($h, self::SAVE_CORNER);
        // Save something DIFFERENT into the GENERAL slot via the private
        // marker's cursor position: at home, `CSI ? s` used to overwrite the
        // ESC 7 corner save with the home position.
        $this->feed($h, "\x1b[?s\x1b[9;9H\x1b[u");

        $this->assertSame(3, $h->cursor->row, 'the ESC 7 slot must survive CSI ?s untouched');
        $this->assertSame(8, $h->cursor->col, 'the ESC 7 slot must survive CSI ?s untouched');
    }

    public function testGtMarkedRestoreIsInertLikePrivateMarker(): void
    {
        $h = $this->handler();
        $this->feed($h, self::SAVE_CORNER);
        $this->feed($h, "\x1b[>u\x1b[<s");

        $this->assertSame([0, 0], [$h->cursor->row, $h->cursor->col], 'CSI >u / CSI <s are marked forms — inert');

        // And the slot they might have touched is still the corner save.
        $this->feed($h, "\x1b[9;9H\x1b[u");
        $this->assertSame([3, 8], [$h->cursor->row, $h->cursor->col], 'plain CSI u still finds the ESC 7 slot');
    }

    public function testPlainCsiSaveRestoreRoundTripStillWorks(): void
    {
        $h = $this->handler();
        $this->feed($h, "\x1b[2;3H\x1b[s\x1b[9;9H\x1b[u");

        $this->assertSame(1, $h->cursor->row, 'CSI s then CSI u (no marker) round-trips');
        $this->assertSame(2, $h->cursor->col, 'CSI s then CSI u (no marker) round-trips');
    }

    public function testCsiSaveIsVisibleFromDecrcAndViceVersa(): void
    {
        // CSI s writes the same GENERAL slot as ESC 7 (DEC alias), and CSI u
        // reads what ESC 7 wrote — asserted from BOTH directions so a gate
        // that disabled the unmarked arms wholesale could not pass.
        $h = $this->handler();
        $this->feed($h, "\x1b[2;3H\x1b[s\x1b[9;9H\x1b8");
        $this->assertSame([1, 2], [$h->cursor->row, $h->cursor->col], 'ESC 8 restores the CSI s save');

        $h = $this->handler();
        $this->feed($h, "\x1b[2;3H\x1b7\x1b[9;9H\x1b[u");
        $this->assertSame([1, 2], [$h->cursor->row, $h->cursor->col], 'CSI u restores the ESC 7 save');
    }

    /**
     * `CSI Ps s` / `CSI Ps u` (params, NO SP intermediate) stay save/restore.
     * Pinned against both references: xterm routes them to the identical
     * ANSI_SC/ANSI_RC cases (VTPrsTbl.c:623,626) whose only_default() guard
     * (charproc.c:4899,4912-4913 + 2219-2223) merely DROPS the non-default
     * param spelling — it is never routed to DECSCUSR, which needs SP + 'q'
     * (xterm csi_sp_table VTPrsTbl.c:1917-1918; tmux input.c:342) — while
     * tmux saves/restores ignoring params (input.c:1820-1831 →
     * input_save_state/input_restore_state, input.c:849-871). candy-vt
     * follows tmux: the shape request silently swallowed is our documented
     * choice, the cursor save is the established behaviour.
     */
    public function testCsi4sStaysSaveCursorNotCursorShape(): void
    {
        $h = $this->handler();
        $this->feed($h, "\x1b[2;3H\x1b[4s\x1b[9;9H\x1b[u");

        $this->assertSame(1, $h->cursor->row, 'CSI 4 s must still save the cursor (tmux parity)');
        $this->assertSame(2, $h->cursor->col, 'CSI 4 s must still save the cursor (tmux parity)');
        $this->assertSame(0, $h->mode->cursorShape, 'and must NOT act as a DECSCUSR shape request');
    }

    public function testRendererFacadeIgnoresMarkedSaveRestoreToo(): void
    {
        // The renderer half of the gate lives in candy-ansi
        // HandlerAdapter::csiDispatch() (the vcr engine's s/u route); the two
        // engines must agree — a kitty query must not teleport either cursor.
        $t = Terminal::new(10, 4);
        $t->feed("\x1b[9;9H\x1b[s\x1b[1;1H");
        $this->assertSame([0, 0], [$t->cursor()->row, $t->cursor()->col], 'armed: home, slot holds (3,8)');
        $t->feed("\x1b[?u\x1b[?s");
        $this->assertSame([0, 0], [$t->cursor()->row, $t->cursor()->col], 'CSI ?u/?s inert on the renderer too');
        $t->feed("\x1b[9;9H\x1b[u");
        $this->assertSame([3, 8], [$t->cursor()->row, $t->cursor()->col], 'and its slot stayed the CSI s save');
    }
}
