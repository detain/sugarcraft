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
        // designations, the GL shift, a single shift, the pen and a DEC mode —
        // then reset through the emitter and require all of it back at power-on.
        $h = $this->feed(
            Ansi::scsG0(Ansi::CHARSET_DEC_SPECIAL)
            . Ansi::scsG1(Ansi::CHARSET_UK)
            . Ansi::shiftOut()
            . "\x8f"
            . "\x1b[7m\x1b[?25l"
            . Ansi::ris(),
        );

        $this->assertSame([Charsets::ASCII, Charsets::ASCII, Charsets::ASCII, Charsets::ASCII], $h->charsets);
        $this->assertSame(0, $h->gl, 'SO must be undone by RIS');
        $this->assertNull($h->singleShift);
        $this->assertTrue($h->mode->cursorVisible, 'DECTCEM returns to its power-on value');
        $this->assertFalse($h->sgr->reverse, 'the pen returns to default rendition');
        $this->assertSame('', $this->row($h));
    }

    // ─── DECALN (ESC # 8) ────────────────────────────────────────────────────

    public function testDecalnEmitterDispatchesWithTheHashIntermediate(): void
    {
        // The single most important property of this emitter: `#` must arrive as
        // the INTERMEDIATE (0x23) and `8` as the FINAL (0x38) of one complete
        // escape. The `CSI # 8` spelling people reach for never dispatches at
        // all — candy-ansi closes CsiIntermediate on 0x30-0x3F by dropping to
        // Ground — and would strand the receiver mid-sequence.
        $debug = new DebugHandler();
        (new Parser($debug))->feed(Ansi::decaln() . 'E');

        $this->assertSame(
            [['type' => 'esc', 'detail' => ['final' => 0x38, 'intermediate' => 0x23]]],
            $debug->filter('esc'),
        );
        $this->assertSame(['E'], array_column($debug->filter('print'), 'detail'));
    }

    public function testDecalnBytesReachTheHandlerThatModelsThem(): void
    {
        // candy-vt models DECALN programmatically (`displayAlignmentTest()`);
        // this asserts the *handler* exists and produces the 'E' field the
        // emitter's docblock describes, so the two vocabularies cannot drift
        // apart silently while the parser gap is closed in a later change.
        $h = $this->feed('x');
        $h->displayAlignmentTest();

        $this->assertSame(str_repeat('E', self::COLS), $this->row($h));
        $this->assertSame(str_repeat('E', self::COLS), $this->row($h, self::ROWS - 1));
    }

    // ─── SCS (ESC ( ) * + F) ─────────────────────────────────────────────────

    public function testDesignatorRosterMatchesWhatTheParserRecognises(): void
    {
        // Core names the designators; vt decides which ones mean anything. If
        // either roster drifts alone, an emitter would produce a designation the
        // receiver silently discards — the failure this pins out. Read through
        // reflection so the comparison stays a genuine runtime one (a literal
        // `assertSame('B', 'B')` would also break loudly on a rename in either
        // lib instead of silently comparing two stale string literals).
        $core = new \ReflectionClass(Ansi::class);
        $emulator = new \ReflectionClass(Charsets::class);
        $pairs = [
            'CHARSET_ASCII' => 'ASCII',
            'CHARSET_DEC_SPECIAL' => 'DEC_SPECIAL',
            'CHARSET_UK' => 'UK',
            'CHARSET_NO_BREAK_SPACE' => 'NO_BREAK_SPACE',
        ];

        foreach ($pairs as $coreConstant => $emulatorConstant) {
            $this->assertSame(
                $emulator->getConstant($emulatorConstant),
                $core->getConstant($coreConstant),
                "candy-core Ansi::{$coreConstant} must name the same designator as candy-vt Charsets::{$emulatorConstant}",
            );
        }
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
