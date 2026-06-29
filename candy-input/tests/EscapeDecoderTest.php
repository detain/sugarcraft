<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\PasteEvent;
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
        $events = $this->decoder->decode("\x1b[?9;0u");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyEvent::class, $events[0]);
        $this->assertSame('Tab', $events[0]->key);
    }

    public function testKittyEnterKey(): void
    {
        $events = $this->decoder->decode("\x1b[?13;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Enter', $events[0]->key);
    }

    public function testKittyEscapeKey(): void
    {
        $events = $this->decoder->decode("\x1b[?27;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Escape', $events[0]->key);
    }

    public function testKittyBackspaceKey(): void
    {
        $events = $this->decoder->decode("\x1b[?127;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Backspace', $events[0]->key);
    }

    public function testKittyArrowUp(): void
    {
        // Kitty uses code 57399 for arrow up
        $events = $this->decoder->decode("\x1b[?57399;0u");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    public function testKittyArrowDown(): void
    {
        $events = $this->decoder->decode("\x1b[?57400;0u");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowDown', $events[0]->key);
    }

    public function testKittyF1(): void
    {
        $events = $this->decoder->decode("\x1b[?11;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F1', $events[0]->key);
    }

    public function testKittyF12(): void
    {
        $events = $this->decoder->decode("\x1b[?24;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F12', $events[0]->key);
    }

    public function testKittyLetterKey(): void
    {
        $events = $this->decoder->decode("\x1b[?97;0u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
    }

    public function testKittyUpperCaseLetter(): void
    {
        // Uppercase (A=65) should return lowercase 'a'
        $events = $this->decoder->decode("\x1b[?65;0u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
    }

    public function testKittyWithShiftModifier(): void
    {
        // Shift = bit 0 in modifier field
        $events = $this->decoder->decode("\x1b[?97;1u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testKittyWithCtrlModifier(): void
    {
        // Ctrl = bit 2 in modifier field
        $events = $this->decoder->decode("\x1b[?97;4u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testKittyKeyRelease(): void
    {
        // Key release: modifier OR 0x20
        $events = $this->decoder->decode("\x1b[?97;33u"); // 33 = 1 + 32 (Shift + release bit)
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
        $events = $this->decoder->decode("\x1b[?32;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Space', $events[0]->key);
    }

    public function testKittyDeleteKey(): void
    {
        // Delete = code 3 in Kitty
        $events = $this->decoder->decode("\x1b[?3;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Delete', $events[0]->key);
    }

    public function testKittyPageUp(): void
    {
        $events = $this->decoder->decode("\x1b[?5;0u");
        $this->assertCount(1, $events);
        $this->assertSame('PageUp', $events[0]->key);
    }

    public function testKittyPageDown(): void
    {
        $events = $this->decoder->decode("\x1b[?6;0u");
        $this->assertCount(1, $events);
        $this->assertSame('PageDown', $events[0]->key);
    }

    public function testKittyHome(): void
    {
        $events = $this->decoder->decode("\x1b[?1;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Home', $events[0]->key);
    }

    public function testKittyEnd(): void
    {
        $events = $this->decoder->decode("\x1b[?4;0u");
        $this->assertCount(1, $events);
        $this->assertSame('End', $events[0]->key);
    }

    public function testKittyWithAltModifier(): void
    {
        // Alt = bit 1
        $events = $this->decoder->decode("\x1b[?97;2u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
    }

    public function testKittyWithMetaModifier(): void
    {
        // Meta = bit 3
        $events = $this->decoder->decode("\x1b[?97;8u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::META));
    }

    public function testKittyWithSuperModifier(): void
    {
        // Super = bit 4
        $events = $this->decoder->decode("\x1b[?97;16u");
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
        // Paste completes in one call — remainder "world" buffered for next decode
        $this->decoder->decode("\x1b[200~hello\x1b[201~world");
        // Now remainder is "world" — calling decode("") flushes it
        $events = $this->decoder->decode("");
        $this->assertCount(5, $events); // "world" = 5 key events
        $this->assertSame('w', $events[0]->key);
        $this->assertSame('o', $events[1]->key);
        $this->assertSame('r', $events[2]->key);
        $this->assertSame('l', $events[3]->key);
        $this->assertSame('d', $events[4]->key);
        // After flush, normal typing works
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
}
