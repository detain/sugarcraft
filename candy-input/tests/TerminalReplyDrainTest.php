<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\EscapeDecoderOptions;
use SugarCraft\Input\Event;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event\TerminalReplyEvent;
use SugarCraft\Input\KeyModifier;

/**
 * Byte-level tests for unsolicited terminal REPLIES on the input stream.
 *
 * Each reply family must be recognized and either mapped to a documented Msg
 * (KeyEvent/MouseEvent/FocusEvent) or drained as a TerminalReplyEvent —
 * never left in the decode buffer (stall/poisoning) and never mis-parsed as
 * a keystroke. Covers: DA1/DA2, DSR/CPR, XTWINOPS, kitty keyboard flags
 * (including the empty-codepoint `CSI ? u` form), DECRPM, private-mode CSIs,
 * OSC/DCS strings, and X10/SGR mouse — each also arriving mid keystroke
 * stream and split across reads.
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html
 * @see https://sw.kovidgoyal.net/kitty/keyboard-protocol/
 */
final class TerminalReplyDrainTest extends TestCase
{
    private EscapeDecoder $decoder;

    protected function setUp(): void
    {
        $this->decoder = new EscapeDecoder();
    }

    protected function tearDown(): void
    {
        $this->decoder->reset();
    }

    /**
     * Assert $events is exactly [reply of $family with $params] and the buffer is clean.
     *
     * @param list<\SugarCraft\Input\Event> $events
     * @param list<int>                     $params
     */
    private function assertDrained(array $events, string $family, array $params, string $raw): void
    {
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TerminalReplyEvent::class, $events[0]);
        $this->assertSame($family, $events[0]->family);
        $this->assertSame($params, $events[0]->params);
        $this->assertSame($raw, $events[0]->raw);
        $this->assertSame('', $this->decoder->remainder(), 'reply must never linger in the decode buffer');
    }

    /**
     * After any drained reply, ordinary keystrokes must still decode — the
     * stall symptom from the audit: a reply left in the buffer made EVERY
     * later key vanish until the 128-byte cap discarded the stream.
     */
    private function assertStreamAlive(): void
    {
        $events = $this->decoder->decode("a\x1b[C");
        $this->assertCount(2, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame('ArrowRight', $events[1]->key);
    }

    // ─── DA1 / DA2 ────────────────────────────────────────────────────────────

    public function testDa1ReplyIsDrainedNotBuffered(): void
    {
        $events = $this->decoder->decode("\x1b[?1;2c");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES, [1, 2], "\x1b[?1;2c");
        // The reported poisoning: previously every later keystroke vanished behind this reply.
        $events = $this->decoder->decode('hello');
        $this->assertCount(5, $events);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testDa1ExtendedParamsReply(): void
    {
        // xterm DA1 reply with CONSUMED_LEVEL_ONE params (64;1;...).
        $events = $this->decoder->decode("\x1b[?64;1;2;6;9;15;18;22;23c");
        $this->assertDrained(
            $events,
            TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES,
            [64, 1, 2, 6, 9, 15, 18, 22, 23],
            "\x1b[?64;1;2;6;9;15;18;22;23c",
        );
    }

    public function testDa1ReplySplitAcrossReads(): void
    {
        $this->assertCount(0, $this->decoder->decode("\x1b[?64;"));
        $this->assertSame("\x1b[?64;", $this->decoder->remainder());
        $events = $this->decoder->decode("1;2c");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES, [64, 1, 2], "\x1b[?64;1;2c");
        $this->assertStreamAlive();
    }

    public function testDa2ReplyIsDrained(): void
    {
        $events = $this->decoder->decode("\x1b[>1;10;0c");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES, [1, 10, 0], "\x1b[>1;10;0c");
        $this->assertStreamAlive();
    }

    public function testBareDaQueryIsDrainedNotStuck(): void
    {
        // `ESC [ c` is a DA request echoed / soft-triggered on the input fd —
        // a reply family, never a keystroke.
        $events = $this->decoder->decode("\x1b[c");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES, [], "\x1b[c");
        $this->assertStreamAlive();
    }

    // ─── DSR / CPR ────────────────────────────────────────────────────────────

    public function testCprRowOneIsNotAFabricatedF3(): void
    {
        // The audit repro: "\x1b[1;20R" used to fabricate KeyEvent(F3, SHIFT|ALT).
        $events = $this->decoder->decode("\x1b[1;20R");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_CURSOR_POSITION, [1, 20], "\x1b[1;20R");
        $this->assertStreamAlive();
    }

    public function testCprRowsTenPlusAreDrainedNotSilentlySwallowed(): void
    {
        // Previously swallowed as an unknown CSI with zero observability; now a
        // documented drained reply.
        $events = $this->decoder->decode("\x1b[42;80R");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_CURSOR_POSITION, [42, 80], "\x1b[42;80R");
        $this->assertSame(1, $this->decoder->drainedReplyCount());
    }

    public function testCprMidKeystrokeStream(): void
    {
        // arrow key, then CPR, then arrow key — one chunk.
        $events = $this->decoder->decode("\x1b[C\x1b[4;12R\x1b[D");
        $this->assertCount(3, $events);
        $this->assertSame('ArrowRight', $events[0]->key);
        $this->assertInstanceOf(TerminalReplyEvent::class, $events[1]);
        $this->assertSame(TerminalReplyEvent::FAMILY_CURSOR_POSITION, $events[1]->family);
        $this->assertSame([4, 12], $events[1]->params);
        $this->assertSame('ArrowLeft', $events[2]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testCprSplitAcrossReadsMidStream(): void
    {
        $events = $this->decoder->decode("\x1b[C\x1b[4");
        $this->assertSame('ArrowRight', $events[0]->key);
        $this->assertSame("\x1b[4", $this->decoder->remainder());

        $events = $this->decoder->decode(";12R\x1b[D");
        $this->assertCount(2, $events);
        $this->assertSame(TerminalReplyEvent::FAMILY_CURSOR_POSITION, $events[0]->family);
        $this->assertSame([4, 12], $events[0]->params);
        $this->assertSame('ArrowLeft', $events[1]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testDsrStatusReportIsDrained(): void
    {
        // Reply to `ESC [ 5 n`: `ESC [ 0 n`.
        $events = $this->decoder->decode("\x1b[0n");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_DSR_STATUS, [0], "\x1b[0n");
        $this->assertStreamAlive();
    }

    // ─── XTWINOPS ─────────────────────────────────────────────────────────────

    public function testXtwinopsGeometryReportIsDrained(): void
    {
        // Reply to `CSI 14 t`: `CSI 4 ; H ; W t`.
        $events = $this->decoder->decode("\x1b[4;100;200t");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_WINDOW_REPORT, [4, 100, 200], "\x1b[4;100;200t");
        $this->assertStreamAlive();
    }

    public function testXtwinopsResizeReportIsDrained(): void
    {
        // Reply to `CSI 18 t`: `CSI 8 ; rows ; cols t`.
        $events = $this->decoder->decode("\x1b[8;300;100t");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_WINDOW_REPORT, [8, 300, 100], "\x1b[8;300;100t");
    }

    public function testXtwinopsSplitAcrossReads(): void
    {
        $this->assertCount(0, $this->decoder->decode("\x1b[1;100;"));
        $events = $this->decoder->decode("300t");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_WINDOW_REPORT, [1, 100, 300], "\x1b[1;100;300t");
        $this->assertStreamAlive();
    }

    // ─── Kitty keyboard flags — the "teleports the cursor" family ─────────────

    public function testKittyEmptyCodepointQueryIsDrainedNotMisConsumed(): void
    {
        // The reported symptom: `ESC [ ? u` (kitty empty-codepoint form) mis-consumed
        // its parameters downstream. The decoder must drain it as a flags reply with
        // NO key event and NO leftover bytes feeding the next consumer.
        $events = $this->decoder->decode("\x1b[?u");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_KITTY_FLAGS, [], "\x1b[?u");
        $this->assertStreamAlive();
    }

    public function testKittyFlagsReplyIsDrainedNotDecodedAsKey(): void
    {
        // `CSI ? 3 u` previously decoded as phantom Home (code 3) — and after the
        // stall fix it must not decode as a key at all: private `u` is the flags family.
        $events = $this->decoder->decode("\x1b[?3u");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_KITTY_FLAGS, [3], "\x1b[?3u");
        $this->assertNotInstanceOf(KeyEvent::class, $events[0]);

        // The audit's poisoning probe: flags reply then arrow — the arrow used to
        // vanish behind the buffered flags reply.
        $events = $this->decoder->decode("\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    public function testKittyFlagsReplySplitAcrossReads(): void
    {
        $this->assertCount(0, $this->decoder->decode("\x1b[?"));
        $events = $this->decoder->decode("1u");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_KITTY_FLAGS, [1], "\x1b[?1u");
    }

    public function testKittyFlagsMidKeystrokeStream(): void
    {
        $events = $this->decoder->decode("\x1b[A\x1b[?3u\x1b[B");
        $this->assertCount(3, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame(TerminalReplyEvent::FAMILY_KITTY_FLAGS, $events[1]->family);
        $this->assertSame('ArrowDown', $events[2]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testKittyFlagsDrainedEvenWithKittyProtocolDisabled(): void
    {
        // A reply is not a kitty KEY; protocol gating must not re-buffer it.
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableKitty: false));
        $events = $decoder->decode("\x1b[?3u");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TerminalReplyEvent::class, $events[0]);
        $this->assertSame(TerminalReplyEvent::FAMILY_KITTY_FLAGS, $events[0]->family);
        $this->assertSame('', $decoder->remainder());
    }

    public function testKittyRealEventFrameNeedsNoQuestionMark(): void
    {
        // The inverted-decoder symptom: real frames "CSI code;mods u" (no "?")
        // used to produce NO event at all.
        $events = $this->decoder->decode("\x1b[97;5u");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyEvent::class, $events[0]);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame("\x1b[97;5u", $events[0]->raw);
    }

    public function testKittyEventFrameWithEventTypeNameSubParam(): void
    {
        // Spec encoding of release: event-type 3 sub-param → "CSI 97;1:3u".
        $events = $this->decoder->decode("\x1b[97;1:3u");
        $this->assertCount(1, $events);
        $this->assertSame('ReleaseA', $events[0]->key);

        // Press (event-type 1) stays a press.
        $events = $this->decoder->decode("\x1b[97;1:1u");
        $this->assertSame('a', $events[0]->key);
    }

    public function testKittyEventFrameAlternateCodeSubParamsIgnored(): void
    {
        // "CSI 97:65;2u" = 'a' with shift-code sub-param — primary code identifies the key.
        $events = $this->decoder->decode("\x1b[97:65;2u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
    }

    public function testKittyUnknownKeyCodeConsumesOnlyItself(): void
    {
        // Unmapped code — the frame is dropped (counted) but the trailing bytes
        // of the chunk must survive (old handleKitty returned remaining='' here,
        // swallowing every later byte of the chunk).
        $events = $this->decoder->decode("\x1b[57360;1u\x1b[C");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowRight', $events[0]->key);
        $this->assertSame(1, $this->decoder->droppedUnknownCount());
        $this->assertSame('', $this->decoder->remainder());
    }

    // ─── DECRPM / private-mode drain ─────────────────────────────────────────

    public function testDecrpmModeReportIsDrained(): void
    {
        // `CSI ? 2 ; 1 $ y` — DECRPM reply (mode 2, set).
        $events = $this->decoder->decode("\x1b[?2;1\$y");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_MODE_REPORT, [2, 1], "\x1b[?2;1\$y");
        $this->assertStreamAlive();
    }

    public function testDecrpmSplitAcrossReads(): void
    {
        $this->assertCount(0, $this->decoder->decode("\x1b[?1;"));
        $events = $this->decoder->decode("2\$y");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_MODE_REPORT, [1, 2], "\x1b[?1;2\$y");
    }

    public function testUnknownPrivateCsiIsDrainedNotBufferedForever(): void
    {
        // `CSI ? 9 ; 1 w` — unrecognized private sequence: consumed whole,
        // surfaced as csi-private, stream continues.
        $events = $this->decoder->decode("\x1b[?9;1w");
        $this->assertDrained($events, TerminalReplyEvent::FAMILY_CSI_PRIVATE, [9, 1], "\x1b[?9;1w");
        $this->assertStreamAlive();
    }

    // ─── X10 mouse — reply-shaped bytes must not leak as keystrokes ──────────

    public function testX10MousePressIsNotThreePrintableKeys(): void
    {
        // The audit repro: "\x1b[M".chr(32).chr(35).chr(37) produced
        // Key(' '), Key('#'), Key('%'). Left-press at (3,5).
        $events = $this->decoder->decode("\x1b[M" . chr(32) . chr(35) . chr(37));
        $this->assertCount(1, $events);
        $this->assertInstanceOf(MouseEvent::class, $events[0]);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $events[0]->button);
        $this->assertSame(MouseEvent::ACTION_PRESS, $events[0]->action);
        $this->assertSame(3, $events[0]->x);
        $this->assertSame(5, $events[0]->y);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testX10ReleaseAndWheelAndMotion(): void
    {
        // button 3 = release; 64/65 = wheel up/down; 32 = motion. Payload bytes
        // are wire values (+32): button 3 → chr(35), coordinate 1 → chr(33).
        $events = $this->decoder->decode("\x1b[M" . chr(35) . chr(33) . chr(33));
        $this->assertSame(MouseEvent::ACTION_RELEASE, $events[0]->action);

        $events = $this->decoder->decode("\x1b[M" . chr(96) . chr(33) . chr(33));
        $this->assertTrue($events[0]->isScroll());
        $this->assertSame(96, $events[0]->button, 'wheel up stored as 96');

        $events = $this->decoder->decode("\x1b[M" . chr(97) . chr(33) . chr(33));
        $this->assertTrue($events[0]->isScroll());
        $this->assertSame(97, $events[0]->button, 'wheel down stored as 97');

        // Motion = code 32 (left drag) → wire byte chr(32+32) = chr(64).
        $events = $this->decoder->decode("\x1b[M" . chr(64) . chr(65) . chr(66));
        $this->assertSame(MouseEvent::ACTION_DRAG, $events[0]->action);
    }

    public function testX10SplitAcrossReads(): void
    {
        $this->assertCount(0, $this->decoder->decode("\x1b[M" . chr(32)));
        $this->assertSame("\x1b[M" . chr(32), $this->decoder->remainder());
        $events = $this->decoder->decode(chr(35) . chr(37));
        $this->assertCount(1, $events);
        $this->assertInstanceOf(MouseEvent::class, $events[0]);
        $this->assertSame(3, $events[0]->x);
        $this->assertSame(5, $events[0]->y);
    }

    public function testSgrWheelButtonIsNotAPhantomLeftClick(): void
    {
        // The audit "also-real" repro: `ESC[<64;10;5M` (wheel up) used to fall
        // through to `button & 3 == 0` and emit a left PRESS.
        $events = $this->decoder->decode("\x1b[<64;10;5M");
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->isScroll());
        $this->assertSame(96, $events[0]->button);

        $events = $this->decoder->decode("\x1b[<65;10;5M");
        $this->assertSame(97, $events[0]->button);

        // Horizontal wheel (xterm 1007): codes 66/67.
        $events = $this->decoder->decode("\x1b[<66;10;5M");
        $this->assertSame(98, $events[0]->button);
        $events = $this->decoder->decode("\x1b[<67;10;5M");
        $this->assertSame(99, $events[0]->button);
    }

    public function testMalformedSgrMouseDoesNotStallTheStream(): void
    {
        // Two params instead of three — COMPLETE sequence, must be consumed to
        // its final byte (re-buffering it poisoned every later keystroke).
        $events = $this->decoder->decode("\x1b[<0;10M\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
        $this->assertSame(1, $this->decoder->droppedUnknownCount());
    }

    public function testSgrReleaseThenPressInOneChunkYieldsBoth(): void
    {
        // Release ('m') and press ('M') coalesce into one read() routinely. The
        // final byte must be matched EARLIEST-of, not 'M' first — searching 'M'
        // first used to eat the release report's params into the press report and
        // drop both events.
        $events = $this->decoder->decode("\x1b[<0;1;1m\x1b[<0;1;2M");
        $this->assertCount(2, $events);
        $this->assertSame(MouseEvent::ACTION_RELEASE, $events[0]->action);
        $this->assertSame(1, $events[0]->y);
        $this->assertSame(MouseEvent::ACTION_PRESS, $events[1]->action);
        $this->assertSame(2, $events[1]->y);
        $this->assertSame(0, $this->decoder->droppedUnknownCount());
        $this->assertStreamAlive();
    }

    // ─── OSC / DCS / APC / PM string replies ────────────────────────────────

    public function testOscColorQueryReplyIsDrainedNotAltKeySpam(): void
    {
        // `OSC 4 ; 1 ; rgb:00/00/00 ST` — previously leaked as Alt+']' plus a
        // burst of printable keys.
        $events = $this->decoder->decode("\x1b]4;1;rgb:00/00/00\x1b\\");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TerminalReplyEvent::class, $events[0]);
        $this->assertSame(TerminalReplyEvent::FAMILY_STRING, $events[0]->family);
        $this->assertSame('4;1;rgb:00/00/00', $events[0]->body);
        $this->assertFalse($events[0]->truncated);
        $this->assertStreamAlive();
    }

    public function testStringTerminatedByBel(): void
    {
        $events = $this->decoder->decode("\x1b]11;?\x07");
        $this->assertCount(1, $events);
        $this->assertSame(TerminalReplyEvent::FAMILY_STRING, $events[0]->family);
        $this->assertSame('11;?', $events[0]->body);
    }

    public function testDcsTmuxEchoIsDrained(): void
    {
        $events = $this->decoder->decode("\x1bPtmux;echo\x1b\\");
        $this->assertCount(1, $events);
        $this->assertSame('tmux;echo', $events[0]->body);
        $this->assertStreamAlive();
    }

    public function testStringReplySplitAcrossReads(): void
    {
        // In-progress string state keeps its payload internally (like the paste
        // path): remainder() stays empty, bytes are never re-exposed as keys.
        $this->assertCount(0, $this->decoder->decode("\x1b]0;window"));
        $this->assertSame('', $this->decoder->remainder());
        // terminator ESC itself split across chunks:
        $this->assertCount(0, $this->decoder->decode(" title\x1b"));
        $events = $this->decoder->decode("\\\x07");
        // "\x1b\\" consumed the ST; the trailing BEL is a stray control char.
        $this->assertSame(TerminalReplyEvent::FAMILY_STRING, $events[0]->family);
        $this->assertSame('0;window title', $events[0]->body);
        $this->assertSame("\x07", $events[1]->raw);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testUnterminatedStringIsCappedNotUnbounded(): void
    {
        $flood = "\x1bP" . str_repeat('q', 4000);
        $events = $this->decoder->decode($flood);
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->truncated, 'oversized unterminated string drains as truncated');
        $this->assertSame(1024, strlen($events[0]->body));
        // Past the cap the decoder keeps swallowing toward the terminator — the
        // remainder of the open DCS must NEVER resurface as keystrokes.
        $this->assertCount(0, $this->decoder->decode(str_repeat('q', 500)));
        $this->assertCount(0, $this->decoder->decode("\x1b[A"));
        $this->assertSame('', $this->decoder->remainder());
        // Closing the string resyncs the stream for good.
        $events = $this->decoder->decode("\x1b\\\x1b[A");
        $this->assertCount(1, $events, 'no second event for an already-drained truncation');
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame(1, $this->decoder->drainedReplyCount());
    }

    public function testUnterminatedStringAbandonsAndResyncs(): void
    {
        // A truly hostile never-terminated string is bounded: once MAX_STRING_ABANDON
        // (64 KiB) past the cap is swallowed, the decoder gives up waiting for the
        // terminator and resumes normal decoding instead of eating the session.
        // The replay tail past the abandon boundary decodes as keys IN THE SAME
        // call — chunk size cannot shift the boundary (see the invariance test).
        $events = $this->decoder->decode("\x1bP" . str_repeat('q', 1024 + 65536 + 16));
        $this->assertCount(17, $events, 'one truncated drain plus the 16 bytes past the abandon boundary');
        $this->assertTrue($events[0]->truncated);
        $this->assertSame('q', $events[1]->key);
        $this->assertSame(1, $this->decoder->drainedReplyCount());
        // After abandoning, keystrokes decode again.
        $events = $this->decoder->decode("\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    public function testOversizedStringWithinAbandonBudgetStaysSilent(): void
    {
        // A flood past the 1 KiB drain cap but within the 64 KiB abandon budget is
        // swallowed whole even in 7-byte chunks: one truncated reply, zero key spam.
        $flood = "\x1bP" . str_repeat('q', 2048);
        $total = 0;
        for ($off = 0; $off < strlen($flood); $off += 7) {
            $total += count($this->decoder->decode(substr($flood, $off, 7)));
        }
        $this->assertSame(1, $total, 'exactly the truncated drain event across every chunk');
        $this->assertSame(1, $this->decoder->drainedReplyCount());
        // Real terminator: swallowed quietly, no duplicate event, stream alive.
        $events = $this->decoder->decode("\x1b\\\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testStringFloodDecodingIsChunkingInvariant(): void
    {
        // The reviewer's unterminated-DCS flood must yield the IDENTICAL event
        // stream whether delivered in one chunk or in 7-byte reads: one truncated
        // drain, the same bytes replayed after the abandon boundary, no more.
        $flood = "\x1bP" . str_repeat('zx', 40000);
        $oneShot = new EscapeDecoder();
        $chunked = new EscapeDecoder();
        $chunkEvents = [];
        for ($off = 0; $off < strlen($flood); $off += 7) {
            $chunkEvents = array_merge($chunkEvents, $chunked->decode(substr($flood, $off, 7)));
        }
        $this->assertSame(
            self::signatures($oneShot->decode($flood)),
            self::signatures($chunkEvents),
            'chunk size must not alter the event stream',
        );
        $this->assertSame($oneShot->drainedReplyCount(), $chunked->drainedReplyCount());
        // Both decoders abandoned the phantom string; the stream is live and identical.
        $this->assertSame(
            self::signatures($oneShot->decode("\x1b[A")),
            self::signatures($chunked->decode("\x1b[A")),
        );
        $this->assertSame('', $oneShot->remainder());
        $this->assertSame('', $chunked->remainder());
    }

    public function testExactlyCapReplyIsNotFalselyTruncatedBySplitTerminator(): void
    {
        // A reply of EXACTLY 1 KiB whose chunk boundary lands between the ST's
        // ESC and backslash must still drain whole and un-truncated — the
        // dangling terminator byte may not breach the cap on its own.
        $body = str_repeat('q', 1024);
        $oneShot = new EscapeDecoder();
        $chunked = new EscapeDecoder();
        $chunkEvents = array_merge(
            $chunked->decode("\x1b]" . $body),
            $chunked->decode("\x1b"),
            $chunked->decode("\\z"),
        );
        $oneShotEvents = $oneShot->decode("\x1b]" . $body . "\x1b\\z");
        $this->assertSame(self::signatures($oneShotEvents), self::signatures($chunkEvents));
        $this->assertCount(2, $oneShotEvents);
        $this->assertFalse($oneShotEvents[0]->truncated, 'exactly-cap reply is whole, not truncated');
        $this->assertSame(1024, strlen($oneShotEvents[0]->body));
        $this->assertSame(1, $oneShot->drainedReplyCount());
        $this->assertSame(1, $chunked->drainedReplyCount());
    }

    public function testSplitStringTerminatorAfterOverflowIsNotLost(): void
    {
        // A >1 KiB DCS whose ST is split across the chunk boundary exactly
        // between its ESC and the backslash: the overflow drain must still see
        // the terminator — losing it silently swallows every later keystroke.
        $body = str_repeat('q', 1500);
        $events = $this->decoder->decode("\x1bP" . $body . "\x1b");
        $this->assertCount(1, $events, 'truncated reply emitted at the cap');
        $this->assertTrue($events[0]->truncated);
        // Second read starts with the ST's second byte.
        $events = $this->decoder->decode("\\\x1b[A");
        $this->assertCount(1, $events, 'terminator consumed quietly; only the arrow decodes');
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame(1, $this->decoder->drainedReplyCount());
        $this->assertSame(0, $this->decoder->droppedUnknownCount());
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testLongTerminatedStringBodyIsCappedEquallyInEveryChunking(): void
    {
        // One read carrying both a >1 KiB body and its terminator must drain the
        // SAME bounded reply that incremental reads produce — read size cannot
        // change the event stream.
        $reply = "\x1b]4;" . str_repeat('k', 1500) . "\x1b\\xy";
        $oneShot = new EscapeDecoder();
        $chunked = new EscapeDecoder();
        $chunkEvents = [];
        for ($off = 0; $off < strlen($reply); $off += 7) {
            $chunkEvents = array_merge($chunkEvents, $chunked->decode(substr($reply, $off, 7)));
        }
        $oneShotEvents = $oneShot->decode($reply);
        $this->assertSame(self::signatures($oneShotEvents), self::signatures($chunkEvents));
        $this->assertCount(3, $oneShotEvents, 'one capped reply plus the two trailing keys');
        $this->assertTrue($oneShotEvents[0]->truncated, 'single-chunk body is capped like the chunked one');
        $this->assertSame(1024, strlen($oneShotEvents[0]->body));
        $this->assertSame($oneShot->drainedReplyCount(), $chunked->drainedReplyCount());
    }

    public function testCoalescedPastePairsDecodeWithoutRecursion(): void
    {
        // 20 000 paste pairs in ONE coalesced read used to recurse decode() per
        // pair and segfault the process. Iterative tail handling keeps stack
        // depth flat: every pair must surface as its own PasteEvent.
        $pairs = str_repeat("\x1b[200~z\x1b[201~", 20000);
        $events = $this->decoder->decode($pairs);
        $this->assertCount(20000, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertSame('z', $events[0]->content);
        $this->assertInstanceOf(PasteEvent::class, $events[19999]);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testFlushDeferredEscapeIgnoresPasteHoldback(): void
    {
        // A paste body ending on ESC leaves exactly one "\x1b" in the buffer —
        // held back as the prefix of a split end marker, NOT a deferred Escape.
        // Flushing there would invent a keystroke and strand the paste open.
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(deferTrailingEscape: true));
        $decoder->decode("\x1b[200~hi\x1b");
        $this->assertSame([], $decoder->flushDeferredEscape());
        $events = $decoder->decode('[201~X');
        $this->assertSame('hi', $events[0]->content, 'paste still closes with its full body');
        $this->assertSame('X', $events[1]->key);
    }

    /**
     * Stable per-event signature for stream-equivalence assertions.
     *
     * @param list<Event> $events
     *
     * @return list<string>
     */
    private static function signatures(array $events): array
    {
        return array_map(static function (Event $event): string {
            if ($event instanceof TerminalReplyEvent) {
                return 'reply:' . $event->family . ':' . implode(',', $event->params)
                    . ($event->truncated ? ':truncated' : '');
            }
            if ($event instanceof KeyEvent) {
                return 'key:' . $event->key . ':' . $event->modifiers->value();
            }
            if ($event instanceof PasteEvent) {
                return 'paste:' . $event->content;
            }

            return $event::class;
        }, $events);
    }

    public function testMidStringBytesNeverBecomeKeys(): void
    {
        // Color-report body full of printables: zero KeyEvents may come out.
        $events = $this->decoder->decode("\x1b]10;rgb:ffff/ffff/ffff\x1b\\abc");
        $reply = array_shift($events);
        $this->assertInstanceOf(TerminalReplyEvent::class, $reply);
        $this->assertCount(3, $events, 'only the bytes AFTER the terminator are keys');
        foreach ($events as $e) {
            $this->assertInstanceOf(KeyEvent::class, $e);
        }
    }

    // ─── Focus / mouse reply families mid-stream ─────────────────────────────

    public function testFocusReportBetweenKeys(): void
    {
        $events = $this->decoder->decode("a\x1b[Ob");
        $this->assertCount(3, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertInstanceOf(FocusEvent::class, $events[1]);
        $this->assertFalse($events[1]->gained);
        $this->assertSame('b', $events[2]->key);
    }

    public function testFocusReportSplitAcrossReads(): void
    {
        $this->assertCount(0, $this->decoder->decode("\x1b["));
        $events = $this->decoder->decode("Ix");
        $this->assertCount(2, $events);
        $this->assertInstanceOf(FocusEvent::class, $events[0]);
        $this->assertTrue($events[0]->gained);
        $this->assertSame('x', $events[1]->key);
    }

    // ─── Bracketed-paste interaction ────────────────────────────────────────

    public function testReplyBeforePasteStartDecodesBoth(): void
    {
        $events = $this->decoder->decode("\x1b[?3u\x1b[200~hi\x1b[201~");
        $this->assertCount(2, $events);
        $this->assertInstanceOf(TerminalReplyEvent::class, $events[0]);
        $this->assertInstanceOf(PasteEvent::class, $events[1]);
        $this->assertSame('hi', $events[1]->content);
    }

    public function testReplyBytesInsidePasteArePasteContentNotDrained(): void
    {
        // Once bracketed paste is open, EVERYTHING until CSI 201~ is pasted
        // text — a DA reply shape inside the paste must NOT be swallowed as a
        // reply (it is the user's pasted bytes).
        $events = $this->decoder->decode("\x1b[200~\x1b[?6n\x1b[201~");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertSame("\x1b[?6n", $events[0]->content);
        $this->assertSame(0, $this->decoder->drainedReplyCount());
    }

    public function testPasteEndMarkerSplitAcrossReads(): void
    {
        // The "\x1b[201~" sentinel arrives as "\x1b[201" + "~" — the held-back
        // partial marker must complete the paste instead of rotting in the body.
        $this->decoder->decode("\x1b[200~data\x1b[201");
        $events = $this->decoder->decode("~tail");
        $this->assertCount(5, $events, 'paste closes and its tail decodes in the same call');
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertSame('data', $events[0]->content);
        // Bytes after the paste end still decode.
        $this->assertSame('t', $events[1]->key);
        $this->assertSame('l', $events[4]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testStrayPasteEndMarkerOutsidePasteIsDroppedNotParked(): void
    {
        // A `CSI 201~` with no paste open is noise from a peer that left mode 2004
        // on: consume it, count the drop, keep the buffer clean (it must not sit in
        // remainder() forever, and suffix bytes must still decode).
        $events = $this->decoder->decode("\x1b[201~x");
        $this->assertCount(1, $events, 'only the trailing keystroke survives');
        $this->assertSame('x', $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
        $this->assertSame(1, $this->decoder->droppedUnknownCount());
        $events = $this->decoder->decode("\x1b[201~");
        $this->assertCount(0, $events);
        $this->assertSame('', $this->decoder->remainder(), 'even bare, the stray marker is consumed');
    }

    // ─── Trailing lone ESC: eager (default) vs deferred (opt-in) ──────────────

    public function testDeferTrailingEscapeKeepsSequenceIntactAcrossReads(): void
    {
        // With the option on, a chunk ending on ESC buffers instead of committing
        // Escape, so a reply split as `\x1b` + `[1;20R` still drains as CPR rather
        // than surfacing as seven literal keystrokes.
        $decoder = new EscapeDecoder(options: new EscapeDecoderOptions(deferTrailingEscape: true));
        $events = $decoder->decode("a\x1b");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame("\x1b", $decoder->remainder());
        $events = $decoder->decode("[1;20R");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TerminalReplyEvent::class, $events[0]);
        $this->assertSame(TerminalReplyEvent::FAMILY_CURSOR_POSITION, $events[0]->family);
        $this->assertSame('', $decoder->remainder());
    }

    public function testFlushDeferredEscapeResolvesPendingKeystroke(): void
    {
        $decoder = new EscapeDecoder(options: new EscapeDecoderOptions(deferTrailingEscape: true));
        $this->assertSame([], $decoder->flushDeferredEscape(), 'nothing pending → nothing flushed');
        $decoder->decode("\x1b");
        $this->assertSame("\x1b", $decoder->remainder());
        $events = $decoder->flushDeferredEscape();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyEvent::class, $events[0]);
        $this->assertSame('Escape', $events[0]->key);
        $this->assertSame('', $decoder->remainder());
        $this->assertSame([], $decoder->flushDeferredEscape());
    }

    public function testDeferredEscapeBeforePlainCharStillMergesAsAlt(): void
    {
        // Documented caveat: without a flush in between, a deferred ESC followed by
        // a printable byte still forms Alt+char, exactly like the eager path when
        // both bytes share a chunk.
        $decoder = new EscapeDecoder(options: new EscapeDecoderOptions(deferTrailingEscape: true));
        $decoder->decode("\x1b");
        $events = $decoder->decode("x");
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame('x', $events[0]->key);
    }

    // ─── Observability: drained reply vs dropped unknown ─────────────────────

    public function testCountersDistinguishDrainFromDrop(): void
    {
        $this->assertSame(0, $this->decoder->drainedReplyCount());
        $this->assertSame(0, $this->decoder->droppedUnknownCount());

        $this->decoder->decode("\x1b[?1;2c");   // DA1 reply   → drained
        $this->decoder->decode("\x1b[999Z");    // unknown CSI → dropped
        $this->decoder->decode("\x1b[4;12R");   // CPR reply   → drained

        $this->assertSame(2, $this->decoder->drainedReplyCount());
        $this->assertSame(1, $this->decoder->droppedUnknownCount());

        $this->decoder->reset();
        $this->assertSame(0, $this->decoder->drainedReplyCount());
        $this->assertSame(0, $this->decoder->droppedUnknownCount());
    }

    public function testEveryDocumentedFamilyIsReachable(): void
    {
        $probes = [
            TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES => "\x1b[?1;0c",
            TerminalReplyEvent::FAMILY_CURSOR_POSITION => "\x1b[3;7R",
            TerminalReplyEvent::FAMILY_DSR_STATUS => "\x1b[0n",
            TerminalReplyEvent::FAMILY_WINDOW_REPORT => "\x1b[8;24;80t",
            TerminalReplyEvent::FAMILY_KITTY_FLAGS => "\x1b[?1u",
            TerminalReplyEvent::FAMILY_MODE_REPORT => "\x1b[?4;3\$y",
            TerminalReplyEvent::FAMILY_CSI_PRIVATE => "\x1b[?7;1p",
            TerminalReplyEvent::FAMILY_STRING => "\x1b_q;1;rgb:00/00/00\x1b\\",
        ];
        foreach ($probes as $family => $bytes) {
            $decoder = new EscapeDecoder();
            $events = $decoder->decode($bytes);
            $this->assertCount(1, $events, "family {$family} must surface exactly one event");
            $this->assertInstanceOf(TerminalReplyEvent::class, $events[0]);
            $this->assertSame($family, $events[0]->family);
            $this->assertSame('', $decoder->remainder(), "family {$family} must not linger in the buffer");
        }
    }

    public function testFamilyRosterCoversAllEmittedFamilies(): void
    {
        // The README documents TerminalReplyEvent::FAMILIES as the complete
        // roster — guard against a new emitter escaping the list.
        $decoder = new EscapeDecoder();
        $decoder->decode("\x1b[?c\x1b[1;1R\x1b[5n\x1b[11t\x1b[?9u\x1b[?1;1\$y\x1b[?1p\x1b]0;x\x1b\\");
        $this->assertSame(8, $decoder->drainedReplyCount());
        $this->assertSame(
            [
                TerminalReplyEvent::FAMILY_DEVICE_ATTRIBUTES,
                TerminalReplyEvent::FAMILY_CURSOR_POSITION,
                TerminalReplyEvent::FAMILY_DSR_STATUS,
                TerminalReplyEvent::FAMILY_WINDOW_REPORT,
                TerminalReplyEvent::FAMILY_KITTY_FLAGS,
                TerminalReplyEvent::FAMILY_MODE_REPORT,
                TerminalReplyEvent::FAMILY_CSI_PRIVATE,
                TerminalReplyEvent::FAMILY_STRING,
            ],
            TerminalReplyEvent::FAMILIES,
        );
    }
}
