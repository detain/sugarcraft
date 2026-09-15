<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Cursor\Cursor;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Mode\Mode;

/**
 * Cursor-shape agreement across the state-rebuilding paths.
 *
 * The DECSCUSR handler (`CSI Ps SP q`) writes the shape to TWO fields at
 * once — {@see \SugarCraft\Vt\Cursor\Cursor::$shape}, the copy a renderer
 * would read, and {@see \SugarCraft\Vt\Mode\Mode::$cursorShape}, the copy
 * mode state carries. Any site
 * that rebuilds the Cursor with `new Cursor(...)` and omits `shape:`
 * silently zeroes the renderer's copy while the mode keeps its value, so the
 * two disagree. No in-tree consumer renders or reports either field today
 * (`Mode::$cursorShape` is read only by {@see \SugarCraft\Vt\Mode\Mode::equals()}
 * and the reconcile in {@see \SugarCraft\Vt\Handler\ScreenHandler::__construct()},
 * and the candy-vcr rasterizers drive a different Cursor class), which is
 * exactly why the divergence went unnoticed — it is a broken internal
 * invariant and an API contract any future renderer will rely on. Every test
 * here asserts BOTH fields and their agreement. Each BEHAVIOURAL case is
 * seeded from a non-default shape (4, 5 or 6) so it cannot be satisfied by the
 * accidental 0 that is the symptom; the two tests that deliberately pin 0
 * (default construction, and RIS) say so in their own comments.
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
 * | DECSET 1048h cursor  | xterm's 1048 is a bare save/        | preserved |
 * |                      | restore, no swap (7915-7922); ours  |          |
 * |                      | swaps, and style rides through      |          |
 * | DECSET 47h / 1047h   | buffer swap only, cursor untouched  | preserved |
 * | DECSC / DECRC        | `SavedCursor` carries no style      | preserved |
 *
 * ResetTest owns the general reset matrix and DecalnWireTest owns the DECALN
 * wire seam plus its shape guard; this file owns the DECSTR and alt-screen
 * swap sites, and re-tests DECALN only inside the table sweep below, whose
 * thesis is that the invariant holds across ALL of these paths at once.
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

    // ─── Construction: the pair is a pair even before any escape arrives ────

    public function testInjectingACursorCarriesItsShapeIntoTheMode(): void
    {
        // Cursor and Mode are independent optional parameters, so nothing in
        // the signature enforces the invariant the DECSCUSR handler maintains.
        // `new ScreenHandler($b, cursor: new Cursor(shape: 5))` used to yield
        // cursor 5 / mode 0 — diverged at time zero, before any escape could
        // touch it. The un-injected half now follows the injected half.
        $h = new ScreenHandler(new Buffer(8, 4), cursor: new Cursor(shape: 5));
        $this->assertSame(5, $h->cursor->shape);
        $this->assertSame(5, $h->mode->cursorShape, 'the mode must adopt an injected cursor shape');
        $this->assertShapesAgree($h, 'constructed from a lone Cursor(shape: 5)');
    }

    public function testInjectingAModeCarriesItsShapeIntoTheCursor(): void
    {
        // The symmetric case: a caller who seeds mode state gets a renderer
        // copy that agrees with it.
        $mode = (new Mode())->withCursorShape(6);
        $h = new ScreenHandler(new Buffer(8, 4), mode: $mode);
        $this->assertSame(6, $h->mode->cursorShape);
        $this->assertSame(6, $h->cursor->shape, 'the cursor must adopt an injected mode shape');
        $this->assertShapesAgree($h, 'constructed from a lone Mode(cursorShape: 6)');
    }

    public function testDefaultConstructionAgreesAtZero(): void
    {
        // Deliberate 0/0 pin (one of two in this file, the other being RIS): it
        // passes on the pre-fix code too, because its job is to freeze the
        // construction contract, not to detect the bug.
        $h = $this->handler();
        $this->assertSame(0, $h->cursor->shape);
        $this->assertSame(0, $h->mode->cursorShape);
        $this->assertShapesAgree($h, 'freshly constructed with no injected state');
    }

    public function testAltScreenCarryReadsTheCursorFieldNotTheModeField(): void
    {
        // Deliberate probe of WHICH field the alt-screen carry copies. Divergence
        // is unreachable through the escape path (DECSCUSR writes both halves),
        // so the only way to observe the carry's source is the one state the
        // constructor leaves verbatim by design: BOTH halves injected and
        // disagreeing. Here cursor 5 / mode 2, and entering the alt screen must
        // reproduce the CURSOR's 5 — copying from the mode would give 2.
        $h = new ScreenHandler(
            new Buffer(8, 4),
            cursor: new Cursor(shape: 5),
            mode: (new Mode())->withCursorShape(2),
        );
        $this->assertSame(5, $h->cursor->shape, 'an explicitly supplied pair is taken verbatim');
        $this->assertSame(2, $h->mode->cursorShape);

        $h->enterAltScreen();
        $this->assertSame(5, $h->cursor->shape, 'the swap carries the CURSOR shape, not the mode copy');
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
        // CSI ! p bytes. NOTE: the two cross-comparisons below are tautological
        // on purpose — csiDispatch routes `CSI ! p` straight to softReset(), so
        // no implementation can make them differ. They exist to keep the two
        // entry points fused if that routing ever changes; the assertions that
        // actually carry information are the direction pins and the agreement
        // check, which fail on the pre-fix code.
        $viaWire = $this->handler(self::BAR . "\x1b[!p");
        $direct = $this->handler(self::BAR);
        $direct->softReset();

        $this->assertSame($viaWire->cursor->shape, $direct->cursor->shape);
        $this->assertSame($viaWire->mode->cursorShape, $direct->mode->cursorShape);
        $this->assertSame(0, $viaWire->cursor->shape, 'the wire form resets to 0, not merely to "the same"');
        $this->assertSame(0, $viaWire->mode->cursorShape, 'and on both fields');
        $this->assertSame(0, $direct->cursor->shape, 'programmatic call resets too');
        $this->assertSame(0, $direct->mode->cursorShape);
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
        // candy-vt's 1048 shares the 1049 cursor swap, so the same xterm facts
        // apply to the SHAPE (see the 1049 test above for the file:line
        // evidence). xterm itself treats 1048 differently — `srm_SAVE_CURSOR`
        // (ptyx.h:1275) is a bare `CursorSave(xw)`/`CursorRestore(xw)` with no
        // buffer swap (charproc.c:7915-7922) — so the mode number's xterm
        // meaning is cited here only for the style-preservation argument.
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
        // and agree. The second deliberate 0/0 pin in this file (the other is
        // default construction), and it passes on the pre-fix code by design:
        // RIS is the one reset where 0 IS the right
        // answer (xterm's `ReallyReset(full=True)` runs the same
        // `charproc.c:14377-14387` cursor block), and because the paired
        // construction is what keeps it consistent — a future edit that reset
        // only one of the two would break here, not silently.
        $h = $this->handler(self::BAR . "\x1bc");
        $this->assertSame(0, $h->cursor->shape);
        $this->assertSame(0, $h->mode->cursorShape);
        $this->assertShapesAgree($h, 'after RIS');
    }

    // ─── DECALN — preserved and already fixed (d110bb398); covered by the
    //     wire seam in DecalnWireTest and by the table sweep below. ─────────

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
