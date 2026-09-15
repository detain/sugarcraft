<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Handler;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Terminal\Terminal;

/**
 * DECALN on the wire: `ESC # 8` (bytes 1B 23 38) must arm
 * {@see ScreenHandler::displayAlignmentTest()} through the parser, and the
 * '#' intermediate must not be swallowed by charset designation on the way.
 *
 * The authoritative encoding is the ESC-family form, NOT `ESC [ # 8` —
 * VT510 ch.4 ("ESC # 8 invokes the Screen Alignment test"), DEC
 * ansicode.txt `#8 DECALN`, xterm ctlseqs, and the vttest vector
 * `decaln(){ esc("#8"); }`. In xterm the CSI spelling belongs to the
 * palette-stack substate (csi_hash_table[], `CSI # P/Q/R/S`); no surveyed
 * emulator dispatches DECALN from it, so neither do we — the last test in
 * this file pins that the misquote stays inert.
 *
 * ResetTest covers the programmatic entry point and the reset matrix;
 * this file owns the parser→handler seam that made DECALN unreachable
 * from terminal input before (escDispatch routed every non-zero
 * intermediate, '#' included, to designate()).
 *
 * @see https://vt100.net/docs/vt510-rm/DECALN.html (DECALN)
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html (ESC # 8; CSI # = palette stack)
 */
final class DecalnWireTest extends TestCase
{
    private function handler(string $bytes, int $cols = 6, int $rows = 3): ScreenHandler
    {
        $h = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($h))->feed($bytes);
        return $h;
    }

    private function cellRun(ScreenHandler $h, int $row, int $len): string
    {
        $line = '';
        for ($c = 0; $c < $len; $c++) {
            $line .= $h->buffer->cell($row, $c)->grapheme;
        }
        return $line;
    }

    /** State dirty enough that DECALN must visibly reset every dimension. */
    private const DIRTY =
        "\x1b[2;3HNAUGHTY" .   // cursor parked mid-screen, content printed
        "\x1b[2;4r" .          // DECSTBM margins
        "\x1b[41m" .           // SGR pen
        "\x1b(0\x1b)U";        // non-default G0/G1 designations

    // ─── The wire seam (point of the change) ───────────────────────────────

    public function testEscHash8ArmsDecalnFromTheWire(): void
    {
        $h = $this->handler(self::DIRTY . "\x1b#8", cols: 6, rows: 3);

        for ($r = 0; $r < 3; $r++) {
            $this->assertSame('EEEEEE', $this->cellRun($h, $r, 6), "row {$r} filled with E");
        }
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(0, $h->cursor->col, 'cursor homed');
        $this->assertSame(0, $h->scrollRegionTop);
        $this->assertSame(2, $h->scrollRegionBottom, 'margins back to full screen');
        $this->assertNull($h->sgr->background, 'pen reset');
        $this->assertSame(['B', 'B', 'B', 'B'], $h->charsets, 'SCS back to ASCII');
        $this->assertSame(0, $h->gl, 'GL back to G0');
    }

    public function testDecalnCellsCarryTheDefaultRendition(): void
    {
        // Per-cell pen reset: the DIRTY pen (\x1b[41m) must not leak into
        // any E cell — DECALN fills with the DEFAULT rendition.
        $h = $this->handler(self::DIRTY . "\x1b#8", cols: 4, rows: 2);
        for ($r = 0; $r < 2; $r++) {
            for ($c = 0; $c < 4; $c++) {
                $this->assertNull($h->buffer->cell($r, $c)->sgr, "cell {$r},{$c} has no per-cell SGR");
            }
        }
    }

    public function testEscHash8FiresThroughTheTerminalFacadeFeedApi(): void
    {
        // The documented public entry point, not just Parser→ScreenHandler.
        $t = Terminal::new(5, 2);
        $t->feed("hello\r\nworld\x1b#8");
        $this->assertSame('EEEEE', $this->facadeRun($t, 0, 5));
        $this->assertSame('EEEEE', $this->facadeRun($t, 1, 5));
        $this->assertSame(0, $t->cursor()->row);
        $this->assertSame(0, $t->cursor()->col);
    }

    private function facadeRun(Terminal $t, int $row, int $len): string
    {
        $line = '';
        for ($c = 0; $c < $len; $c++) {
            $line .= $t->screen()->cell($row, $c)->grapheme;
        }
        return $line;
    }

    // ─── Not-a-designation: charset regressions impossible ─────────────────

    public function testEscHash8LeavesGlMappingUntouchedAndDesignationStillWorks(): void
    {
        // If `ESC # 8` still reached designate() as a would-be SCS, GL
        // would be remapped or the following designation misrouted. It
        // must not be: after DECALN, G0 is the default ASCII set (which is
        // DECALN's own charset reset, not a designation), a plain 'q'
        // prints as 'q', and a subsequent ESC ( 0 designation still lands
        // and still transforms print output.
        $h = $this->handler("\x1b#8q", cols: 6, rows: 3);
        $this->assertSame('q', $h->buffer->cell(0, 0)->grapheme, 'ESC # 8 must not arm DEC Special Graphics in GL');
        $this->assertSame(0, $h->gl, 'GL invocation unchanged by ESC # 8 alone');

        $h2 = $this->handler("\x1b#8\x1b(0q", cols: 6, rows: 3);
        $this->assertSame('0', $h2->charsets[0], 'ESC ( 0 after DECALN still designates G0');
        $this->assertSame("\u{2500}", $h2->buffer->cell(0, 0)->grapheme, 'and the designation still maps q → box-drawing');
    }

    public function testScsDesignationsStillReachDesignate(): void
    {
        // Full SCS matrix regression: every designating intermediate must
        // keep flowing to designate() exactly as before.
        $h = $this->handler("\x1b(0\x1b)A\x1b*U\x1b+B", cols: 4, rows: 2);
        $this->assertSame(['0', 'A', 'U', 'B'], $h->charsets);

        $ascii = $this->handler("\x1b(B\x1b)B", cols: 4, rows: 2);
        $this->assertSame(['B', 'B', 'B', 'B'], $ascii->charsets, 'explicit ASCII designation intact');
    }

    public function testNonHashIntermediatesKeepHistoricalFallthrough(): void
    {
        // Intermediates that designate() ignores (ESC - .) plus the
        // ESC-family direct dispatches (ESC =) must behave byte-for-byte as
        // before the '#' arm was added. '.' (0x2E) is itself an
        // intermediate-range byte, so `ESC - .` keeps the parser in
        // EscapeIntermediate and the following 'X' is consumed as the
        // dispatch final — nothing prints. '=' (0x3D) dispatches straight
        // from Escape (RKM — unimplemented, a no-op here) and the '8' that
        // follows prints as an ordinary ground byte.
        $h = $this->handler("\x1b(0\x1b-.X", cols: 4, rows: 2);
        $this->assertSame(['0', 'B', 'B', 'B'], $h->charsets, 'ESC - . still a no-op designation');
        $this->assertSame(' ', $h->buffer->cell(0, 0)->grapheme, 'X swallowed as the ESC dispatch final');

        $h2 = $this->handler("\x1b(0\x1b=8X", cols: 4, rows: 2);
        $this->assertSame(['0', 'B', 'B', 'B'], $h2->charsets, 'ESC = still ignored (RKM not modelled)');
        $this->assertSame('8', $h2->buffer->cell(0, 0)->grapheme, "'8' after ESC = is a ground print, not DECALN");
        $this->assertSame('X', $h2->buffer->cell(0, 1)->grapheme);
    }

    // ─── Negative: unsupported '#' finals must not corrupt state ───────────

    public function testUnsupportedHashFinalsRemainSilentIgnores(): void
    {
        // DECDHL (ESC # 3 / # 4) and DECSWL/DECDWL (ESC # 5 / # 6) are NOT
        // implemented — this test pins their ACTUAL current behaviour: they
        // fall through to designate(), which treats '#' as a non-designator
        // and ignores them. Screen, pen, margins, cursor and GL/SCS state
        // must be exactly what DIRTY left behind.
        foreach ([0x30, 0x33, 0x34, 0x35, 0x36] as $final) {
            $before = $this->handler(self::DIRTY, cols: 6, rows: 3);
            $after = $this->handler(self::DIRTY . chr(0x1b) . '#' . chr($final), cols: 6, rows: 3);
            $this->assertSame(
                $this->cellRun($before, 0, 6) . $this->cellRun($before, 1, 6) . $this->cellRun($before, 2, 6),
                $this->cellRun($after, 0, 6) . $this->cellRun($after, 1, 6) . $this->cellRun($after, 2, 6),
                sprintf('ESC # %s must not alter the screen', chr($final)),
            );
            $this->assertSame($before->charsets, $after->charsets);
            $this->assertSame($before->gl, $after->gl);
            $this->assertSame($before->cursor->row, $after->cursor->row);
            $this->assertSame($before->cursor->col, $after->cursor->col);
            $this->assertSame($before->scrollRegionTop, $after->scrollRegionTop);
            $this->assertSame($before->scrollRegionBottom, $after->scrollRegionBottom);
        }
    }

    // ─── Cross-lib seam: candy-core emitter → candy-vt emulator ────────────

    public function testAnsiDecalnRoundTripMatchesProgrammaticCall(): void
    {
        // The broken seam this change closes: Ansi::decaln() emits the
        // bytes ESC # 8 (1B 23 38). Feeding them must land the emulator in
        // exactly the state displayAlignmentTest() produces directly.
        $viaWire = $this->handler(self::DIRTY . Ansi::decaln(), cols: 6, rows: 3);
        $direct = $this->handler(self::DIRTY, cols: 6, rows: 3);
        $direct->displayAlignmentTest();

        $this->assertSame(Ansi::decaln(), "\x1b#8", 'candy-core emits the DEC encoding');
        for ($r = 0; $r < 3; $r++) {
            for ($c = 0; $c < 6; $c++) {
                $this->assertEquals(
                    $direct->buffer->cell($r, $c),
                    $viaWire->buffer->cell($r, $c),
                    "cell {$r},{$c} identical between wire and programmatic DECALN",
                );
            }
        }
        $this->assertSame($direct->cursor->row, $viaWire->cursor->row);
        $this->assertSame($direct->cursor->col, $viaWire->cursor->col);
        $this->assertSame($direct->charsets, $viaWire->charsets);
        $this->assertSame($direct->gl, $viaWire->gl);
        $this->assertSame($direct->scrollRegionTop, $viaWire->scrollRegionTop);
        $this->assertSame($direct->scrollRegionBottom, $viaWire->scrollRegionBottom);
    }

    // ─── The misquote stays inert ──────────────────────────────────────────

    public function testCsiHash8MisquoteDoesNotArmDecaln(): void
    {
        // `ESC [ # 8` is a circulating transcription error (see class
        // docblock): xterm's CSI '#' substate treats the 8 as a collected
        // digit waiting for a `P/Q/R/S` colour-stack final, and the
        // candy-ansi transition table we mirror drops it to Ground without
        // dispatch. Deliberately NOT accepted; must stay a screen no-op so
        // a future "fix" that makes it dispatch is caught here.
        $h = $this->handler("\x1b[#8X", cols: 6, rows: 3);
        $this->assertSame('X', $this->cellRun($h, 0, 6)[0], 'screen not filled with E');
        $this->assertNotSame('EEEEEE', $this->cellRun($h, 0, 6));
        $this->assertSame(0, $h->cursor->row, 'cursor not homed');
        $this->assertSame(1, $h->cursor->col, 'only the printed X landed');
    }
}
