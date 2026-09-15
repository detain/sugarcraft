<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Ansi\Parser\DebugHandler;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Vt\Buffer\Buffer;
use SugarCraft\Vt\Charset\Charsets;
use SugarCraft\Vt\Handler\ScreenHandler;
use SugarCraft\Vt\Terminal\Terminal as EmulatorTerminal;

/**
 * Round-trip guard between the two halves of the ANSI wire that live in
 * different libs: the byte strings candy-core emits ({@see Ansi}) and what
 * candy-vt's VT500 parser + emulator actually make of them.
 *
 * Why the test lives HERE: candy-vt `require`s candy-core, so core can never
 * depend back on the emulator without a cycle — the only suites that can see
 * both sides are the ones that already dev-depend on vt. Same reason
 * {@see VtParityTest} lives here rather than in candy-vt.
 *
 * Each case asserts the intended *effect*, not merely that the bytes were
 * consumed, because an emitter whose receiver ignores it is exactly the defect
 * class the audit logged (X-5/X-6/X-7: core had no RIS/DECALN/SCS emitters at
 * all, while vt had just gained the handlers).
 *
 * @see docs/research/ansi-tmux-ansicode-audit.md
 */
final class CoreEmitterRoundTripTest extends TestCase
{
    private const COLS = 12;
    private const ROWS = 3;

    private function feed(string $bytes, int $cols = self::COLS, int $rows = self::ROWS): ScreenHandler
    {
        $handler = new ScreenHandler(new Buffer($cols, $rows));
        (new Parser($handler))->feed($bytes);
        return $handler;
    }

    private function row(ScreenHandler $h, int $index = 0): string
    {
        $line = '';
        for ($c = 0; $c < $h->buffer->cols; $c++) {
            $line .= $h->buffer->cell($index, $c)->grapheme;
        }
        return rtrim($line);
    }

    // ─── RIS (ESC c) ─────────────────────────────────────────────────────────

    public function testRisDispatchesAsAPlainEscapeFinal(): void
    {
        // No intermediate, no parameters — the parser must hand the handler
        // esc('c', 0). Any stray introducer byte would show up here first.
        $debug = new DebugHandler();
        (new Parser($debug))->feed(Ansi::ris() . 'E');

        $this->assertSame(
            [['type' => 'esc', 'detail' => ['final' => 0x63, 'intermediate' => 0]]],
            $debug->filter('esc'),
        );
        $this->assertSame(['E'], array_column($debug->filter('print'), 'detail'), 'RIS must not eat the next graphic');
    }

    public function testRisEmitterClearsTheScreenAndHomesTheCursor(): void
    {
        $terminal = EmulatorTerminal::new(self::COLS, self::ROWS);
        $terminal->feed("ABCDEF\r\nGHIJKL\r\n");
        $this->assertNotSame('', $this->rowOf($terminal));

        $terminal->feed(Ansi::ris());

        $this->assertSame('', $this->rowOf($terminal));
        $this->assertSame(0, $terminal->cursor()->row);
        $this->assertSame(0, $terminal->cursor()->col);
    }

    public function testRisEmitterRestoresEveryDesignationAndModeItTouches(): void
    {
        // Dirty the state the emitter's docblock promises RIS restores: two SCS
        // designations, the GL shift, a single shift, the pen and a DEC mode.
        // The dirtiness is asserted BEFORE the reset so the test is self-
        // contained: if the prefix machinery ever stopped working, this fails on
        // "not dirty" instead of passing vacuously on "back at power-on".
        $handler = new ScreenHandler(new Buffer(self::COLS, self::ROWS));
        $parser = new Parser($handler);
        $parser->feed(
            Ansi::scsG0(Ansi::CHARSET_DEC_SPECIAL)
            . Ansi::scsG1(Ansi::CHARSET_UK)
            . Ansi::shiftOut()
            . "\x8f",
        );
        $parser->feed("\x1b[7m\x1b[?25l");

        $this->assertSame(Charsets::DEC_SPECIAL, $handler->charsets[0], 'G0 must be dirty first');
        $this->assertSame(Charsets::UK, $handler->charsets[1], 'G1 must be dirty first');
        $this->assertSame(1, $handler->gl, 'SO must be armed first');
        $this->assertSame(3, $handler->singleShift, 'SS3 must be armed first');
        $this->assertFalse($handler->mode->cursorVisible, 'DECTCEM must be off first');
        $this->assertTrue($handler->sgr->reverse, 'the pen must be set first');

        $parser->feed(Ansi::ris());

        $this->assertSame([Charsets::ASCII, Charsets::ASCII, Charsets::ASCII, Charsets::ASCII], $handler->charsets);
        $this->assertSame(0, $handler->gl, 'SO must be undone by RIS');
        $this->assertNull($handler->singleShift);
        $this->assertTrue($handler->mode->cursorVisible, 'DECTCEM returns to its power-on value');
        $this->assertFalse($handler->sgr->reverse, 'the pen returns to default rendition');
        // Nothing was printed above, so this only trips if hardReset() scribbles
        // on the buffer; the clear itself is pinned non-vacuously by
        // testRisEmitterClearsTheScreenAndHomesTheCursor.
        $this->assertSame('', $this->row($handler));
    }

    // ─── DECALN (ESC # 8) ────────────────────────────────────────────────────

    /**
     * Receiver state dirtied on the dimensions the DECALN tests below actually
     * pin — grid content, cursor position, the scroll-region top edge, DECOM,
     * the SGR pen and the G0 designation — so those assertions are discriminating
     * rather than passing on values already at their default. (displayAlignmentTest
     * also resets gl and carries the cursor shape through — both pinned by
     * candy-vt's own DecalnWireTest — and clears singleShift/wrapPending, which no
     * suite pins yet and which is deliberately out of scope here.) The
     * cursor park comes LAST because `ESC [ r` (DECSTBM) homes the cursor as a
     * side effect — parking before it would let "cursor homed" pass whether or
     * not DECALN ever fired.
     */
    private const DECALN_DIRTY =
        "\x1b[2;3r"       // DECSTBM region top=1, bottom=2 (bottom is only discriminating on the 5-row ARMS buffer)
        . "\x1b[?6h"      // DECOM origin mode ON
        . "\x1b[41m"      // SGR background colour — a non-default pen
        . "\x1b(0"        // G0 designated DEC Special Graphics
        . "\x1b[3;5HW";   // park the cursor at (2,5) and print a content marker

    public function testDecalnEmitterDispatchesWithTheHashIntermediate(): void
    {
        // The single most important property of this emitter: `#` must arrive as
        // the INTERMEDIATE (0x23) and `8` as the FINAL (0x38) of one complete
        // escape, so the handler receives them as one DEC-family dispatch. The
        // `ESC [ # 8` spelling people reach for is a misquote of xterm's
        // palette-stack substate (`CSI # P/Q/R/S`, where the `8` is a collected
        // digit awaiting a colour final, not a final of its own): candy-ansi
        // closes CsiIntermediate on a 0x30-0x3F byte by returning to Ground, so
        // the CSI form never dispatches DECALN — and because it recovers to
        // Ground rather than stranding mid-sequence, the next graphic still
        // prints. That inertness is pinned negatively by
        // {@see testCsiHash8MisquoteDoesNotArmDecaln}.
        $debug = new DebugHandler();
        (new Parser($debug))->feed(Ansi::decaln() . 'E');

        $this->assertSame(
            [['type' => 'esc', 'detail' => ['final' => 0x38, 'intermediate' => 0x23]]],
            $debug->filter('esc'),
        );
        $this->assertSame(['E'], array_column($debug->filter('print'), 'detail'));
    }

    public function testDecalnEmitterArmsTheAlignmentFillAndResetsTheReceiver(): void
    {
        // The full emitter→emulator round trip of the DECALN contract (VT510
        // ch.4). Since PR #1431 made {@see ScreenHandler::escDispatch()} intercept
        // the `#` (0x23) intermediate and dispatch final 0x38 to
        // displayAlignmentTest() instead of swallowing it in designate(), feeding
        // Ansi::decaln() must actually RUN the alignment test on the receiver —
        // not merely be consumed. Every post-DECALN effect assertion below is red
        // under the pre-#1431 parser, where `ESC # 8` fell through to designate()
        // and was ignored: the grid would keep the DIRTY content instead of the E
        // field, the cursor would stay parked off-origin, both scroll-region edges
        // would keep their non-default DECSTBM values, DECOM would stay on and the
        // pen would stay red. The dirtiness is asserted BEFORE the sequence (the
        // same self-contained discipline as the RIS tests) so a silent no-op cannot
        // masquerade as a reset of values already at their defaults.
        $this->assertSame("\x1b#8", Ansi::decaln(), 'the emitter must put ESC # 8 (1B 23 38) on the wire');

        // A buffer two rows taller than the class default so DECSTBM's lower edge
        // (2) differs from the full-screen reset value (rows-1): on the 3-row
        // default the bottom edge would coincide with DECALN's reset and that one
        // assertion would carry no signal. The parked cursor is read back rather
        // than hard-coded, because its landing is buffer-height dependent.
        $rows = self::ROWS + 2;
        $handler = new ScreenHandler(new Buffer(self::COLS, $rows));
        $parser = new Parser($handler);
        $parser->feed(self::DECALN_DIRTY);

        $parkRow = $handler->cursor->row;
        $parkCol = $handler->cursor->col;
        $this->assertNotSame(0, $parkRow, 'cursor parked off the top row first');
        $this->assertGreaterThan(0, $parkCol, 'cursor parked off the left margin first');
        $this->assertSame('W', $handler->buffer->cell($parkRow, $parkCol - 1)->grapheme, 'pre-DECALN content present first');
        $this->assertNotSame(0, $handler->scrollRegionTop, 'DECSTBM top edge off the page first');
        $this->assertNotSame($rows - 1, $handler->scrollRegionBottom, 'DECSTBM bottom edge off the page first');
        $this->assertTrue($handler->mode->originMode, 'DECOM armed first');
        $this->assertNotNull($handler->sgr->background, 'pen coloured first');
        $this->assertSame(Charsets::DEC_SPECIAL, $handler->charsets[0], 'a non-ASCII designation set first');

        $parser->feed(Ansi::decaln());

        // Every cell of the live grid, row by row and column by column — not a
        // single probe that a partial fill could slip past.
        for ($r = 0; $r < $rows; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                $this->assertSame('E', $handler->buffer->cell($r, $c)->grapheme, "DECALN must fill cell ({$r},{$c})");
            }
        }
        $this->assertSame(0, $handler->cursor->row, 'DECALN homes the cursor');
        $this->assertSame(0, $handler->cursor->col, 'DECALN homes the cursor');
        $this->assertSame(0, $handler->scrollRegionTop, 'DECALN resets the scroll region top to the page edge');
        $this->assertSame($rows - 1, $handler->scrollRegionBottom, 'DECALN resets the scroll region bottom to the page edge');
        $this->assertFalse($handler->mode->originMode, 'DECALN clears DECOM (xterm-411 UIntClr ORIGIN)');
        $this->assertNull($handler->sgr->background, 'DECALN resets the SGR pen');
        $this->assertSame(
            [Charsets::ASCII, Charsets::ASCII, Charsets::ASCII, Charsets::ASCII],
            $handler->charsets,
            'DECALN restores the default ASCII designations',
        );
    }

    public function testDecalnLeavesTheReceiverReadyForTheNextGraphic(): void
    {
        // Whatever follows DECALN must still be rendered, never swallowed as the
        // final byte of a half-built sequence. DECALN homes the cursor, so the
        // trailing 'Z' lands on (0,0); pinning the E fill behind it at (0,1) is
        // what turns this from a bare "the byte printed" check (which the old
        // ignore-DECALN receiver also passed) into a real DECALN tripwire — the
        // alignment fill must have run for that cell to hold 'E'.
        $h = $this->feed(Ansi::decaln() . 'Z');

        $this->assertSame('Z', $h->buffer->cell(0, 0)->grapheme, 'the graphic after DECALN prints at the homed cursor');
        $this->assertSame('E', $h->buffer->cell(0, 1)->grapheme, 'DECALN filled the row behind the trailing graphic');
        $this->assertSame(0, $h->cursor->row);
        $this->assertSame(1, $h->cursor->col, 'the trailing graphic advanced the cursor one cell');
    }

    public function testCsiHash8MisquoteDoesNotArmDecaln(): void
    {
        // Negative pin, consistent with candy-vt's
        // DecalnWireTest::testCsiHash8MisquoteDoesNotArmDecaln. `ESC [ # 8` is
        // the circulating misquote (xterm's palette-stack `CSI # P/Q/R/S`), not
        // the DEC encoding, and must NOT arm DECALN. Under the correct parser the
        // sequence is dropped to Ground without dispatch, so every dimension the
        // alignment test touches is left exactly as DIRTY set it, AND the graphic
        // that follows still prints in place (the misquote recovers to Ground
        // rather than stranding — vt's DecalnWireTest pins the same recovery with
        // its trailing 'X'). Each assertion here goes red the moment someone
        // "fixes" the parser to dispatch DECALN from the CSI spelling.
        $probe = $this->feed(self::DECALN_DIRTY);
        $parkRow = $probe->cursor->row;
        $parkCol = $probe->cursor->col;
        $this->assertGreaterThan(0, $parkRow, 'the dirty prefix parks the cursor off-origin');

        $h = $this->feed(self::DECALN_DIRTY . "\x1b[#8Z");

        $this->assertSame('W', $h->buffer->cell($parkRow, $parkCol - 1)->grapheme, 'pre-sequence content survived — the screen was not filled with E');
        $this->assertSame('Z', $h->buffer->cell($parkRow, $parkCol)->grapheme, 'the graphic after the misquote printed in place — recovered to Ground, not swallowed');
        // Probing a never-written row rather than cell(0,0): under an (incorrectly)
        // armed reading DECALN homes the cursor to (0,0) and the trailing 'Z' then
        // overwrites it, so "cell(0,0) is not E" would pass in the very failure case
        // it claims to catch. Row 1 is untouched by DECALN_DIRTY, so it can only be
        // blank if the alignment fill never ran.
        $this->assertSame('', $this->row($h, 1), 'no E field anywhere — the never-written middle row stays blank');
        $this->assertSame($parkRow, $h->cursor->row, 'the cursor stayed on its parked row — DECALN did not home it');
        $this->assertSame($parkCol + 1, $h->cursor->col, 'only the trailing graphic advanced the cursor one cell');
        $this->assertSame(1, $h->scrollRegionTop, 'the scroll region top was not reset');
        $this->assertTrue($h->mode->originMode, 'DECOM was not cleared');
        $this->assertNotNull($h->sgr->background, 'the pen was not reset');
    }

    public function testAlignmentHandlerStillFillsTheScreenWhenInvokedProgrammatically(): void
    {
        // The programmatic entry point {@see ScreenHandler::displayAlignmentTest()}
        // pinned directly — the very method the `ESC # 8` wire sequence now
        // dispatches to since PR #1431. The wire seam itself is covered by
        // {@see testDecalnEmitterArmsTheAlignmentFillAndResetsTheReceiver}; this is
        // the direct-API guarantee other libs (and candy-vt's own reset matrix)
        // rely on, so the two entry points cannot drift apart.
        $h = $this->feed(self::DECALN_DIRTY);
        $h->displayAlignmentTest();

        for ($r = 0; $r < self::ROWS; $r++) {
            for ($c = 0; $c < self::COLS; $c++) {
                $this->assertSame('E', $h->buffer->cell($r, $c)->grapheme, "programmatic DECALN fills cell ({$r},{$c})");
            }
        }
        $this->assertSame(0, $h->cursor->row, 'programmatic DECALN homes the cursor');
        $this->assertSame(0, $h->scrollRegionTop, 'programmatic DECALN resets the region top');
        $this->assertFalse($h->mode->originMode, 'programmatic DECALN clears DECOM');
        $this->assertNull($h->sgr->background, 'programmatic DECALN resets the pen');
    }

    // ─── SCS (ESC ( ) * + F) ─────────────────────────────────────────────────

    public function testDesignatorRosterMatchesWhatTheParserRecognises(): void
    {
        // Core names the designators; vt decides which ones mean anything. If
        // either roster drifts alone, an emitter would produce a designation the
        // receiver silently discards — the failure this pins out. Read through
        // reflection so the comparison stays a genuine runtime one: a literal
        // `assertSame('B', 'B')` would survive a rename in either lib and go on
        // comparing two stale string literals forever, which is why the constant
        // names are checked here rather than only their values.
        $core = new \ReflectionClass(Ansi::class);
        $emulator = new \ReflectionClass(Charsets::class);
        $pairs = [
            'CHARSET_ASCII' => 'ASCII',
            'CHARSET_DEC_SPECIAL' => 'DEC_SPECIAL',
            'CHARSET_UK' => 'UK',
            'CHARSET_NO_BREAK_SPACE' => 'NO_BREAK_SPACE',
        ];

        foreach ($pairs as $coreConstant => $emulatorConstant) {
            // getConstant() yields false for a name that does not exist, so a
            // symmetric rename on both sides would compare false === false and
            // pass with the mapping silently retired. Demand the names first.
            $this->assertTrue(
                $core->hasConstant($coreConstant) && $emulator->hasConstant($emulatorConstant),
                "candy-core Ansi::{$coreConstant} and candy-vt Charsets::{$emulatorConstant} must both exist",
            );
            $this->assertSame(
                $emulator->getConstant($emulatorConstant),
                $core->getConstant($coreConstant),
                "candy-core Ansi::{$coreConstant} must name the same designator as candy-vt Charsets::{$emulatorConstant}",
            );
        }

        // …and the other direction: neither roster may grow alone. The pair map
        // above is hand-written, so without this a charset added on the vt side
        // would silently have no emitter (and vice versa). This compares every
        // public constant on both classes, so an unrelated public constant added
        // to either side also trips it — deliberately loud rather than permissive.
        $collect = static function (\ReflectionClass $class, string $prefix = ''): array {
            $values = [];
            foreach ($class->getReflectionConstants() as $constant) {
                if ($constant->isPublic() && ($prefix === '' || str_starts_with($constant->getName(), $prefix))) {
                    $value = $constant->getValue();
                    // Every designator is a single byte, so a non-string here is a
                    // roster bug worth failing on — and it keeps the sorted lists
                    // comparable instead of letting a mixed value pick its own order.
                    if (!\is_string($value)) {
                        throw new \UnexpectedValueException("{$class->getName()}::{$constant->getName()} must hold a string designator");
                    }
                    $values[] = $value;
                }
            }
            // Deterministic ordering for the comparison below.
            sort($values, SORT_STRING);
            return $values;
        };
        $this->assertSame(
            $collect($emulator),
            $collect($core, 'CHARSET_'),
            'Ansi::CHARSET_* and Charsets::* must cover exactly the same designators',
        );
    }

    public function testScsG0DesignationDrawsTheDecSpecialGraphicsFrame(): void
    {
        // ESC ( 0 then lqqqqk must paint the box corner/edge run, proving the
        // designation both parsed and became the active GL charset.
        $h = $this->feed(Ansi::decSpecialGraphics() . 'lqqqqk');
        $this->assertSame('┌────┐', $this->row($h));
    }

    public function testScsG0WithAsciiDesignatorRestoresPlainText(): void
    {
        $h = $this->feed(Ansi::decSpecialGraphics() . 'lqq' . Ansi::asciiCharset() . 'lqq');
        $this->assertSame('┌──lqq', $this->row($h));
    }

    public function testScsG1DesignationTakesEffectOnShiftOut(): void
    {
        // G1 is inert until LS1 (SO) moves it into GL — which is why the emitter
        // pair ships together.
        $h = $this->feed(Ansi::scsG1(Ansi::CHARSET_DEC_SPECIAL) . Ansi::shiftOut() . 'lqq');
        $this->assertSame('┌──', $this->row($h));
        $this->assertSame(Charsets::DEC_SPECIAL, $h->charsets[1]);
        $this->assertSame(1, $h->gl);

        $h2 = $this->feed(
            Ansi::scsG1(Ansi::CHARSET_DEC_SPECIAL) . Ansi::shiftOut() . 'lqq' . Ansi::shiftIn() . 'lqq',
        );
        $this->assertSame('┌──lqq', $this->row($h2), 'SI must hand GL back to G0');
    }

    public function testScsG2AndG3DesignationsTakeEffectOnSingleShift(): void
    {
        // SS2/SS3 are the C1 bytes 0x8E/0x8F. candy-core deliberately has no
        // emitter for them: the audit shows candy-input still mis-decodes the
        // 7-bit ESC N / ESC O spellings, so single shifts stay a raw-byte
        // concern here until that lands.
        $g2 = $this->feed(Ansi::scsG2(Ansi::CHARSET_DEC_SPECIAL) . "\x8e" . 'lqq');
        $this->assertSame('┌qq', $this->row($g2), 'SS2 selects G2 for one graphic only');
        $this->assertSame(Charsets::DEC_SPECIAL, $g2->charsets[2]);

        $g3 = $this->feed(Ansi::scsG3(Ansi::CHARSET_UK) . "\x8f" . '#');
        $this->assertSame('£', $this->row($g3), 'SS3 selects G3 for one graphic only');
        $this->assertSame(Charsets::UK, $g3->charsets[3]);
    }

    public function testScsDesignatorsTheParserDoesNotModelLeaveTheCharsetAlone(): void
    {
        // Fail-soft on the receiving end (candy-vt ignores unknown finals). The
        // emitter's job is to produce a syntactically complete sequence, which
        // this proves by showing the next graphic still prints normally.
        $h = $this->feed(Ansi::scsG0('q') . 'abc');
        $this->assertSame('abc', $this->row($h));
        $this->assertSame(Charsets::ASCII, $h->charsets[0]);
    }

    public function testScsEmittersDispatchTheirOwnIntermediateAndFinal(): void
    {
        // Byte-level contract with the shared parser for every slot, so a
        // refactor of the slot table cannot quietly swap two of them.
        $expected = [
            Ansi::scsG0('0') => ['final' => 0x30, 'intermediate' => 0x28],
            Ansi::scsG1('0') => ['final' => 0x30, 'intermediate' => 0x29],
            Ansi::scsG2('0') => ['final' => 0x30, 'intermediate' => 0x2a],
            Ansi::scsG3('0') => ['final' => 0x30, 'intermediate' => 0x2b],
        ];
        foreach ($expected as $bytes => $want) {
            $debug = new DebugHandler();
            (new Parser($debug))->feed($bytes);
            $this->assertSame([$want], array_column($debug->filter('esc'), 'detail'));
        }
    }

    // ─── A whole frame, end to end ──────────────────────────────────────────

    public function testAComposedFrameRoundTripsThroughTheEmulator(): void
    {
        // The realistic call pattern: designate, draw a rule, restore ASCII,
        // then tear the session down with RIS — every step emitted by candy-core.
        $frame = Ansi::decSpecialGraphics()
            . 'lqqqqk'
            . Ansi::asciiCharset()
            . "\r\n"
            . 'aligned'
            . Ansi::ris();

        $terminal = EmulatorTerminal::new(self::COLS, self::ROWS);
        $terminal->feed($frame);

        // RIS at the tail means the visible result is an empty screen at home;
        // the point is that the whole frame parsed without desynchronising.
        $this->assertSame('', $this->rowOf($terminal));
        $this->assertSame(0, $terminal->cursor()->row);

        // Same frame minus the teardown must have drawn the box.
        $drawn = EmulatorTerminal::new(self::COLS, self::ROWS);
        $drawn->feed(substr($frame, 0, -strlen(Ansi::ris())));
        $this->assertSame('┌────┐', $this->rowOf($drawn));
        $this->assertSame('aligned', $this->rowOf($drawn, 1));
    }

    private function rowOf(EmulatorTerminal $terminal, int $index = 0): string
    {
        $line = '';
        for ($c = 0; $c < self::COLS; $c++) {
            $line .= $terminal->screen()->cell($index, $c)->grapheme;
        }
        return rtrim($line);
    }
}
