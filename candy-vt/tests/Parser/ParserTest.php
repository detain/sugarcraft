<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Tests\Parser;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vt\Parser\DebugHandler;
use SugarCraft\Vt\Parser\Parser;
use SugarCraft\Vt\Parser\State;

final class ParserTest extends TestCase
{
    private function parse(string $bytes): DebugHandler
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed($bytes);
        return $h;
    }

    private static function csi(int $final, array $params = [], int $prefix = 0, int $intermediate = 0): array
    {
        return ['type' => 'csi', 'detail' => [
            'final' => $final,
            'params' => $params,
            'prefix' => $prefix,
            'intermediate' => $intermediate,
        ]];
    }

    private static function esc(int $final, int $intermediate = 0): array
    {
        return ['type' => 'esc', 'detail' => ['final' => $final, 'intermediate' => $intermediate]];
    }

    private static function dcs(int $final, array $params, int $prefix, int $intermediate, string $data): array
    {
        return ['type' => 'dcs', 'detail' => [
            'final' => $final,
            'params' => $params,
            'prefix' => $prefix,
            'intermediate' => $intermediate,
            'data' => $data,
        ]];
    }

    // ─── Print ────────────────────────────────────────────────────────────

    public function testPrintsAscii(): void
    {
        $h = $this->parse('ABC');
        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'print', 'detail' => 'C'],
        ], $h->log);
    }

    public function testEmptyInputProducesNoLog(): void
    {
        $h = $this->parse('');
        $this->assertSame([], $h->log);
    }

    public function testPrintsTwoByteUtf8(): void
    {
        $h = $this->parse("é"); // 0xC3 0xA9
        $this->assertSame([['type' => 'print', 'detail' => 'é']], $h->log);
    }

    public function testPrintsThreeByteUtf8(): void
    {
        $h = $this->parse("日本"); // 0xE6 0x97 0xA5  0xE6 0x9C 0xAC
        $this->assertSame([
            ['type' => 'print', 'detail' => '日'],
            ['type' => 'print', 'detail' => '本'],
        ], $h->log);
    }

    public function testPrintsFourByteUtf8(): void
    {
        $h = $this->parse("\u{1F600}"); // 😀, 0xF0 0x9F 0x98 0x80
        $this->assertSame([['type' => 'print', 'detail' => "\u{1F600}"]], $h->log);
    }

    public function testPrintsAfterUtf8(): void
    {
        $h = $this->parse("日X");
        $this->assertSame([
            ['type' => 'print', 'detail' => '日'],
            ['type' => 'print', 'detail' => 'X'],
        ], $h->log);
    }

    public function testPartialUtf8Suspends(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\xE6\x97"); // first 2 bytes of 日
        $this->assertSame(State::Utf8, $p->currentState());
        $this->assertSame([], $h->log);
        $p->feed("\xA5"); // final continuation
        $this->assertSame([['type' => 'print', 'detail' => '日']], $h->log);
        $this->assertSame(State::Ground, $p->currentState());
    }

    public function testInterruptedUtf8DropsPartialRune(): void
    {
        // ASCII byte arriving mid-rune drops the partial UTF-8 and processes the byte fresh.
        $h = $this->parse("\xE6\x97A");
        $this->assertSame([['type' => 'print', 'detail' => 'A']], $h->log);
    }

    // ─── Execute (C0 / C1) ─────────────────────────────────────────────────

    /** @return list<array{int}> */
    public static function c0Bytes(): array
    {
        return [
            [0x07], [0x08], [0x09], [0x0A], [0x0B], [0x0C], [0x0D],
            [0x0E], [0x0F], [0x18], [0x1A],
        ];
    }

    /**
     * @dataProvider c0Bytes
     */
    public function testExecutesC0Control(int $byte): void
    {
        $h = $this->parse(chr($byte));
        $this->assertSame([['type' => 'execute', 'detail' => $byte]], $h->log);
    }

    public function testExecutesDelInGround(): void
    {
        // Charmbracelet's table executes DEL in Ground (per its tweak).
        $h = $this->parse("\x7F");
        $this->assertSame([['type' => 'execute', 'detail' => 0x7F]], $h->log);
    }

    public function testExecutesC1Control(): void
    {
        $h = $this->parse("\x84"); // 0x84 = IND (8-bit form)
        $this->assertSame([['type' => 'execute', 'detail' => 0x84]], $h->log);
    }

    public function testNulIsExecutedNotPrinted(): void
    {
        $h = $this->parse("\x00");
        $this->assertSame([['type' => 'execute', 'detail' => 0x00]], $h->log);
    }

    // ─── ESC dispatch ──────────────────────────────────────────────────────

    public function testEscDispatchesIND(): void
    {
        // ESC D = IND (7-bit form of C1 0x84). Parser emits an esc dispatch;
        // a downstream handler decides whether to translate to 0x84.
        $h = $this->parse("\x1bD");
        $this->assertSame([self::esc(ord('D'))], $h->log);
    }

    public function testEscDispatchesDECSC(): void
    {
        $h = $this->parse("\x1b7"); // DECSC
        $this->assertSame([self::esc(ord('7'))], $h->log);
    }

    public function testEscDispatchesDECRC(): void
    {
        $h = $this->parse("\x1b8"); // DECRC
        $this->assertSame([self::esc(ord('8'))], $h->log);
    }

    public function testEscDispatchesWithIntermediate(): void
    {
        // ESC ( B = designate G0 charset = ASCII (charset switch)
        $h = $this->parse("\x1b(B");
        $this->assertSame([self::esc(ord('B'), ord('('))], $h->log);
    }

    public function testEscEscRestartsEscape(): void
    {
        // Second ESC mid-escape clears state and leaves us in Escape with no dispatch yet.
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\x1b\x1b");
        $this->assertSame(State::Escape, $p->currentState());
        $this->assertSame([], $h->log);
    }

    // ─── CSI dispatch ──────────────────────────────────────────────────────

    public function testCsiNoParams(): void
    {
        $h = $this->parse("\x1b[H");
        $this->assertSame([self::csi(ord('H'))], $h->log);
    }

    public function testCsiOneParam(): void
    {
        $h = $this->parse("\x1b[1m");
        $this->assertSame([self::csi(ord('m'), [1])], $h->log);
    }

    public function testCsiMultipleParams(): void
    {
        $h = $this->parse("\x1b[5;10H");
        $this->assertSame([self::csi(ord('H'), [5, 10])], $h->log);
    }

    public function testCsiLeadingDefaultParam(): void
    {
        // ';H' produces two params: implicit default before the ';' and default after.
        $h = $this->parse("\x1b[;H");
        $this->assertSame([self::csi(ord('H'), [-1, -1])], $h->log);
    }

    public function testCsiTrailingDefaultParam(): void
    {
        $h = $this->parse("\x1b[1;H");
        $this->assertSame([self::csi(ord('H'), [1, -1])], $h->log);
    }

    public function testCsiTruecolorParams(): void
    {
        $h = $this->parse("\x1b[38;2;255;128;0m");
        $this->assertSame([self::csi(ord('m'), [38, 2, 255, 128, 0])], $h->log);
    }

    public function testCsiPrivatePrefix(): void
    {
        $h = $this->parse("\x1b[?25l"); // hide cursor
        $this->assertSame([self::csi(ord('l'), [25], ord('?'))], $h->log);
    }

    public function testCsiIntermediateByte(): void
    {
        $h = $this->parse("\x1b[1\$p"); // DECRQM = CSI 1 $ p
        $this->assertSame([self::csi(ord('p'), [1], 0, ord('$'))], $h->log);
    }

    public function testCsiResetsBetweenSequences(): void
    {
        $h = $this->parse("\x1b[31m\x1b[H");
        $this->assertSame([
            self::csi(ord('m'), [31]),
            self::csi(ord('H')),
        ], $h->log);
    }

    public function testPrintAfterCsi(): void
    {
        $h = $this->parse("\x1b[31mAB");
        $this->assertSame([
            self::csi(ord('m'), [31]),
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
        ], $h->log);
    }

    // ─── OSC ───────────────────────────────────────────────────────────────

    public function testOscBelTerminated(): void
    {
        $h = $this->parse("\x1b]2;Title\x07");
        $this->assertSame([['type' => 'osc', 'detail' => '2;Title']], $h->log);
    }

    public function testOscStTerminated(): void
    {
        // ESC \ (ST) terminator. Per the upstream table, OscString's ESC fires
        // an OSC dispatch and re-enters Escape; the trailing '\' then dispatches
        // as a (no-op) escDispatch(0x5C). We assert both.
        $h = $this->parse("\x1b]2;Title\x1b\\");
        $this->assertSame([
            ['type' => 'osc', 'detail' => '2;Title'],
            self::esc(ord('\\')),
        ], $h->log);
    }

    public function testOscWithSemicolons(): void
    {
        $h = $this->parse("\x1b]8;id=1;https://example.com\x07");
        $this->assertSame([['type' => 'osc', 'detail' => '8;id=1;https://example.com']], $h->log);
    }

    public function testOscC1ShortForm(): void
    {
        // 0x9D is OSC C1 8-bit form
        $h = $this->parse("\x9d2;Title\x07");
        $this->assertSame([['type' => 'osc', 'detail' => '2;Title']], $h->log);
    }

    public function testTwoOscSequencesBackToBack(): void
    {
        $h = $this->parse("\x1b]0;A\x07\x1b]2;B\x07");
        $this->assertSame([
            ['type' => 'osc', 'detail' => '0;A'],
            ['type' => 'osc', 'detail' => '2;B'],
        ], $h->log);
    }

    // ─── DCS ───────────────────────────────────────────────────────────────

    public function testDcsStTerminated(): void
    {
        // DCS 1;2 q payload ST → dcsDispatch with final='q', params=[1,2], data='payload'
        $h = $this->parse("\x1bP1;2qpayload\x1b\\");
        $this->assertSame([
            self::dcs(ord('q'), [1, 2], 0, 0, 'payload'),
            self::esc(ord('\\')),
        ], $h->log);
    }

    public function testDcsWithPrefix(): void
    {
        $h = $this->parse("\x1bP?1qX\x9c"); // ST C1
        $this->assertSame([
            self::dcs(ord('q'), [1], ord('?'), 0, 'X'),
        ], $h->log);
    }

    public function testDcsEmptyPayload(): void
    {
        $h = $this->parse("\x1bPq\x1b\\");
        $this->assertSame([
            self::dcs(ord('q'), [], 0, 0, ''),
            self::esc(ord('\\')),
        ], $h->log);
    }

    // ─── SOS / PM / APC ────────────────────────────────────────────────────

    public function testSosDispatch(): void
    {
        $h = $this->parse("\x1bXhello\x1b\\");
        $this->assertSame([
            ['type' => 'sos', 'detail' => 'hello'],
            self::esc(ord('\\')),
        ], $h->log);
    }

    public function testPmDispatch(): void
    {
        $h = $this->parse("\x1b^private\x1b\\");
        $this->assertSame([
            ['type' => 'pm', 'detail' => 'private'],
            self::esc(ord('\\')),
        ], $h->log);
    }

    public function testApcDispatch(): void
    {
        $h = $this->parse("\x1b_app\x1b\\");
        $this->assertSame([
            ['type' => 'apc', 'detail' => 'app'],
            self::esc(ord('\\')),
        ], $h->log);
    }

    // ─── Cancellation ──────────────────────────────────────────────────────

    public function testCanCancelsCsi(): void
    {
        // CAN (0x18) mid-CSI executes the CAN, returns to Ground, and lets 'H' print.
        $h = $this->parse("\x1b[1;2\x18H");
        $this->assertSame([
            ['type' => 'execute', 'detail' => 0x18],
            ['type' => 'print', 'detail' => 'H'],
        ], $h->log);
    }

    public function testSubCancelsCsi(): void
    {
        $h = $this->parse("\x1b[1;2\x1aH");
        $this->assertSame([
            ['type' => 'execute', 'detail' => 0x1A],
            ['type' => 'print', 'detail' => 'H'],
        ], $h->log);
    }

    public function testEscMidCsiStartsNewEscape(): void
    {
        // A second ESC mid-CSI cancels the in-flight sequence and starts a new escape.
        $h = $this->parse("\x1b[1;2\x1bD");
        $this->assertSame([self::esc(ord('D'))], $h->log);
    }

    // ─── Partial input ─────────────────────────────────────────────────────

    public function testPartialCsiAcrossFeeds(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\x1b[1;");
        $this->assertSame(State::CsiParam, $p->currentState());
        $p->feed("2H");
        $this->assertSame([self::csi(ord('H'), [1, 2])], $h->log);
    }

    public function testPartialOscAcrossFeeds(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\x1b]2;Par");
        $this->assertSame(State::OscString, $p->currentState());
        $p->feed("tial\x07");
        $this->assertSame([['type' => 'osc', 'detail' => '2;Partial']], $h->log);
    }

    public function testFlushDispatchesPendingOsc(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\x1b]2;Pending");
        $p->flush();
        $this->assertSame([['type' => 'osc', 'detail' => '2;Pending']], $h->log);
        $this->assertSame(State::Ground, $p->currentState());
    }

    public function testFlushOnGroundIsNoOp(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("ABC");
        $p->flush();
        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'print', 'detail' => 'C'],
        ], $h->log);
    }

    public function testResetClearsInFlightCsi(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\x1b[1;2");
        $this->assertSame(State::CsiParam, $p->currentState());
        $p->reset();
        $this->assertSame(State::Ground, $p->currentState());
        $this->assertSame([], $h->log);
    }

    public function testResetClearsInFlightOsc(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\x1b]2;Title");
        $this->assertSame(State::OscString, $p->currentState());
        $p->reset();
        $this->assertSame(State::Ground, $p->currentState());
        // reset() flushes pending sequences, so OSC is dispatched with content
        $this->assertSame([['type' => 'osc', 'detail' => '2;Title']], $h->log);
    }

    public function testResetClearsUtf8InFlight(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\xE6\x97"); // first 2 bytes of 日
        $this->assertSame(State::Utf8, $p->currentState());
        $p->reset();
        $this->assertSame(State::Ground, $p->currentState());
        $this->assertSame([], $h->log);
    }

    public function testResetThenNewInputProcessesCorrectly(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->reset();
        $p->feed("ABC");
        $this->assertSame([
            ['type' => 'print', 'detail' => 'A'],
            ['type' => 'print', 'detail' => 'B'],
            ['type' => 'print', 'detail' => 'C'],
        ], $h->log);
    }

    public function testResetClearsParams(): void
    {
        $h = new DebugHandler();
        $p = new Parser($h);
        $p->feed("\x1b[1;2H"); // CUP with params [1, 2]
        $p->reset();
        $p->feed("\x1b[H"); // CUP with no params (new sequence after reset)
        $csiEntries = $h->filter('csi');
        $this->assertCount(2, $csiEntries);
        // First CSI had params [1, 2], second has empty params (reset started fresh sequence)
        $detail0 = $csiEntries[0]['detail'];
        $detail1 = $csiEntries[1]['detail'];
        $this->assertIsArray($detail0);
        $this->assertIsArray($detail1);
        $this->assertSame([1, 2], $detail0['params']);
        $this->assertSame([], $detail1['params']);
    }

    // ─── Volume / sanity ───────────────────────────────────────────────────

    public function testLargeMixedInput(): void
    {
        $bytes = str_repeat("ABC\x1b[31m", 1000);
        $h = $this->parse($bytes);
        $this->assertSame(3000, count($h->filter('print')));
        $this->assertSame(1000, count($h->filter('csi')));
    }
}
