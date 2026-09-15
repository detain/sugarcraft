<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;

/**
 * Cursor-shape agreement across the state-rebuilding paths.
 *
 * The DECSCUSR handler (`CSI Ps SP q`) writes the shape to TWO places at
 * once — {@see \SugarCraft\Vt\Cursor\Cursor::$shape} for the renderer and
 * {@see \SugarCraft\Vt\Mode\Mode::$cursorShape} for mode queries. Any site
 * that rebuilds the Cursor with `new Cursor(...)` and omits `shape:`
 * silently zeroes the renderer's copy while the mode keeps its value, so the
 * two disagree and the emulator draws a block cursor behind a terminal that
 * still believes it asked for a bar. Every test here asserts BOTH fields and
 * their agreement, always seeded from a non-default shape (4 or 5) so the
 * assertions cannot be satisfied by the accidental 0 that is the symptom.
 *
 * Per-path expected outcomes, all from xterm-411 `charproc.c` (line numbers
 * cited at each site in ScreenHandler):
 *
 * | Path                 | xterm-411 does                     | shape    |
 * |----------------------|------------------------------------|----------|
 * | RIS `ESC c`          | `ReallyReset(full=True)` cursor blk| reset to 0 |
 * | DECSTR `CSI ! p`     | same cursor block, above the        | reset to 0 |
 * |                      | `if (full)` gate → runs on soft too|          |
 * | DECALN `ESC # 8`     | never touches cursor style          | preserved |
 * | DECSET 1049h alt swap| `SavedCursor` has no style field;   | preserved |
 * |                      | `cursor_shape` is per-terminal      |          |
 * | DECSET 1048h cursor  | same                              | preserved |
 *
 * ResetTest owns the general reset matrix and DecalnWireTest owns the DECALN
 * shape guard; this file owns the DECSTR and alt-screen swap sites.
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (DECSTR, DECSCUSR, DEC 1048/1049)
 */
final class CursorShapeAgreementTest extends TestCase
{
    private function handler(string $bytes = '', int $cols = 8, int $rows = 4): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        if ($bytes !== '') {
            (new Parser($h))->feed($bytes);
        }
        return $h;
    }

    /** `CSI 4 SP q` — steady underline. */
    private const UNDERLINE = "\x1b[4\x20q";

    /** `CSI 5 SP q` — blinking bar. */
    private const BAR = "\x1b[5\x20q";

    /**
     * Assert the invariant without duplicating the path-specific expectation:
     * the renderer's shape and the mode's shape must never diverge.
     */
    private function assertShapesAgree(ScreenHandler $h, string $when): void
    {
        $this->assertSame(
            $h->mode->cursorShape,
            $h->cursor->shape,
            "{$when}: Mode::\$cursorShape and Cursor::\$shape must not diverge",
        );
    }

    // ─── Seed sanity: the shapes used below are really non-default ─────────

    public function testSeedingDecscusrSetsBothShapeFields(): void
    {
        // Guard against every test in this file passing vacuously: if the wire
        // form did not reach both fields, a later "they agree" assertion would
        // prove nothing.
        $h = $this->handler(self::UNDERLINE);
        $this->assertSame(4, $h->cursor->shape);
        $this->assertSame(4, $h->mode->cursorShape);

        $h = $this->handler(self::BAR);
        $this->assertSame(5, $h->cursor->shape);
        $this->assertSame(5, $h->mode->cursorShape);
    }

    // ─── DECSTR (CSI ! p) — xterm resets the shape; both fields must follow ─

    public function testDecstrResetsShapeOnBothFieldsKeepingAgreement(): void
    {
        // xterm-411 `charproc.c:6154-6156` routes CASE_DECSTR to
        // `VTReset(xw, False, False)`, and the cursor block inside
        // `ReallyReset()` at `charproc.c:14377-14387` sits ABOVE the RIS-only
        // `if (full)` gate at `charproc.c:14432`, so the soft reset runs it too:
        // `InitCursorShape()` recomputes `cursor_shape` from the
        // cursorUnderLine/cursorBar RESOURCES (`charproc.c:10315-10317`,
        // `charproc.c:569-570`), discarding whatever DECSCUSR set, and
        // `cursor_blink_esc` goes to 0. candy-vt has no resource layer, so the
        // faithful target is the 0 its value objects default to.
        //
        // The MODE assertion is the one the bug broke: pre-fix the renderer
        // dropped to 0 while mode->cursorShape stayed at 4, so pinning mode to 0
        // here is what makes this test non-vacuous for the reset direction.
        $h = $this->handler(self::UNDERLINE . "\x1b[!p");
        $this->assertSame(0, $h->mode->cursorShape, 'DECSTR must reset the MODE shape (xterm resets DECSCUSR on soft reset)');
        $this->assertSame(0, $h->cursor->shape, 'DECSTR must reset the RENDERER shape');
        $this->assertShapesAgree($h, 'after DECSTR from shape 4');
    }

    public function testDecstrResetsBlinkingBarShapeOnBothFields(): void
    {
        // Second seed value so the reset target is not an artefact of 4.
        $h = $this->handler(self::BAR . "\x1b[!p");
        $this->assertSame(0, $h->mode->cursorShape);
        $this->assertSame(0, $h->cursor->shape);
        $this->assertShapesAgree($h, 'after DECSTR from shape 5');
    }

    public function testDecstrProgrammaticEntryMatchesTheWireForm(): void
    {
        // softReset() called directly must land in the same shape state as the
        // CSI ! p bytes, so the two entry points cannot drift apart.
        $viaWire = $this->handler(self::BAR . "\x1b[!p");
        $direct = $this->handler(self::BAR);
        $direct->softReset();

        $this->assertSame($viaWire->cursor->shape, $direct->cursor->shape);
        $this->assertSame($viaWire->mode->cursorShape, $direct->mode->cursorShape);
        $this->assertShapesAgree($direct, 'programmatic softReset() from shape 5');
    }

    public function testDecstrKeepsShapeFieldsReSettableAfterwards(): void
    {
        // The reset must not wedge the mode: a later DECSCUSR still sets both
        // fields together, proving softReset() touched a value rather than
        // breaking the wiring.
        $h = $this->handler(self::UNDERLINE . "\x1b[!p");
        (new Parser($h))->feed(self::BAR);
        $this->assertSame(5, $h->cursor->shape, 'DECSCUSR still sets the renderer shape after DECSTR');
        $this->assertSame(5, $h->mode->cursorShape, 'and still sets the mode shape');
        $this->assertShapesAgree($h, 'DECSCUSR after DECSTR');
    }

    // ─── DECSET 1049 (alt screen with save) — xterm leaves style alone ──────

    public function testEnterAltScreenPreservesShapeWhileTheAltScreenIsLive(): void
    {
        // xterm's 1049 entry is `CursorSave(xw); ToAlternate(xw, True);
        // ClearScreen(xw);` (`charproc.c:7732-7745`) and none of it writes
        // cursor style: `SavedCursor` (`ptyx.h:2347-2363`) carries row, col,
        // rendition flags, GL/GR, charsets and colours but NO shape field, and
        // `cursor_shape` is one per-terminal slot (`ptyx.h:2806`), not a
        // per-buffer one, so ToAlternate/SwitchBufs (`charproc.c:9529-9545`,
        // `charproc.c:9564-9594`) never save or restore it. Entering alt must
        // therefore keep 5 on BOTH fields — pre-fix the renderer showed 0.
        $h = $this->handler(self::BAR);
        $h->enterAltScreen();

        $this->assertSame(5, $h->cursor->shape, 'the alt screen keeps the DECSCUSR shape');
        $this->assertSame(5, $h->mode->cursorShape, 'the mode never stopped reporting it');
        $this->assertShapesAgree($h, 'inside the alt screen (1049)');
        $this->assertSame(0, $h->cursor->row, 'while still homing the cursor');
        $this->assertSame(0, $h->cursor->col);
    }

    public function testAltScreenRoundTripKeepsShapeAndAgreementAtEveryStep(): void
    {
        // Sample the invariant at each transition rather than only at the end:
        // a swap that zeroed the shape in one direction and restored it in the
        // other would still "agree" after the round trip while visibly flashing
        // a block cursor inside alt.
        $h = $this->handler(self::UNDERLINE);
        $this->assertShapesAgree($h, 'before entering alt');

        $h->enterAltScreen();
        $this->assertShapesAgree($h, 'inside alt');
        $this->assertSame(4, $h->cursor->shape);

        $h->leaveAltScreen();
        $this->assertShapesAgree($h, 'after leaving alt');
        $this->assertSame(4, $h->cursor->shape, 'the main screen shape is restored intact');
        $this->assertSame(4, $h->mode->cursorShape);
    }

    public function testPrintingInsideAltScreenDoesNotLoseTheShape(): void
    {
        // The realistic sequence: an app sets a bar cursor, enters alt, draws.
        // Nothing in that path is allowed to reintroduce the divergence.
        $h = $this->handler(self::BAR);
        (new Parser($h))->feed("\x1b[?1049hhello");

        $this->assertSame(5, $h->cursor->shape, 'printing in the alt screen keeps the shape');
        $this->assertSame(5, $h->mode->cursorShape);
        $this->assertShapesAgree($h, 'after printing inside alt');
    }

    // ─── DECSET 1048 (cursor-only save) — same per-terminal style argument ──

    public function testEnterAltScreenCursorOnlyPreservesShape(): void
    {
        // 1048 shares the 1049 cursor swap, so the same xterm lines apply
        // (see the 1049 test above for the file:line evidence).
        $h = $this->handler(self::UNDERLINE);
        $h->enterAltScreenCursorOnly();

        $this->assertSame(4, $h->cursor->shape, '1048 must not drop the DECSCUSR shape');
        $this->assertSame(4, $h->mode->cursorShape);
        $this->assertShapesAgree($h, 'inside the alt screen (1048)');
    }

    public function testCursorOnlyAltRoundTripKeepsAgreementAtEveryStep(): void
    {
        $h = $this->handler(self::BAR);
        $this->assertShapesAgree($h, 'before entering alt (1048)');

        $h->enterAltScreenCursorOnly();
        $this->assertShapesAgree($h, 'inside alt (1048)');
        $this->assertSame(5, $h->cursor->shape);

        $h->leaveAltScreenCursorOnly();
        $this->assertShapesAgree($h, 'after leaving alt (1048)');
        $this->assertSame(5, $h->cursor->shape);
        $this->assertSame(5, $h->mode->cursorShape);
    }

    public function testDecset1048ThroughTheWirePreservesShape(): void
    {
        $h = $this->handler(self::BAR . "\x1b[?1048h");
        $this->assertSame(5, $h->cursor->shape, 'the wire form is held to the same standard');
        $this->assertShapesAgree($h, 'CSI ?1048h from shape 5');
    }

    // ─── DECSET 47/1047 — the no-save swap never rebuilt a cursor at all ────

    public function testAltScreenNoSaveLeavesShapeFieldsUntouched(): void
    {
        // Regression guard the other way: enterAltScreenNoSave() swaps only the
        // buffer, so both shape fields must survive untouched. If a future edit
        // starts rebuilding the cursor here it must carry the shape like the
        // 1048/1049 sites do.
        $h = $this->handler(self::UNDERLINE);
        $h->enterAltScreenNoSave();

        $this->assertSame(4, $h->cursor->shape);
        $this->assertSame(4, $h->mode->cursorShape);
        $this->assertShapesAgree($h, 'inside the alt screen (1047, no-save variant)');
    }

    // ─── RIS (ESC c) — full reset, agreement at the default value ───────────

    public function testHardResetLeavesBothShapeFieldsAtDefault(): void
    {
        // hardReset() rebuilds Cursor AND Mode from scratch, so both land on 0
        // and agree. Pinned because it is the one reset where 0 IS the right
        // answer (xterm's `ReallyReset(full=True)` runs the same
        // `charproc.c:14377-14387` cursor block), and because the paired
        // construction is what keeps it consistent — a future edit that reset
        // only one of the two would break here, not silently.
        $h = $this->handler(self::BAR . "\x1bc");
        $this->assertSame(0, $h->cursor->shape);
        $this->assertSame(0, $h->mode->cursorShape);
        $this->assertShapesAgree($h, 'after RIS');
    }

    // ─── DECALN — preserved (already fixed in d110bb398), held to the same rule ─

    public function testDecalnPreservesShapeOnBothFields(): void
    {
        // CASE_DECALN (`charproc.c:4837-4855`) clears ORIGIN and homes via
        // `CursorSet(screen, 0, 0, xw->flags)` without a style argument, so the
        // shape survives. DecalnWireTest owns the wire seam; this pins the same
        // agreement invariant for the path this change class covers.
        $h = $this->handler(self::BAR);
        $h->displayAlignmentTest();

        $this->assertSame(5, $h->cursor->shape, 'DECALN preserves the DECSCUSR shape');
        $this->assertSame(5, $h->mode->cursorShape);
        $this->assertShapesAgree($h, 'after DECALN');
    }

    // ─── DECSC/DECRC — the general save slot must not disturb style either ───

    public function testSaveAndRestoreCursorKeepShapeFieldsInAgreement(): void
    {
        // xterm's DECSC/DECRC (`charproc.c:4906-4924`) move through
        // `SavedCursor`, which has no shape member at all — so style is not
        // part of the save/restore contract in either direction.
        $h = $this->handler(self::BAR . "\x1b[2;3H\x1b7\x1b[1;1H\x1b8");
        $this->assertSame(5, $h->cursor->shape, 'DECRC must not disturb the renderer shape');
        $this->assertSame(5, $h->mode->cursorShape);
        $this->assertShapesAgree($h, 'after DECSC then DECRC');
    }

    // ─── The invariant stated once, for every path this file covers ────────

    public function testCursorAndModeShapeNeverDivergeAcrossEveryRebuildingPath(): void
    {
        // Table-driven sweep of the same transitions through their wire forms,
        // asserting only the invariant. Seeds alternate 4/5 so no row can be
        // satisfied by a shape that was never set.
        $paths = [
            'DECSTR' => "\x1b[!p",
            'DECALN' => "\x1b#8",
            'RIS' => "\x1bc",
            'DECSET 1049h' => "\x1b[?1049h",
            'DECSET 1048h' => "\x1b[?1048h",
            'DECSET 47h' => "\x1b[?47h",
            'DECSC+DECRC' => "\x1b7\x1b8",
            'DECSTR then DECALN' => "\x1b[!p\x1b#8",
            'alt enter then exit' => "\x1b[?1049h\x1b[?1049l",
        ];

        foreach ($paths as $name => $bytes) {
            foreach ([self::UNDERLINE, self::BAR] as $i => $seed) {
                $expected = $i === 0 ? 4 : 5;
                $h = $this->handler($seed . $bytes);

                $this->assertShapesAgree($h, "{$name} seeded from shape {$expected}");

                // The two paths that xterm resets must report 0 on BOTH fields;
                // everything else must still report the seeded value on BOTH.
                $resetPaths = ['DECSTR', 'RIS', 'DECSTR then DECALN'];
                if (in_array($name, $resetPaths, true)) {
                    $this->assertSame(0, $h->cursor->shape, "{$name} must zero the renderer shape");
                    $this->assertSame(0, $h->mode->cursorShape, "{$name} must zero the mode shape");
                } else {
                    $this->assertSame($expected, $h->cursor->shape, "{$name} must preserve the renderer shape");
                    $this->assertSame($expected, $h->mode->cursorShape, "{$name} must preserve the mode shape");
                }
            }
        }
    }
}
