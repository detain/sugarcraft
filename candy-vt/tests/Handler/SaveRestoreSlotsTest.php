<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Color\Color;
use SugarCraft\Vt\Handler\ScreenHandler;

/**
 * DECSC/DECRC must operate a GENERAL save slot that snapshots the full
 * VT500 §DECSC payload — cursor position, active SGR rendition, GL/GR
 * charset designations and DECOM — and stays INDEPENDENT of the AUX
 * alt-screen slot DEC 1049 swaps through.
 *
 * ESC 7/8 and CSI s/u share the one general slot (xterm merges DECSC and
 * the SCO extended-cursor save); the 1049 enter/leave pair never reads
 * or writes it, and vice versa.
 *
 * @see https://vt100.net/docs/vt510-rm/DECSC.html
 * @see https://vt100.net/docs/vt510-rm/DECRC.html
 */
final class SaveRestoreSlotsTest extends TestCase
{
    private function feed(string $bytes, int $cols = 10, int $rows = 5): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    // ─── General slot: full VT500 snapshot ──────────────────────────────────

    public function testDecscDecrcRestoresPositionRenditionCharsetsAndOrigin(): void
    {
        $h = $this->feed(
            "\x1b[?6h"          // DECOM on
            . "\x1b(0"          // G0 → DEC Special Graphics
            . "\x1b[31;44m"    // red fg, blue bg pen
            . "\x1b[4;7H"       // (3,6)
            . "\x1b7"           // DECSC
            . "\x1b(B"          // G0 → ASCII again
            . "\x1b[32m"        // green fg (bg unchanged)
            . "\x1b[?6l"        // DECOM off
            . "\x1b[1;1H"       // (0,0)
            . "\x1b8"           // DECRC
        );

        $this->assertSame(3, $h->cursor->row, 'position restored');
        $this->assertSame(6, $h->cursor->col, 'position restored');
        $this->assertNotNull($h->sgr->foreground, 'rendition restored');
        $this->assertTrue($h->sgr->foreground->equals(Color::indexed16(1)), 'red fg back');
        $this->assertTrue($h->sgr->background->equals(Color::indexed16(4)), 'blue bg back');
        $this->assertSame('0', $h->charsets[0], 'G0 designation restored');
        $this->assertSame(0, $h->gl, 'GL invocation restored');
        $this->assertTrue($h->mode->originMode, 'DECOM restored');
    }

    public function testCsiSUCsiPairSharesTheGeneralSlot(): void
    {
        // CSI s snapshots the SAME slot as ESC 7, including rendition and
        // designations; CSI u restores it (SCO extended-cursor parity).
        $h = $this->feed(
            "\x1b(0\x1b[46m\x1b[3;2H\x1b[s"
            . "\x1b(B\x1b[0m\x1b[5;9H"
            . "\x1b[u"
        );

        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col);
        $this->assertTrue($h->sgr->background->equals(Color::indexed16(6)), 'bg pen restored by CSI u');
        $this->assertFalse($h->sgr->bold, 'bold stays off (never set)');
        $this->assertSame('0', $h->charsets[0], 'charset restored by CSI u');
    }

    public function testEsc7OverwritesCsiSContentsOfSharedSlot(): void
    {
        // Save with CSI s at A, move, save with ESC 7 at B, move, restore
        // via CSI u → B: one slot, last save wins (xterm DECSC/SCO merge).
        $h = $this->feed("\x1b[1;1H\x1b[s\x1b[3;3H\x1b7\x1b[5;5H\x1b[u");
        $this->assertSame(2, $h->cursor->row);
        $this->assertSame(2, $h->cursor->col);
    }

    public function testDecrcWithoutPriorSaveLeavesPenCharsetsAndOriginAlone(): void
    {
        // No save on record → the historical position-only no-op, and the
        // companions must NOT be conjured from defaults.
        $h = $this->feed("\x1b[1;31m\x1b(0\x1b[?6h\x1b[2;4H\x1b8");
        $this->assertSame(1, $h->cursor->row, 'position untouched');
        $this->assertSame(3, $h->cursor->col, 'position untouched');
        $this->assertTrue($h->sgr->foreground->equals(Color::indexed16(1)), 'pen untouched');
        $this->assertSame('0', $h->charsets[0], 'designation untouched');
        $this->assertTrue($h->mode->originMode, 'DECOM untouched');
    }

    public function testDecrcRestoresGlInvocation(): void
    {
        // SO put G1 into GL at save time; SI at restore time must be undone.
        $h = $this->feed("\x1b)0\x0e\x1b7\x0f\x1b8");
        $this->assertSame(1, $h->gl, 'GL back to G1 (SO state at save)');
        $this->assertSame('0', $h->charsets[1], 'G1 designation restored');
    }

    // ─── General vs AUX slot independence (DEC 1049) ────────────────────────

    public function testAltScreenSwapDoesNotDisturbGeneralSlot(): void
    {
        // DECSC a spot, round-trip the alt screen (AUX save/restore), then
        // DECRC: still lands on the general save, not alt-screen residue.
        $h = $this->feed(
            "\x1b[4;6H\x1b[46m\x1b7"          // general save at (3,5), cyan bg
            . "\x1b[2;2H\x1b[?1049h"          // enter alt (AUX save of (1,1))
            . "\x1b[5;9H\x1b[45m"             // scribble in alt
            . "\x1b[?1049l"                   // leave alt → AUX restore
            . "\x1b8"                          // DECRC → general restore
        );

        $this->assertSame(3, $h->cursor->row, 'general slot survived the alt round-trip');
        $this->assertSame(5, $h->cursor->col, 'general slot survived the alt round-trip');
        $this->assertTrue($h->sgr->background->equals(Color::indexed16(6)), 'general pen restored');
    }

    public function testGeneralSaveInsideAltScreenDoesNotClobberAuxRestore(): void
    {
        // Enter alt, DECSC there, leave: the AUX restore must hand back the
        // pre-alt cursor/pen regardless of the general slot being rewritten.
        $h = $this->feed(
            "\x1b[1;3H\x1b[42m"
            . "\x1b[?1049h"
            . "\x1b[4;4H\x1b7"                // general save INSIDE alt
            . "\x1b[?1049l"
        );

        $this->assertSame(0, $h->cursor->row, 'AUX cursor restored, not the in-alt DECSC');
        $this->assertSame(2, $h->cursor->col);
        $this->assertTrue($h->sgr->background->equals(Color::indexed16(2)), 'AUX pen restored');
    }

    public function testAltScreenAuxSaveSnapshotsCharsetsAndOrigin(): void
    {
        // The VT500 DECSC-style save the 1049 swap promises covers SCS and
        // DECOM too: designate + origin ON before entering alt, corrupt both
        // inside, leave → both back.
        $h = $this->feed(
            "\x1b(0\x1b[?6h"
            . "\x1b[?1049h"
            . "\x1b(B\x1b[?6l"
            . "\x1b[?1049l"
        );

        $this->assertSame('0', $h->charsets[0], 'G0 designation survived the alt screen');
        $this->assertTrue($h->mode->originMode, 'DECOM survived the alt screen');
    }

    public function testHardResetClearsGeneralSlotCompanions(): void
    {
        // RIS drops the saved cursor; a following DECRC must not resurrect
        // the pre-reset pen/designation from the slot fields.
        $h = $this->feed("\x1b(0\x1b[42m\x1b[3;3H\x1b7\x1bc\x1b8");
        $this->assertNull($h->sgr->background, 'RIS cleared the general slot pen');
        $this->assertSame('B', $h->charsets[0], 'RIS cleared the general slot designations');
        $this->assertFalse($h->mode->originMode);
    }

    public function testGeneralCompanionsParkWhileAltScreenActive(): void
    {
        // xterm keeps the DECSC save per screen: a DECRC issued INSIDE the
        // alt screen must not resurrect the MAIN screen's pen/designations,
        // and once back on main the full general slot is intact.
        $h = $this->feed(
            "\x1b(0\x1b[46m\x1b[3;4H\x1b7"            // main: DECSC cyan-bg/DEC-G0 state at (2,3)
            . "\x1b[?1049h"                           // enter alt → companions park
            . "\x1b(B\x1b[0m\x1b[2;2H"                // corrupt pen+designation in alt
            . "\x1b8"                                  // DECRC in alt: full no-op (alt slot empty)
        );
        $this->assertSame(1, $h->cursor->row, 'alt DECRC must not teleport to the main slot');
        $this->assertSame(1, $h->cursor->col);
        $this->assertNull($h->sgr->background, 'alt DECRC must not resurrect the main pen');
        $this->assertSame('B', $h->charsets[0], 'alt DECRC must not resurrect the main designation');

        $p = new Parser($h);
        $p->feed("\x1b[?1049l\x1b8");                  // back to main, THEN DECRC
        $this->assertSame(2, $h->cursor->row, 'main general slot intact after the round-trip');
        $this->assertSame(3, $h->cursor->col);
        $this->assertNotNull($h->sgr->background, 'main pen restored by DECRC');
        $this->assertTrue($h->sgr->background->equals(Color::indexed16(6)));
        $this->assertSame('0', $h->charsets[0], 'main designation restored by DECRC');
    }

    public function testGeneralSaveInsideAltScreenIsDiscardedOnExit(): void
    {
        // Per-screen slot: DECSC taken in the alt screen dies with the alt
        // screen; the main screen's parked save wins once back. (xterm keeps
        // the slot per-screen; it leaves the alt slot's stale contents
        // alive — this port is stricter: the alt buffer is cleared on entry,
        // its save vanishes with it.)
        $h = $this->feed(
            "\x1b[2;2H\x1b7"                       // main slot: (1,1), default pen
            . "\x1b[?1049h"                        // enter alt → main slot parks
            . "\x1b[46m\x1b[5;5H\x1b7"             // alt slot: (4,4) with CYAN bg pen
            . "\x1b[3;3H"                          // move in alt
            . "\x1b[?1049l"                        // leave → alt slot gone, main slot back
            . "\x1b8"                              // DECRC on main
        );
        $this->assertSame(1, $h->cursor->row, 'main save wins over the in-alt save');
        $this->assertSame(1, $h->cursor->col);
        $this->assertNull($h->sgr->background, 'the in-alt cyan pen must not leak to the main DECRC');
    }
}
