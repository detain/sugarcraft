<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;

/**
 * The two DECALN resets nothing else reaches.
 *
 * {@see ScreenHandler::displayAlignmentTest()} clears nine pieces of state.
 * {@see DecalnWireTest} pins the E-fill, the homing, the margins, the pen, the
 * DECOM clear, the SCS/GL reset and the cursor-shape carry-through; ResetTest
 * pins the programmatic reset matrix. Still unpinned for DECALN were the armed
 * SS2/SS3 single shift and the deferred-wrap flag — the only `wrapPending`
 * assertions ResetTest makes sit after RIS/DECSTR on a handler whose DIRTY
 * never prints into the last column, so the flag was never armed and they hold
 * no matter what the reset does (the never-armed control in
 * {@see self::testPendingWrapPinNeedsTheArmedFlag()} shows exactly that vacuity;
 * `singleShift` is asserted nowhere in this lib's tests before this file).
 *
 * Both dimensions are therefore pinned the only way that bites: ARM the state,
 * assert it armed, run DECALN, assert it cleared — then assert the *user-visible
 * consequence*, which is where a stale flag actually does damage. A surviving
 * single shift silently overrides the next printable with a charset the
 * alignment test already discarded; a surviving phantom cell makes the first
 * print after DECALN jump to the next row as if the screen had wrapped.
 *
 * `ESC # 8` is the wire form (VT510 ch.4, DEC ansicode.txt `#8 DECALN`); the
 * emitter→emulator equivalence for `Ansi::decaln()` and the inertness of the
 * `ESC [ # 8` misquote are owned by DecalnWireTest and not repeated here.
 *
 * @see https://vt100.net/docs/vt510-rm/DECALN.html (DECALN)
 * @see DecalnWireTest (the parser→handler seam and the rest of the reset matrix)
 */
final class DecalnResetPinTest extends TestCase
{
    /** DECALN — `ESC # 8` (bytes 1B 23 38). */
    private const DECALN = "\x1b#8";

    private function handler(int $cols = 10, int $rows = 3): ScreenHandler
    {
        return new ScreenHandler(new Buffer($cols, $rows));
    }

    private function feed(ScreenHandler $h, string $bytes): void
    {
        (new Parser($h))->feed($bytes);
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string}>
     */
    public static function singleShiftArms(): array
    {
        // This emulator arms the single shift ONLY from the 8-bit C1 bytes
        // 0x8E/0x8F. Their ECMA-48 7-bit forms `ESC N`/`ESC O` (SS2/SS3) are
        // not implemented — they fall through escDispatch()'s default arm, as
        // do the ECMA-48 locking shifts `ESC J`/`ESC K` (LS2/LS3). The GL
        // locking shifts this library does implement are the DEC spellings
        // `ESC n`/`ESC o`, which src labels LS2/LS3 following charmbracelet;
        // they move GL and never set a single shift. So the C1 pair below is
        // the only route into $singleShift, which is the state DECALN drops.
        return [
            'SS2 arms G2' => ["\x8e", 2, "\x1b*0"],
            'SS3 arms G3' => ["\x8f", 3, "\x1b+0"],
        ];
    }

    // ─── The armed single shift ────────────────────────────────────────────

    /**
     * Control first: the arm byte really does redirect one printable through
     * the designated set. Without this the DECALN assertion below could pass
     * on a handler that never supported SS2/SS3 at all.
     *
     * @dataProvider singleShiftArms
     */
    public function testSingleShiftArmActuallyRedirectsTheNextPrintable(string $arm, int $slot, string $designate): void
    {
        $h = $this->handler();
        $this->assertNull($h->singleShift, 'a fresh handler has no armed shift');

        $this->feed($h, $arm);
        $this->assertSame(
            $slot,
            $h->singleShift,
            sprintf('C1 0x%s arms the shift onto G%d', strtoupper(bin2hex($arm)), $slot),
        );

        $this->feed($h, $designate);
        $this->assertSame('0', $h->charsets[$slot], "designation lands on the shifted slot");

        $this->feed($h, 'q');
        $this->assertSame("\u{2500}", $h->buffer->cell(0, 0)->grapheme, 'the shifted printable renders from DEC Special Graphics');
        $this->assertNull($h->singleShift, 'and is consumed by that one printable');
    }

    /**
     * @dataProvider singleShiftArms
     */
    public function testDecalnClearsTheArmedSingleShift(string $arm, int $slot, string $designate): void
    {
        $h = $this->handler();
        $this->feed($h, $arm . $designate);
        $this->assertSame($slot, $h->singleShift, 'precondition: shift armed BEFORE DECALN');

        $this->feed($h, self::DECALN);
        $this->assertNull($h->singleShift, 'DECALN must drop the armed SS2/SS3 shift');

        // Consequence: the set the shift pointed at has to be re-designated
        // before it can leak — if the shift survived, this 'q' would render
        // '\u{2500}' from the surviving G2/G3 override instead of plain ASCII.
        $this->feed($h, $designate . 'q');
        $this->assertSame('q', $h->buffer->cell(0, 0)->grapheme, 'no stale shift may fire on the first printable after DECALN');
    }

    // ─── The deferred-wrap (phantom cell) flag ─────────────────────────────

    /**
     * Control: printing into the last column with DECAWM on leaves the cursor
     * parked on the phantom cell with the wrap pending — the state DECALN is
     * supposed to disarm.
     */
    public function testPrintAtRightMarginArmsThePendingWrap(): void
    {
        $h = $this->handler();
        $this->assertTrue($h->mode->autoWrap, 'DECAWM boots on (VT100)');

        $this->feed($h, "\x1b[1;10HZ");

        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(9, $h->cursor->col, 'cursor stays on the last column');
        $this->assertTrue($h->wrapPending, 'precondition: wrap pending after printing the right margin');
    }

    public function testDecalnClearsThePendingWrap(): void
    {
        $h = $this->handler();
        $this->feed($h, "\x1b[1;10HZ");
        $this->assertTrue($h->wrapPending, 'precondition: wrap armed BEFORE DECALN');

        $this->feed($h, self::DECALN);

        $this->assertFalse($h->wrapPending, 'DECALN must disarm the pending wrap');
        $this->assertSame(0, $h->cursor->row, 'and home the cursor');
        $this->assertSame(0, $h->cursor->col);
        // xterm CASE_DECALN clears the pending-wrap FLAG, never the DECAWM
        // MODE — a "fix" that resets the mode here would silently turn off
        // autowrap for the rest of the session.
        $this->assertTrue($h->mode->autoWrap, 'DECAWM mode itself stays on');
    }

    public function testFirstPrintAfterDecalnDoesNotJumpToTheNextRow(): void
    {
        // The user-visible half of the pin, asserted before the flag itself:
        // with a surviving phantom cell the very first print after the
        // alignment test wraps to row 1 and the top-left corner keeps its 'E'.
        $h = $this->handler();
        $this->feed($h, "\x1b[1;10HZ" . self::DECALN);

        $this->feed($h, 'Y');

        $this->assertSame('Y', $h->buffer->cell(0, 0)->grapheme, 'print lands on the homed cursor instead of late-wrapping');
        $this->assertSame('E', $h->buffer->cell(1, 0)->grapheme, 'nothing spilled onto the next row');
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col);
        $this->assertFalse($h->wrapPending, 'the flag behind it is disarmed');
    }

    /**
     * Why the arm-first preconditions above are not ceremony: on a handler
     * whose state was never armed, the same post-DECALN assertions are true of
     * the initial state and would pass even if DECALN did nothing at all.
     */
    public function testPendingWrapPinNeedsTheArmedFlag(): void
    {
        $neverArmed = $this->handler();
        $this->feed($neverArmed, 'Z' . self::DECALN);
        $this->assertFalse($neverArmed->wrapPending, 'vacuously false — never armed');

        $armed = $this->handler();
        $this->feed($armed, "\x1b[1;10HZ");
        $this->assertTrue($armed->wrapPending, 'armed — this is the state the pin must clear');
    }
}
