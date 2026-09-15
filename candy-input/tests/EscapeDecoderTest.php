<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\EscapeDecoderOptions;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event\TerminalReplyEvent;
use SugarCraft\Input\KeyModifier;

/**
 * Comprehensive tests for EscapeDecoder.
 */
final class EscapeDecoderTest extends TestCase
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

    // ─── Plain ASCII ─────────────────────────────────────────────────────────

    public function testPlainLetter(): void
    {
        $events = $this->decoder->decode('a');
        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyEvent::class, $events[0]);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame(KeyModifier::none(), $events[0]->modifiers);
        $this->assertSame('a', $events[0]->raw);
    }

    public function testPlainDigits(): void
    {
        $events = $this->decoder->decode('123');
        $this->assertCount(3, $events);
        foreach ($events as $e) {
            $this->assertInstanceOf(KeyEvent::class, $e);
        }
    }

    public function testMultipleChunks(): void
    {
        // Feed character by character
        $events = $this->decoder->decode('h');
        $this->assertCount(1, $events);
        $this->assertSame('h', $events[0]->key);

        $events = $this->decoder->decode('i');
        $this->assertCount(1, $events);
        $this->assertSame('i', $events[0]->key);
    }

    // ─── Control characters ───────────────────────────────────────────────

    public function testBackspace(): void
    {
        $events = $this->decoder->decode("\x7f");
        $this->assertCount(1, $events);
        $this->assertSame('Backspace', $events[0]->key);
    }

    public function testTab(): void
    {
        $events = $this->decoder->decode("\t");
        $this->assertCount(1, $events);
        $this->assertSame('Tab', $events[0]->key);
    }

    public function testEnter(): void
    {
        $events = $this->decoder->decode("\n");
        $this->assertCount(1, $events);
        $this->assertSame('Enter', $events[0]->key);
    }

    public function testCarriageReturn(): void
    {
        $events = $this->decoder->decode("\r");
        $this->assertCount(1, $events);
        $this->assertSame('Enter', $events[0]->key);
    }

    public function testEscape(): void
    {
        $events = $this->decoder->decode("\x1b");
        $this->assertCount(1, $events);
        $this->assertSame('Escape', $events[0]->key);
    }

    public function testCtrlLetter(): void
    {
        $events = $this->decoder->decode("\x01");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testCtrlC(): void
    {
        $events = $this->decoder->decode("\x03");
        $this->assertCount(1, $events);
        $this->assertSame('c', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    // ─── Arrow keys (legacy CSI) ────────────────────────────────────────────

    public function testArrowUp(): void
    {
        $events = $this->decoder->decode("\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    public function testArrowDown(): void
    {
        $events = $this->decoder->decode("\x1b[B");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowDown', $events[0]->key);
    }

    public function testArrowRight(): void
    {
        $events = $this->decoder->decode("\x1b[C");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowRight', $events[0]->key);
    }

    public function testArrowLeft(): void
    {
        $events = $this->decoder->decode("\x1b[D");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowLeft', $events[0]->key);
    }

    // ─── Function keys (legacy CSI) ─────────────────────────────────────────

    public function testF1(): void
    {
        // SS3 OP (real ESC O P form)
        $events = $this->decoder->decode("\x1bOP");
        $this->assertCount(1, $events);
        $this->assertSame('F1', $events[0]->key);
    }

    public function testF2(): void
    {
        // SS3 OQ
        $events = $this->decoder->decode("\x1bOQ");
        $this->assertCount(1, $events);
        $this->assertSame('F2', $events[0]->key);
    }

    public function testF3(): void
    {
        // SS3 OR
        $events = $this->decoder->decode("\x1bOR");
        $this->assertCount(1, $events);
        $this->assertSame('F3', $events[0]->key);
    }

    public function testF4(): void
    {
        // SS3 OS
        $events = $this->decoder->decode("\x1bOS");
        $this->assertCount(1, $events);
        $this->assertSame('F4', $events[0]->key);
    }

    public function testF5(): void
    {
        $events = $this->decoder->decode("\x1b[15~");
        $this->assertCount(1, $events);
        $this->assertSame('F5', $events[0]->key);
    }

    public function testF6(): void
    {
        $events = $this->decoder->decode("\x1b[17~");
        $this->assertCount(1, $events);
        $this->assertSame('F6', $events[0]->key);
    }

    public function testF7(): void
    {
        $events = $this->decoder->decode("\x1b[18~");
        $this->assertCount(1, $events);
        $this->assertSame('F7', $events[0]->key);
    }

    public function testF8(): void
    {
        $events = $this->decoder->decode("\x1b[19~");
        $this->assertCount(1, $events);
        $this->assertSame('F8', $events[0]->key);
    }

    public function testF9(): void
    {
        $events = $this->decoder->decode("\x1b[20~");
        $this->assertCount(1, $events);
        $this->assertSame('F9', $events[0]->key);
    }

    public function testF10(): void
    {
        $events = $this->decoder->decode("\x1b[21~");
        $this->assertCount(1, $events);
        $this->assertSame('F10', $events[0]->key);
    }

    public function testF11(): void
    {
        $events = $this->decoder->decode("\x1b[23~");
        $this->assertCount(1, $events);
        $this->assertSame('F11', $events[0]->key);
    }

    public function testF12(): void
    {
        $events = $this->decoder->decode("\x1b[24~");
        $this->assertCount(1, $events);
        $this->assertSame('F12', $events[0]->key);
    }

    // ─── Home / End / PgUp / PgDn / Insert / Delete ───────────────────────

    public function testHome(): void
    {
        $events = $this->decoder->decode("\x1b[H");
        $this->assertCount(1, $events);
        $this->assertSame('Home', $events[0]->key);
    }

    public function testEnd(): void
    {
        $events = $this->decoder->decode("\x1b[F");
        $this->assertCount(1, $events);
        $this->assertSame('End', $events[0]->key);
    }

    public function testInsert(): void
    {
        $events = $this->decoder->decode("\x1b[2~");
        $this->assertCount(1, $events);
        $this->assertSame('Insert', $events[0]->key);
    }

    public function testDelete(): void
    {
        $events = $this->decoder->decode("\x1b[3~");
        $this->assertCount(1, $events);
        $this->assertSame('Delete', $events[0]->key);
    }

    public function testPageUp(): void
    {
        $events = $this->decoder->decode("\x1b[5~");
        $this->assertCount(1, $events);
        $this->assertSame('PageUp', $events[0]->key);
    }

    public function testPageDown(): void
    {
        $events = $this->decoder->decode("\x1b[6~");
        $this->assertCount(1, $events);
        $this->assertSame('PageDown', $events[0]->key);
    }

    // ─── Partial sequence buffering ─────────────────────────────────────────

    public function testPartialSequenceReturnsEmpty(): void
    {
        $events = $this->decoder->decode("\x1b[");
        $this->assertCount(0, $events);
        $this->assertSame("\x1b[", $this->decoder->remainder());
    }

    public function testPartialSequenceCompletedOnNextCall(): void
    {
        $this->decoder->decode("\x1b[");
        $events = $this->decoder->decode("A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testResetClearsPartialBuffer(): void
    {
        $this->decoder->decode("\x1b[");
        $this->assertSame("\x1b[", $this->decoder->remainder());
        $this->decoder->reset();
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testPartialFKey(): void
    {
        $this->decoder->decode("\x1b[15");
        $events = $this->decoder->decode("~");
        $this->assertCount(1, $events);
        $this->assertSame('F5', $events[0]->key);
    }

    // ─── SGR 1006 Mouse ─────────────────────────────────────────────────────

    public function testSgrMouseLeftPress(): void
    {
        // CSI < 0 ; 10 ; 5 M
        $events = $this->decoder->decode("\x1b[<0;10;5M");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(MouseEvent::class, $events[0]);
        $m = $events[0];
        $this->assertSame(10, $m->x);
        $this->assertSame(5, $m->y);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $m->button);
        $this->assertSame(MouseEvent::ACTION_PRESS, $m->action);
    }

    public function testSgrMouseMiddlePress(): void
    {
        $events = $this->decoder->decode("\x1b[<1;20;15M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(20, $m->x);
        $this->assertSame(15, $m->y);
        $this->assertSame(MouseEvent::BUTTON_MIDDLE, $m->button);
    }

    public function testSgrMouseRightPress(): void
    {
        $events = $this->decoder->decode("\x1b[<2;30;25M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::BUTTON_RIGHT, $m->button);
    }

    public function testSgrMouseRelease(): void
    {
        // Release uses 'm' instead of 'M'
        $events = $this->decoder->decode("\x1b[<0;10;5m");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::ACTION_RELEASE, $m->action);
    }

    public function testSgrMouseScrollUp(): void
    {
        // Button 96 = scroll up
        $events = $this->decoder->decode("\x1b[<96;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertTrue($m->isScroll());
        $this->assertSame(10, $m->x);
        $this->assertSame(5, $m->y);
    }

    public function testSgrMouseScrollDown(): void
    {
        // Button 97 = scroll down
        $events = $this->decoder->decode("\x1b[<97;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertTrue($m->isScroll());
    }

    public function testSgrMouseWithShiftModifier(): void
    {
        // Shift encoded as 4 added to button (SGR bit 2)
        $events = $this->decoder->decode("\x1b[<4;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(0, $m->button);
        $this->assertTrue($m->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testSgrMouseWithAltModifier(): void
    {
        // Alt encoded as bit 3 = 8 added to button
        $events = $this->decoder->decode("\x1b[<8;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(0, $m->button);
        $this->assertTrue($m->modifiers->includes(KeyModifier::ALT));
    }

    // ─── Focus events ───────────────────────────────────────────────────────

    public function testFocusGained(): void
    {
        $events = $this->decoder->decode("\x1b[I");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(FocusEvent::class, $events[0]);
        $this->assertTrue($events[0]->gained);
    }

    public function testFocusLost(): void
    {
        $events = $this->decoder->decode("\x1b[O");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(FocusEvent::class, $events[0]);
        $this->assertFalse($events[0]->gained);
    }

    /**
     * Focus event with intermediate bytes — e.g. CSI 1 I.
     * The decoder does NOT recognize '\x1b[1I' as a focus event; the '1'
     * intermediate byte causes it to be treated as an unknown/modifier CSI.
     * This test documents the current behavior: no focus event emitted.
     */
    public function testFocusGainedWithIntermediateBytes(): void
    {
        // CSI 1 I — the '1' intermediate byte causes focus to NOT be recognized
        $events = $this->decoder->decode("\x1b[1I");
        // No events: the sequence is treated as unknown CSI and skipped
        $this->assertCount(0, $events, 'CSI with intermediate bytes is not recognized as focus event');
    }

    /**
     * Focus event with private-mode prefix — e.g. CSI ? 1 I.
     * A private-mode CSI can never be a keystroke (ECMA-48); the decoder drains
     * it as a TerminalReplyEvent instead of buffering it forever and poisoning
     * every later keystroke in the stream (the audit's "input-stream poisoning").
     */
    public function testFocusEventWithPrivateModePrefix(): void
    {
        // CSI ? 1 I — private-mode sequence: drained as a reply, not a key.
        $events = $this->decoder->decode("\x1b[?1I");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TerminalReplyEvent::class, $events[0]);
        $this->assertSame(TerminalReplyEvent::FAMILY_CSI_PRIVATE, $events[0]->family);
        $this->assertSame('', $this->decoder->remainder());
        // The stream stays alive: the next keystroke decodes normally.
        $events = $this->decoder->decode("\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    /**
     * Focus event with private-mode prefix and explicit mode number.
     * CSI ? 1004 I is a DECSET 1004 echo — private mode, drained as a reply.
     */
    public function testFocusEventWithDecset1004Sequence(): void
    {
        // CSI ? 1004 I — DECSET 1004 echo
        $events = $this->decoder->decode("\x1b[?1004I");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(TerminalReplyEvent::class, $events[0]);
        $this->assertSame(TerminalReplyEvent::FAMILY_CSI_PRIVATE, $events[0]->family);
        $this->assertSame([1004], $events[0]->params);
        $this->assertSame('', $this->decoder->remainder());
    }

    /**
     * Focus event immediately followed by another sequence in the SAME chunk.
     * The focus report is recognized structurally at its final byte, so
     * '\x1b[IA\x1b[C' yields focus-gained, then the 'A' key, then ArrowRight —
     * the report is never lost by a whole-tail string comparison.
     */
    public function testFocusEventFollowedByArrow(): void
    {
        $events = $this->decoder->decode("\x1b[IA\x1b[C");
        $this->assertCount(3, $events);
        $this->assertInstanceOf(FocusEvent::class, $events[0]);
        $this->assertTrue($events[0]->gained, 'bare CSI I is focus gained even mid-chunk');
        $this->assertSame('A', $events[1]->key);
        $this->assertSame('ArrowRight', $events[2]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    /**
     * Focus lost followed by a key in the same chunk: '\x1b[Oa' yields the
     * focus-lost report and then the 'a' keystroke.
     */
    public function testFocusLostFollowedByKey(): void
    {
        // '\x1b[Oa' — CSI O is focus lost; 'a' is the next byte.
        $events = $this->decoder->decode("\x1b[Oa");
        $this->assertCount(2, $events);
        $this->assertInstanceOf(FocusEvent::class, $events[0]);
        $this->assertFalse($events[0]->gained);
        $this->assertSame('a', $events[1]->key);
        $this->assertSame('', $this->decoder->remainder());
        $this->decoder->reset();
    }

    /**
     * Multiple consecutive focus sequences in one chunk both produce events:
     * '\x1b[I\x1b[O' partitions cleanly at each final byte.
     */
    public function testMultipleFocusEventsInOneChunk(): void
    {
        $events = $this->decoder->decode("\x1b[I\x1b[O");
        $this->assertCount(2, $events, 'Both focus events decode from one chunk');
        $this->assertInstanceOf(FocusEvent::class, $events[0]);
        $this->assertTrue($events[0]->gained, 'first: focus gained');
        $this->assertInstanceOf(FocusEvent::class, $events[1]);
        $this->assertFalse($events[1]->gained, 'second: focus lost');
        $this->assertSame('', $this->decoder->remainder());
    }

    // ─── Bracketed paste ────────────────────────────────────────────────────

    public function testBracketedPasteStart(): void
    {
        $events = $this->decoder->decode("\x1b[200~");
        $this->assertCount(0, $events);
        // Remainder is empty because the paste start was recognized;
        // subsequent bytes accumulate in the paste buffer until 201~
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testBracketedPasteComplete(): void
    {
        $this->decoder->decode("\x1b[200~");
        $events = $this->decoder->decode("hello\x1b[201~");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertSame('hello', $events[0]->content);
    }

    public function testBracketedPasteMultiLine(): void
    {
        $this->decoder->decode("\x1b[200~");
        $events = $this->decoder->decode("line1\nline2\x1b[201~");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertSame("line1\nline2", $events[0]->content);
    }

    public function testBracketedPasteEmbeddedEscapeSequence(): void
    {
        // Paste content containing an escape sequence — it should be treated as literal
        $this->decoder->decode("\x1b[200~");
        $pasteContent = "hello\x1b[Cworld"; // includes arrow key escape
        $events = $this->decoder->decode($pasteContent . "\x1b[201~");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        // The escape sequence inside paste is NOT decoded as an arrow key
        $this->assertSame("hello\x1b[Cworld", $events[0]->content);
    }

    public function testBracketedPasteTruncation(): void
    {
        $this->decoder->decode("\x1b[200~");
        $large = str_repeat('x', PasteEvent::MAX_SIZE + 100);
        $events = $this->decoder->decode($large . "\x1b[201~");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertSame(PasteEvent::MAX_SIZE, strlen($events[0]->content));
    }

    public function testPasteStartAndEndInSameChunk(): void
    {
        $events = $this->decoder->decode("\x1b[200~pasted\x1b[201~");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertSame('pasted', $events[0]->content);
    }

    // ─── Kitty keyboard protocol ──────────────────────────────────────────

    public function testKittyTabKey(): void
    {
        // CSI ? 9 ; Pm u — Kitty keyboard protocol for Tab
        $events = $this->decoder->decode("\x1b[9;0u");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyEvent::class, $events[0]);
        $this->assertSame('Tab', $events[0]->key);
    }

    public function testKittyEnterKey(): void
    {
        $events = $this->decoder->decode("\x1b[13;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Enter', $events[0]->key);
    }

    public function testKittyEscapeKey(): void
    {
        $events = $this->decoder->decode("\x1b[27;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Escape', $events[0]->key);
    }

    public function testKittyBackspaceKey(): void
    {
        $events = $this->decoder->decode("\x1b[127;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Backspace', $events[0]->key);
    }

    public function testKittyArrowUp(): void
    {
        // Kitty uses code 57399 for arrow up
        $events = $this->decoder->decode("\x1b[57399;0u");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    public function testKittyArrowDown(): void
    {
        $events = $this->decoder->decode("\x1b[57400;0u");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowDown', $events[0]->key);
    }

    public function testKittyF1(): void
    {
        $events = $this->decoder->decode("\x1b[11;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F1', $events[0]->key);
    }

    public function testKittyF12(): void
    {
        $events = $this->decoder->decode("\x1b[24;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F12', $events[0]->key);
    }

    public function testKittyLetterKey(): void
    {
        $events = $this->decoder->decode("\x1b[97;0u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
    }

    public function testKittyUpperCaseLetter(): void
    {
        // Uppercase (A=65) should return lowercase 'a'
        $events = $this->decoder->decode("\x1b[65;0u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
    }

    public function testKittyWithShiftModifier(): void
    {
        // modifiers field = 1 + bitmask; Shift = bit 0 → wire 2
        $events = $this->decoder->decode("\x1b[97;2u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testKittyWithCtrlModifier(): void
    {
        // Ctrl = bit 2 → mask 4 → wire 5
        $events = $this->decoder->decode("\x1b[97;5u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testKittyKeyRelease(): void
    {
        // Key release: modifier OR 0x20
        $events = $this->decoder->decode("\x1b[97;33u"); // 33 = 1 + 0x20 (legacy release bit)
        $this->assertCount(1, $events);
        $this->assertSame('ReleaseA', $events[0]->key);
    }

    // ─── Pathological inputs ────────────────────────────────────────────────

    public function testLoneEscape(): void
    {
        $events = $this->decoder->decode("\x1b");
        $this->assertCount(1, $events);
        $this->assertSame('Escape', $events[0]->key);
    }

    public function testDoubleEscapeAltEsc(): void
    {
        // ESC ESC — Alt + Escape
        $events = $this->decoder->decode("\x1b\x1b");
        $this->assertCount(1, $events);
        $this->assertSame('Escape', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
    }

    public function testEmptyString(): void
    {
        $events = $this->decoder->decode('');
        $this->assertCount(0, $events);
    }

    public function testRunawayCSI(): void
    {
        // Too many params should not crash
        $events = $this->decoder->decode("\x1b[" . str_repeat('9', 1000));
        $this->assertCount(0, $events); // incomplete, buffered
    }

    public function testMixedKeysAndSequences(): void
    {
        $events = $this->decoder->decode("a\x1b[Bb");
        $this->assertCount(3, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame('ArrowDown', $events[1]->key);
        $this->assertSame('b', $events[2]->key);
    }

    public function testInvalidUtf8MidSequence(): void
    {
        // Invalid UTF-8 bytes — should not throw, should emit as-is
        $events = $this->decoder->decode("a\xff\xfe\x1b[C");
        $this->assertCount(4, $events);
        $this->assertSame('a', $events[0]->key);
        // Invalid bytes treated as individual keys
        $this->assertSame("\xff", $events[1]->key);
        $this->assertSame("\xfe", $events[2]->key);
        $this->assertSame('ArrowRight', $events[3]->key);
    }

    public function testUnknownSequenceReturnsEmpty(): void
    {
        // A CSI we don't understand should not crash
        $events = $this->decoder->decode("\x1b[999Z");
        $this->assertCount(0, $events);
    }

    // ─── Remainder management ──────────────────────────────────────────────

    public function testRemainderAfterPartial(): void
    {
        $this->decoder->decode("\x1b[");
        $this->assertSame("\x1b[", $this->decoder->remainder());
    }

    public function testRemainderClearedAfterComplete(): void
    {
        $this->decoder->decode("\x1b[");
        $this->decoder->decode("A");
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testPartialSequenceBytesConsumedFromRemainder(): void
    {
        // Feed partial, then complete the sequence
        $this->decoder->decode("\x1b[");
        // remainder is now "\x1b[" — calling decode("A") prepends it automatically
        $events = $this->decoder->decode("A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    // ─── Reset ──────────────────────────────────────────────────────────────

    public function testResetClearsPasteBuffer(): void
    {
        $this->decoder->decode("\x1b[200~");
        // After paste start, remainder is empty (decoder is now in paste mode)
        $this->assertSame('', $this->decoder->remainder());
        $this->decoder->reset();
        $this->assertSame('', $this->decoder->remainder());
    }

    // ─── Additional Kitty edge cases ──────────────────────────────────────

    public function testKittySpaceKey(): void
    {
        $events = $this->decoder->decode("\x1b[32;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Space', $events[0]->key);
    }

    public function testKittyDeleteKey(): void
    {
        // Delete = code 3 in Kitty
        $events = $this->decoder->decode("\x1b[3;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Delete', $events[0]->key);
    }

    public function testKittyPageUp(): void
    {
        $events = $this->decoder->decode("\x1b[5;0u");
        $this->assertCount(1, $events);
        $this->assertSame('PageUp', $events[0]->key);
    }

    public function testKittyPageDown(): void
    {
        $events = $this->decoder->decode("\x1b[6;0u");
        $this->assertCount(1, $events);
        $this->assertSame('PageDown', $events[0]->key);
    }

    public function testKittyHome(): void
    {
        $events = $this->decoder->decode("\x1b[1;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Home', $events[0]->key);
    }

    public function testKittyEnd(): void
    {
        $events = $this->decoder->decode("\x1b[4;0u");
        $this->assertCount(1, $events);
        $this->assertSame('End', $events[0]->key);
    }

    public function testKittyWithAltModifier(): void
    {
        // Alt = bit 1 → mask 2 → wire 3
        $events = $this->decoder->decode("\x1b[97;3u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
    }

    public function testKittyWithMetaModifier(): void
    {
        // Meta = bit 5 → mask 0x20. The legacy release convention claims that
        // same bit when no explicit event-type sub-param is present, so Meta is
        // only expressible in the extended form "mods:event-type".
        $events = $this->decoder->decode("\x1b[97;33:1u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::META));
    }

    public function testKittyWithSuperModifier(): void
    {
        // Super = bit 3 → mask 8 → wire 9
        $events = $this->decoder->decode("\x1b[97;9u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SUPER));
    }

    // ─── SGR edge cases ──────────────────────────────────────────────────

    public function testSgrMouseMiddleButton(): void
    {
        $events = $this->decoder->decode("\x1b[<1;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::BUTTON_MIDDLE, $m->button);
        $this->assertSame(MouseEvent::ACTION_PRESS, $m->action);
    }

    public function testSgrMouseRightButton(): void
    {
        $events = $this->decoder->decode("\x1b[<2;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::BUTTON_RIGHT, $m->button);
    }

    public function testSgrMouseAllModifiers(): void
    {
        // Shift+Alt+Ctrl+Left = 4+8+16 = 28
        $events = $this->decoder->decode("\x1b[<28;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(0, $m->button);
        $this->assertTrue($m->modifiers->includes(KeyModifier::SHIFT));
        $this->assertTrue($m->modifiers->includes(KeyModifier::ALT));
        $this->assertTrue($m->modifiers->includes(KeyModifier::CTRL));
    }

    // ─── Partial sequence edge cases ────────────────────────────────────

    public function testPartialArrowUpFromRemainder(): void
    {
        $this->decoder->decode("\x1b[");
        $this->assertSame("\x1b[", $this->decoder->remainder());
        $events = $this->decoder->decode("A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testPartialFKeyFromRemainder(): void
    {
        $this->decoder->decode("\x1b[15");
        // decode() prepends its own remainder automatically
        $events = $this->decoder->decode("~");
        $this->assertCount(1, $events);
        $this->assertSame('F5', $events[0]->key);
    }

    public function testUnknownSequenceIsSkipped(): void
    {
        // CSI 999Z is not a known sequence — skip the first byte, process rest
        $events = $this->decoder->decode("\x1b[999Zx");
        $this->assertCount(1, $events);
        $this->assertSame('x', $events[0]->key);
    }

    public function testAltModifiedLetter(): void
    {
        // ESC followed by a letter = Alt+letter
        $events = $this->decoder->decode("\x1ba");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
    }

    public function testCtrlModifiedLetter(): void
    {
        $events = $this->decoder->decode("\x05"); // Ctrl+E = 0x05
        $this->assertCount(1, $events);
        $this->assertSame('e', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testPasteThenType(): void
    {
        // Paste completes in one call — the bytes after the end marker decode in
        // the SAME call (they are complete keystrokes, not a held partial).
        $events = $this->decoder->decode("\x1b[200~hello\x1b[201~world");
        $this->assertCount(6, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertSame('hello', $events[0]->content);
        $this->assertSame('w', $events[1]->key);
        $this->assertSame('o', $events[2]->key);
        $this->assertSame('r', $events[3]->key);
        $this->assertSame('l', $events[4]->key);
        $this->assertSame('d', $events[5]->key);
        $this->assertSame('', $this->decoder->remainder());
        // Normal typing keeps working
        $events = $this->decoder->decode("xyz");
        $this->assertCount(3, $events);
    }

    // ─── Step 11: ESC ESC trailing and Alt raw ───────────────────────────────

    public function testEscEscTrailingByteNotDropped(): void
    {
        // ESC ESC X should produce two events: Alt+Escape, then X (uppercase preserved)
        $events = $this->decoder->decode("\x1b\x1bX");
        $this->assertCount(2, $events);

        // First event: Alt+Escape
        $this->assertSame('Escape', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame("\x1b\x1b", $events[0]->raw);

        // Second event: plain X (uppercase preserved, not lowercased)
        $this->assertSame('X', $events[1]->key);
        $this->assertFalse($events[1]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame('X', $events[1]->raw);
    }

    public function testAltKeyRawIncludesLetter(): void
    {
        // Alt+A should have raw = "\x1ba" not just "\x1b"
        $events = $this->decoder->decode("\x1ba");
        $this->assertCount(1, $events);

        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame("\x1ba", $events[0]->raw);
    }

    // ─── Step 12: SS3 and modified-arrow tests ──────────────────────────────

    public function testSS3Arrows(): void
    {
        // SS3 arrow keys via ESC O A/B/C/D
        $tests = [
            "\x1bOA" => 'ArrowUp',
            "\x1bOB" => 'ArrowDown',
            "\x1bOC" => 'ArrowRight',
            "\x1bOD" => 'ArrowLeft',
        ];
        foreach ($tests as $seq => $expectedKey) {
            $events = $this->decoder->decode($seq);
            $this->assertCount(1, $events, "Failed for $seq");
            $this->assertSame($expectedKey, $events[0]->key);
            $this->assertSame(KeyModifier::NONE, $events[0]->modifiers->value());
        }
        $this->decoder->reset();
    }

    public function testSS3PartialBuffers(): void
    {
        // Partial SS3 sequence should buffer
        $events = $this->decoder->decode("\x1bO");
        $this->assertCount(0, $events);
        $this->assertSame("\x1bO", $this->decoder->remainder());

        // Complete with P → F1
        $events = $this->decoder->decode("P");
        $this->assertCount(1, $events);
        $this->assertSame('F1', $events[0]->key);
    }

    public function testModifiedArrowShift(): void
    {
        // CSI 1;2A = Shift+ArrowUp
        $events = $this->decoder->decode("\x1b[1;2A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
        $this->assertFalse($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertFalse($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testModifiedArrowCtrl(): void
    {
        // CSI 1;5C = Ctrl+ArrowRight
        $events = $this->decoder->decode("\x1b[1;5C");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowRight', $events[0]->key);
        $this->assertFalse($events[0]->modifiers->includes(KeyModifier::SHIFT));
        $this->assertFalse($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testModifiedArrowAlt(): void
    {
        // CSI 1;3D = Alt+ArrowLeft
        $events = $this->decoder->decode("\x1b[1;3D");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowLeft', $events[0]->key);
        $this->assertFalse($events[0]->modifiers->includes(KeyModifier::SHIFT));
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertFalse($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testUnmodifiedArrowStillWorks(): void
    {
        // Plain CSI A (no params) should still work
        $events = $this->decoder->decode("\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame(KeyModifier::NONE, $events[0]->modifiers->value());
    }

    public function testNumberedFKeyStillWorks(): void
    {
        // CSI 15~ = F5 (numbered function key)
        $events = $this->decoder->decode("\x1b[15~");
        $this->assertCount(1, $events);
        $this->assertSame('F5', $events[0]->key);
        $this->assertSame(KeyModifier::NONE, $events[0]->modifiers->value());
    }

    public function testAdversarialCsiNotMisdecodedAsF2(): void
    {
        // \x1b[xQ should NOT emit F2 (verifies Step 7 fix)
        $events = $this->decoder->decode("\x1b[xQ");
        $this->assertCount(1, $events);
        // Should be 'Q' (from Alt+Q fallback), NOT 'F2'
        $this->assertSame('Q', $events[0]->key);
        $this->assertFalse($events[0]->key === 'F2');
    }

    // ─── UTF-8 multibyte: one event per codepoint ─────────────────────────────

    public function testMultibyteTwoByteSingleEvent(): void
    {
        // "é" = 0xC3 0xA9 — one codepoint must be one event carrying both bytes,
        // never split into two per-byte KeyEvents.
        $events = $this->decoder->decode("\xc3\xa9");
        $this->assertCount(1, $events);
        $this->assertSame("\xc3\xa9", $events[0]->key);
        $this->assertSame("\xc3\xa9", $events[0]->raw);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testMultibyteThreeByteSingleEvent(): void
    {
        // "€" = 0xE2 0x82 0xAC
        $events = $this->decoder->decode("\xe2\x82\xac");
        $this->assertCount(1, $events);
        $this->assertSame("\xe2\x82\xac", $events[0]->key);
        $this->assertSame("\xe2\x82\xac", $events[0]->raw);
    }

    public function testMultibyteFourByteSingleEvent(): void
    {
        // "😀" = 0xF0 0x9F 0x98 0x80
        $events = $this->decoder->decode("\xf0\x9f\x98\x80");
        $this->assertCount(1, $events);
        $this->assertSame("\xf0\x9f\x98\x80", $events[0]->key);
        $this->assertSame("\xf0\x9f\x98\x80", $events[0]->raw);
    }

    public function testMixedAsciiAndMultibyte(): void
    {
        // "héllo" — 'é' (0xC3 0xA9) is a single event; ASCII chars one each.
        $events = $this->decoder->decode("h\xc3\xa9llo");
        $this->assertCount(5, $events);
        $this->assertSame('h', $events[0]->key);
        $this->assertSame("\xc3\xa9", $events[1]->key);
        $this->assertSame('l', $events[2]->key);
        $this->assertSame('l', $events[3]->key);
        $this->assertSame('o', $events[4]->key);
    }

    // ─── UTF-8 split across chunk boundaries ──────────────────────────────────

    public function testMultibyteSplitAcrossChunks(): void
    {
        // First byte of "é" arrives alone — buffered, no event yet.
        $events = $this->decoder->decode("\xc3");
        $this->assertCount(0, $events);
        $this->assertSame("\xc3", $this->decoder->remainder());

        // Continuation byte on the next call completes it — one event, correct.
        $events = $this->decoder->decode("\xa9");
        $this->assertCount(1, $events);
        $this->assertSame("\xc3\xa9", $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testFourByteSplitAcrossChunks(): void
    {
        // "😀" split 2 + 2 bytes across two decode() calls.
        $events = $this->decoder->decode("\xf0\x9f");
        $this->assertCount(0, $events);
        $this->assertSame("\xf0\x9f", $this->decoder->remainder());

        $events = $this->decoder->decode("\x98\x80");
        $this->assertCount(1, $events);
        $this->assertSame("\xf0\x9f\x98\x80", $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    // ─── Invalid UTF-8 never hangs ────────────────────────────────────────────

    public function testLoneContinuationByteFallsBack(): void
    {
        // 0xA9 is a continuation byte with no lead — single-byte fallback.
        $events = $this->decoder->decode("\xa9");
        $this->assertCount(1, $events);
        $this->assertSame("\xa9", $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testInvalidLeadByteFallsBack(): void
    {
        // 0xFF is never a valid UTF-8 lead — single-byte fallback, no hang.
        $events = $this->decoder->decode("\xff");
        $this->assertCount(1, $events);
        $this->assertSame("\xff", $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testValidLeadFollowedByNonContinuation(): void
    {
        // 0xC3 is a valid 2-byte lead, but 'z' is not a continuation byte. The
        // lead falls back to a single byte, then 'z' decodes normally.
        $events = $this->decoder->decode("\xc3z");
        $this->assertCount(2, $events);
        $this->assertSame("\xc3", $events[0]->key);
        $this->assertSame('z', $events[1]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    // ─── MAX_SEQUENCE_LENGTH cap on $remainder ────────────────────────────────

    public function testOversizedIncompleteCsiIsDiscarded(): void
    {
        // Unterminated CSI with 200 param bytes (202 total) breaches the 128-byte
        // cap: it is dropped, not buffered, so $remainder cannot grow unbounded.
        $events = $this->decoder->decode("\x1b[" . str_repeat('1', 200));
        $this->assertCount(0, $events);
        $this->assertLessThanOrEqual(128, strlen($this->decoder->remainder()));
        $this->assertSame('', $this->decoder->remainder(), 'oversized unterminated CSI must be discarded');
    }

    public function testSmallIncompleteCsiStillCompletes(): void
    {
        // A short unterminated CSI is under the cap: buffer it, then complete it
        // on the next call.
        $events = $this->decoder->decode("\x1b[");
        $this->assertCount(0, $events);
        $this->assertSame("\x1b[", $this->decoder->remainder());

        $events = $this->decoder->decode("A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    // ─── Perf regression guard (O(n) not O(n²)) ───────────────────────────────

    public function testLargeAsciiPasteIsLinear(): void
    {
        // ~500KB of printable ASCII. The old byte-by-byte substr loop was O(n²)
        // (~0.8s at 160KB → ~8s extrapolated to 500KB); the offset walk is O(n)
        // and finishes in a fraction of a second. A generous 2.0s bound cleanly
        // separates fixed from broken without CI flakiness.
        $n = 500000;
        $input = str_repeat('x', $n);

        $start = microtime(true);
        $events = $this->decoder->decode($input);
        $elapsed = microtime(true) - $start;

        $this->assertCount($n, $events);
        $this->assertLessThan(2.0, $elapsed, "decode of {$n} ASCII bytes took {$elapsed}s (expected O(n))");
    }

    // ─── CSI trailing-byte regression (handleCsiKey suffix isolation) ─────────

    /**
     * A modified key immediately followed by a printable byte in the SAME chunk
     * must emit BOTH events. Previously the CSI final byte was located by a
     * backward scan that peeled only one trailing byte, so the Ctrl+ArrowRight
     * was consumed with zero events and only 'z' survived.
     */
    public function testModifiedKeyFollowedByPrintable(): void
    {
        $events = $this->decoder->decode("\x1b[1;5Cz");
        $this->assertCount(2, $events);
        $this->assertSame('ArrowRight', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
        $this->assertSame('z', $events[1]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    /**
     * An unknown-but-complete CSI (final byte 'Z') followed by 2+ printable
     * bytes must drop the CSI (0 events) yet preserve EVERY trailing byte.
     * Previously only the last trailing byte ('c') survived.
     */
    public function testUnknownCsiFollowedByMultipleTrailingBytes(): void
    {
        $events = $this->decoder->decode("\x1b[999Zabc");
        $this->assertCount(3, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame('b', $events[1]->key);
        $this->assertSame('c', $events[2]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    /**
     * Two complete CSI sequences back-to-back in one chunk must both decode —
     * the first must not swallow the second's leading "ESC [".
     */
    public function testTwoBackToBackCsiSequences(): void
    {
        $events = $this->decoder->decode("\x1b[A\x1b[B");
        $this->assertCount(2, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame('ArrowDown', $events[1]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    /**
     * A modified key followed by a multibyte UTF-8 char in the same chunk: the
     * CSI must be isolated at its final byte and the whole codepoint preserved
     * as a single event (never split across the CSI/suffix boundary).
     */
    public function testModifiedKeyFollowedByMultibyteChar(): void
    {
        // é = U+00E9 = "\xc3\xa9"
        $events = $this->decoder->decode("\x1b[1;2A\xc3\xa9");
        $this->assertCount(2, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
        $this->assertSame("\xc3\xa9", $events[1]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    // ─── EscapeDecoderOptions protocol gating ────────────────────────────────

    /**
     * The documented `new EscapeDecoder($options)` construction must be
     * accepted (a bare ctor previously made the docblock example a lie).
     */
    public function testConstructsWithOptions(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions());
        $events = $decoder->decode('a');
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
    }

    /**
     * Default construction (no options) preserves current behavior: every
     * protocol stays enabled, so a mouse sequence still decodes to a MouseEvent.
     */
    public function testDefaultOptionsPreserveAllProtocols(): void
    {
        $decoder = new EscapeDecoder();
        $mouse = $decoder->decode("\x1b[<0;10;5M");
        $this->assertCount(1, $mouse);
        $this->assertInstanceOf(MouseEvent::class, $mouse[0]);

        $focus = $decoder->decode("\x1b[I");
        $this->assertCount(1, $focus);
        $this->assertInstanceOf(FocusEvent::class, $focus[0]);

        $paste = $decoder->decode("\x1b[200~hi\x1b[201~");
        $this->assertCount(1, $paste);
        $this->assertInstanceOf(PasteEvent::class, $paste[0]);

        $kitty = $decoder->decode("\x1b[97;1u");
        $this->assertCount(1, $kitty);
        $this->assertInstanceOf(KeyEvent::class, $kitty[0]);
        $this->assertSame('a', $kitty[0]->key);
    }

    /**
     * Load-bearing: mouse disabled → an SGR mouse sequence is NOT decoded as a
     * MouseEvent. It is structurally consumed (final byte M) and emits nothing,
     * leaving no remainder. Revert the enableMouse gate → a MouseEvent is
     * produced → this fails.
     */
    public function testMouseDisabledSuppressesMouseEvent(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableMouse: false));
        $events = $decoder->decode("\x1b[<0;10;5M");
        $this->assertSame([], $events);
        $this->assertSame('', $decoder->remainder());
    }

    /**
     * Mouse disabled must not disturb ordinary keys sharing the chunk: the
     * mouse sequence is dropped, the trailing 'z' survives.
     */
    public function testMouseDisabledStillDecodesSurroundingKeys(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableMouse: false));
        $events = $decoder->decode("\x1b[<0;10;5Mz");
        $this->assertCount(1, $events);
        $this->assertSame('z', $events[0]->key);
        $this->assertSame('', $decoder->remainder());
    }

    /**
     * Load-bearing: focus disabled → CSI I / CSI O emit no FocusEvent.
     */
    public function testFocusDisabledSuppressesFocusEvent(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableFocus: false));
        $this->assertSame([], $decoder->decode("\x1b[I"));
        $this->assertSame([], $decoder->decode("\x1b[O"));
        $this->assertSame('', $decoder->remainder());
    }

    /**
     * Load-bearing: kitty disabled → CSI ?…u emits no KeyEvent and is consumed.
     */
    public function testKittyDisabledSuppressesKeyEvent(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableKitty: false));
        $events = $decoder->decode("\x1b[97;1u");
        $this->assertSame([], $events);
        $this->assertSame('', $decoder->remainder());
    }

    /**
     * Load-bearing: paste disabled → the bracketed-paste markers are not
     * sentinels, so no PasteEvent is emitted. The markers are consumed as
     * ordinary CSI sequences and only the literal in-between key(s) survive.
     */
    public function testPasteDisabledSuppressesPasteEvent(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enablePaste: false));
        $events = $decoder->decode("\x1b[200~a\x1b[201~");
        foreach ($events as $e) {
            $this->assertNotInstanceOf(PasteEvent::class, $e);
        }
        // The 200~/201~ markers are dropped; the literal 'a' between them remains.
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame('', $decoder->remainder());
    }

    /**
     * Disabling one protocol leaves the others intact: mouse off, but focus /
     * kitty / paste still decode normally.
     */
    public function testSelectiveDisableLeavesOthersEnabled(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableMouse: false));

        $this->assertSame([], $decoder->decode("\x1b[<0;10;5M"));

        $focus = $decoder->decode("\x1b[I");
        $this->assertCount(1, $focus);
        $this->assertInstanceOf(FocusEvent::class, $focus[0]);

        $paste = $decoder->decode("\x1b[200~hi\x1b[201~");
        $this->assertCount(1, $paste);
        $this->assertInstanceOf(PasteEvent::class, $paste[0]);
    }

    // ─── Kitty F13-F24 key codes ──────────────────────────────────────────

    public function testKittyF13(): void
    {
        // CSI ? 25 ; Ps u — F13
        $events = $this->decoder->decode("\x1b[25;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F13', $events[0]->key);
    }

    public function testKittyF14(): void
    {
        $events = $this->decoder->decode("\x1b[26;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F14', $events[0]->key);
    }

    public function testKittyF15(): void
    {
        $events = $this->decoder->decode("\x1b[28;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F15', $events[0]->key);
    }

    public function testKittyF16(): void
    {
        $events = $this->decoder->decode("\x1b[29;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F16', $events[0]->key);
    }

    public function testKittyF17(): void
    {
        $events = $this->decoder->decode("\x1b[31;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F17', $events[0]->key);
    }

    public function testKittyF18IsSpace(): void
    {
        // Note: code 32 maps to Space, not F18 (conflict in original mapping)
        $events = $this->decoder->decode("\x1b[32;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Space', $events[0]->key);
    }

    public function testKittyF19(): void
    {
        $events = $this->decoder->decode("\x1b[33;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F19', $events[0]->key);
    }

    public function testKittyF20(): void
    {
        $events = $this->decoder->decode("\x1b[34;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F20', $events[0]->key);
    }

    // F21-F24 (codes 35-38) are not in the mapping, so they return null events

    // ─── SGR mouse drag (motion flag) ─────────────────────────────────────

    public function testSgrMouseDrag(): void
    {
        // Button 32 = button 0 with motion flag (bit 5) set
        // Motion flag takes precedence over press action
        $events = $this->decoder->decode("\x1b[<32;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::BUTTON_LEFT, $m->button);
        $this->assertSame(MouseEvent::ACTION_DRAG, $m->action);
    }

    public function testSgrMouseDragWithModifiers(): void
    {
        // Button 36 = button 0 with motion flag (32) + Shift (4)
        $events = $this->decoder->decode("\x1b[<36;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::BUTTON_LEFT, $m->button);
        $this->assertSame(MouseEvent::ACTION_DRAG, $m->action);
        $this->assertTrue($m->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testSgrMouseDragRight(): void
    {
        // Button 34 = button 2 with motion flag (32)
        $events = $this->decoder->decode("\x1b[<34;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::BUTTON_RIGHT, $m->button);
        $this->assertSame(MouseEvent::ACTION_DRAG, $m->action);
    }

    // ─── SS3 partial sequences ────────────────────────────────────────────

    public function testSS3PartialBuffersIncomplete(): void
    {
        // Just ESC O without final byte
        $events = $this->decoder->decode("\x1bO");
        $this->assertCount(0, $events);
        $this->assertSame("\x1bO", $this->decoder->remainder());
    }

    // ─── Kitty partial sequences ──────────────────────────────────────────

    public function testKittyPartialSequence(): void
    {
        // Incomplete Kitty sequence
        $events = $this->decoder->decode("\x1b[97");
        $this->assertCount(0, $events);
    }

    public function testKittyKeyReleaseWithModifiers(): void
    {
        // Key release: 1 + (modifiers OR legacy release bit 0x20)
        // Shift (1) + release bit (0x20) = mask 33 → wire 34
        $events = $this->decoder->decode("\x1b[97;34u");
        $this->assertCount(1, $events);
        $this->assertSame('ReleaseA', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testKittyKeyReleaseWithCtrl(): void
    {
        // Ctrl (4) + release bit (0x20) = mask 36 → wire 37
        $events = $this->decoder->decode("\x1b[97;37u");
        $this->assertCount(1, $events);
        $this->assertSame('ReleaseA', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testKittyKeyReleaseWithAlt(): void
    {
        // Alt (2) + release bit (0x20) = mask 34 → wire 35
        $events = $this->decoder->decode("\x1b[97;35u");
        $this->assertCount(1, $events);
        $this->assertSame('ReleaseA', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
    }

    // ─── SGR incomplete sequences ────────────────────────────────────────

    public function testSgrMouseIncompleteNoMOrM(): void
    {
        // Just CSI < Pb ; x ; y without M or m
        $events = $this->decoder->decode("\x1b[<0;10;5");
        $this->assertCount(0, $events);
        $this->assertNotSame('', $this->decoder->remainder());
    }

    public function testSgrMouseWrongParamCount(): void
    {
        // Only 2 params instead of 3
        $events = $this->decoder->decode("\x1b[<0;10M");
        $this->assertCount(0, $events);
    }

    // ─── Paste event edge cases ────────────────────────────────────────────

    public function testPasteOverflowTruncation(): void
    {
        // Large paste that exceeds MAX_SIZE
        $this->decoder->decode("\x1b[200~");
        $large = str_repeat('x', PasteEvent::MAX_SIZE + 1000);
        $events = $this->decoder->decode($large . "\x1b[201~");

        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        // Should be truncated to MAX_SIZE
        $this->assertSame(PasteEvent::MAX_SIZE, strlen($events[0]->content));
    }

    // ─── Mixed escape sequences ───────────────────────────────────────────

    public function testAlternateSS3AndCSIArrows(): void
    {
        // Some terminals use SS3 for arrows
        $events = $this->decoder->decode("\x1bOA\x1b[B");
        $this->assertCount(2, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame('ArrowDown', $events[1]->key);
    }

    public function testCsiWithIntermediateBytes(): void
    {
        // CSI with intermediate bytes should not be recognized as a standard key
        $events = $this->decoder->decode("\x1b[1;2;3A");
        // This doesn't match the modified arrow pattern (needs exactly 1 or 2 params)
        $this->assertCount(0, $events);
    }
}
