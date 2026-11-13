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

    // ─── Legacy modified number keys (xterm `CSI number;modifier ~`) ───────

    public function testCtrlDeleteNumberTildeForm(): void
    {
        // Ctrl+Delete arrives as CSI 3;5~ on terminals that keep the legacy
        // numbered-key family under a modifier (modifyFunctionKeys scheme).
        $events = $this->decoder->decode("\x1b[3;5~");
        $this->assertCount(1, $events);
        $this->assertSame('Delete', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
        $this->assertSame("\x1b[3;5~", $events[0]->raw);
    }

    public function testShiftDeleteTerminfoKdcFrame(): void
    {
        // The xterm-256color and alacritty terminfo entries ship
        // kDC (Shift+Delete) as exactly this frame.
        $events = $this->decoder->decode("\x1b[3;2~");
        $this->assertCount(1, $events);
        $this->assertSame('Delete', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
        $this->assertFalse($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testCtrlFunctionKeyNumberTildeForm(): void
    {
        // wezterm's terminfo ships kf34 = CSI 21;5~ — Ctrl+F10, 21 being the
        // plain F10 number, riding alongside the CSI 1;5P..S F1-F4 family.
        $events = $this->decoder->decode("\x1b[21;5~");
        $this->assertCount(1, $events);
        $this->assertSame('F10', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testAltFunctionKeyNumberTildeForm(): void
    {
        // xterm-256color ships kf53 = CSI 15;3~ — Alt+F5 in the same family.
        $events = $this->decoder->decode("\x1b[15;3~");
        $this->assertCount(1, $events);
        $this->assertSame('F5', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
    }

    public function testCtrlPageUpNumberTildeForm(): void
    {
        $events = $this->decoder->decode("\x1b[5;5~");
        $this->assertCount(1, $events);
        $this->assertSame('PageUp', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testExplicitNoModifierNumberTildeDecodesUnmodified(): void
    {
        // A ";1" modifier field is an explicit NO-modifier (the same de-base
        // law as the arrow family). Flipped 2026-11-03 (m6): the shape is
        // unmistakable and refusing it stranded real traffic (terminfo
        // ships e.g. kDN=\E[3;1~ on some entries); it now decodes as the
        // bare key, agreeing with the parameter-less "3~". ";0" stays
        // refused — see testZeroModifierNumberTildeRefusedBeforeBitFabrication.
        $events = $this->decoder->decode("\x1b[3;1~z");
        $this->assertCount(2, $events);
        $this->assertSame('Delete', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->equals(KeyModifier::none()));
        $this->assertSame('z', $events[1]->key);
        $this->assertSame(0, $this->decoder->droppedUnknownCount());
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testRxvtTildeSevenEightDecodeAsHomeAndEnd(): void
    {
        // m5 (2026-10 review): rxvt/aterm ship khomet=\E[7~ and kdelet=\E[8~.
        // xterm reserves 7~/8~ for the ISO-IR escape sequences but never SENDS
        // them as keys; a terminal that does send them means Home/End. They
        // were counted drops before — real traffic stranded (and the README's
        // Home/End claim was false on those terminals). Now decoded.
        $home = $this->decoder->decode("\x1b[7~");
        $this->assertCount(1, $home);
        $this->assertSame('Home', $home[0]->key);
        $this->assertSame("\x1b[7~", $home[0]->raw);
        $end = $this->decoder->decode("\x1b[8~");
        $this->assertCount(1, $end);
        $this->assertSame('End', $end[0]->key);
        $this->assertSame(0, $this->decoder->droppedUnknownCount());
        // The modified rxvt form decodes too: 7 with Ctrl is Ctrl+Home.
        $ctrlHome = $this->decoder->decode("\x1b[7;5~");
        $this->assertCount(1, $ctrlHome);
        $this->assertSame('Home', $ctrlHome[0]->key);
        $this->assertTrue($ctrlHome[0]->modifiers->includes(KeyModifier::CTRL));
    }

    public function testExplicitNoModifierArrowFormsDecodeUnmodified(): void
    {
        // m6 (2026-10 review): "CSI 1;1A" and the parameter-less "CSI 1A"
        // spell the de-based modifier value 1 = no-modifier. Refusing them
        // stranded keypresses from terminals that always emit the modifier
        // field; they now agree with bare "CSI A".
        foreach (["\x1b[1;1A", "\x1b[1A"] as $wire) {
            $events = $this->decoder->decode($wire);
            $this->assertCount(1, $events, $wire);
            $this->assertSame('ArrowUp', $events[0]->key, $wire);
            $this->assertTrue($events[0]->modifiers->equals(KeyModifier::none()), $wire);
        }
        $this->assertSame(0, $this->decoder->droppedUnknownCount());
        // And 1;0A stays refused: de-basing 0 would fabricate every bit.
        $events = $this->decoder->decode("\x1b[1;0A");
        $this->assertCount(0, $events);
        $this->assertSame(1, $this->decoder->droppedUnknownCount());
    }

    public function testZeroModifierNumberTildeRefusedBeforeBitFabrication(): void
    {
        // fromXtermParam(0) would de-base to -1 and light every modifier bit
        // (the same trap the modifyOtherKeys guard names). Refused as a
        // counted drop instead — no phantom Shift+Alt+Ctrl Delete.
        $events = $this->decoder->decode("\x1b[3;0~z");
        $this->assertCount(1, $events);
        $this->assertSame('z', $events[0]->key);
        $this->assertSame(1, $this->decoder->droppedUnknownCount());
    }

    public function testUnmappedNumberTildeFormStillDropped(): void
    {
        // 200/201 are bracketed-paste markers, not numbered-key table entries;
        // a modifier riding on one decodes to neither and stays a counted drop.
        $events = $this->decoder->decode("\x1b[200;5~x");
        $this->assertCount(1, $events);
        $this->assertSame('x', $events[0]->key);
        $this->assertSame(1, $this->decoder->droppedUnknownCount());
        $this->assertSame(0, $this->decoder->drainedReplyCount());
    }

    public function testModifyOtherKeysTripleParamFrameUntouchedByTwoParamGate(): void
    {
        // The 3-param modifyOtherKeys wrap (27;mods;keysym ~) must not be
        // captured by the widened 2-param numbered-key branch.
        $events = $this->decoder->decode("\x1b[27;5;97~");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
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

    public function testSgrMouseHorizontalWheel6667(): void
    {
        // Bit-6 + bit-1 forms: 66 = scroll-left (stored 98), 67 = scroll-right
        // (stored 99) — never a phantom press.
        $left = $this->decoder->decode("\x1b[<66;10;20M")[0];
        $this->assertTrue($left->isScroll());
        $this->assertSame(98, $left->button);
        $this->assertSame(10, $left->x);
        $this->assertSame(20, $left->y);

        $right = $this->decoder->decode("\x1b[<67;10;20M")[0];
        $this->assertTrue($right->isScroll());
        $this->assertSame(99, $right->button);
    }

    public function testSgrMouseHorizontalWheel128129NeverPhantomClicks(): void
    {
        // xterm bit-7 fold: 128/129 are the horizontal-wheel pair. Before the
        // fold they fell through to the plain-button path as phantom left-
        // (128 & 3 = 0) and right- (129 & 3 = 1) presses.
        $left = $this->decoder->decode("\x1b[<128;10;20M")[0];
        $this->assertInstanceOf(MouseEvent::class, $left);
        $this->assertTrue($left->isScroll());
        $this->assertSame(98, $left->button);
        $this->assertSame(MouseEvent::ACTION_SCROLL, $left->action);
        $this->assertSame(10, $left->x);
        $this->assertSame(20, $left->y);

        $right = $this->decoder->decode("\x1b[<129;10;20M")[0];
        $this->assertTrue($right->isScroll());
        $this->assertSame(99, $right->button);

        // Press-shape equivalence with the 66/67 forms the fold names, and the
        // release final cannot turn a wheel code into a release event.
        $rel = $this->decoder->decode("\x1b[<128;3;4m")[0];
        $this->assertTrue($rel->isScroll());
        $this->assertSame(98, $rel->button);
    }

    public function testSgrMouseHorizontalWheel128129WithMotionAndModifiers(): void
    {
        // 128 | 32 (motion) | 4 (shift): still a scroll-left, shift recorded.
        $m = $this->decoder->decode("\x1b[<164;5;6M")[0];
        $this->assertTrue($m->isScroll());
        $this->assertSame(98, $m->button);
        $this->assertTrue($m->modifiers->includes(KeyModifier::SHIFT));
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

    public function testSgrMouseNonNumericParamsAreRefusedNotZeroCoordinates(): void
    {
        // M11: (int) 'x' is 0 — without a numeric gate a forged frame lands a
        // MouseEvent at (0,0). Every part must be digits before any cast.
        $before = $this->decoder->droppedUnknownCount();
        $this->assertSame([], $this->decoder->decode("\x1b[<0;x;10M"));
        $this->assertSame([], $this->decoder->decode("\x1b[<x;10;10M"));
        $this->assertSame([], $this->decoder->decode("\x1b[<0;10;-5M"));
        $this->assertSame([], $this->decoder->decode("\x1b[<0;1 0;5M"));
        $this->assertSame($before + 4, $this->decoder->droppedUnknownCount());

        // The frame is consumed through its final byte: a real key after it
        // decodes live (resync law, not a re-buffered stall).
        $after = $this->decoder->decode("\x1b[<0;x;10MA");
        $this->assertCount(1, $after);
        $this->assertSame('A', $after[0]->key);
    }

    public function testSgrMouse1016ColonSubParamsDecodeFirstGroup(): void
    {
        // SGR 1016 sends pixel x and y as COLON sub-parameters ("640:10").
        // Colon groups are numeric: the first group is the value — the frame
        // must decode, not hit the M11 refusal gate.
        $events = $this->decoder->decode("\x1b[<0;640:10;480:7M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(640, $m->x);
        $this->assertSame(480, $m->y);
        $this->assertSame(MouseEvent::ACTION_PRESS, $m->action);
    }

    public function testDigitRunsOverNineAreRefusedNotSaturated(): void
    {
        // Shared digit-hygiene law (SI-02): a run longer than 9 digits would
        // (int)-saturate toward PHP_INT_MAX — a synthetic extreme the wire
        // never means. Refused and counted, on every numeric family.
        $before = $this->decoder->droppedUnknownCount();

        $this->assertSame([], $this->decoder->decode("\x1b[<0;" . str_repeat('9', 50000) . ";10M"));
        $this->assertSame([], $this->decoder->decode("\x1b[" . str_repeat('9', 20) . ";1u"));
        $this->assertSame([], $this->decoder->decode("\x1b[" . str_repeat('9', 20) . ";10;5M"));
        $this->assertSame($before + 3, $this->decoder->droppedUnknownCount());

        // The decoder re-syncs: the next real frame decodes.
        $events = $this->decoder->decode("\x1b[<0;10;5M");
        $this->assertCount(1, $events);
    }

    public function testKittyHostileFieldsAreRefusedAndCounted(): void
    {
        // SI-02: non-digit and oversized-parameter Kitty frames are counted
        // drops, never coercions. '=' is a legal CSI parameter byte, so a
        // "9=7" code field reaches the digit gate and must be refused.
        $before = $this->decoder->droppedUnknownCount();

        $this->assertSame([], $this->decoder->decode("\x1b[9=7;1u"));
        // '97=3' stays inside the parameter byte range ('=' is 0x3D) and
        // reaches the u-frame code field as a non-digit run — digit gate.
        $this->assertSame([], $this->decoder->decode("\x1b[97=3;1u"));
        // A stranded THIRD ';' field is a malformed frame: the old
        // explode(body, 2) silently truncation-fed "5;9" to the modifier cast.
        $refused = $this->decoder->decode("\x1b[97;5;9u");
        $this->assertSame([], $refused);
        $this->assertSame($before + 3, $this->decoder->droppedUnknownCount());

        // Well-formed frames still decode (regression guard on the rewrite).
        $events = $this->decoder->decode("\x1b[97;5u");
        $this->assertSame('a', $events[0]->key);
        $withAlts = $this->decoder->decode("\x1b[97:65;2u");
        $this->assertSame('a', $withAlts[0]->key);
    }

    // ─── Focus events ───────────────────────────────────────────────────────

    public function testFocusGained(): void
    {
        $events = $this->decoder->decode("\x1b[I");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(FocusEvent::class, $events[0]);
        $this->assertTrue($events[0]->gained);
        $this->assertSame("\x1b[I", $events[0]->raw);
    }

    public function testFocusLost(): void
    {
        $events = $this->decoder->decode("\x1b[O");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(FocusEvent::class, $events[0]);
        $this->assertFalse($events[0]->gained);
        $this->assertSame("\x1b[O", $events[0]->raw);
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
        // Spec functional code for Up is 57352; 57399 is KP0 (see parity pins).
        $events = $this->decoder->decode("\x1b[57352;0u");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    public function testKittyArrowDown(): void
    {
        $events = $this->decoder->decode("\x1b[57353;0u");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowDown', $events[0]->key);
    }

    public function testKittyArrowLeftAndRight(): void
    {
        $this->assertSame('ArrowLeft', $this->decoder->decode("\x1b[57350;0u")[0]->key);
        $this->assertSame('ArrowRight', $this->decoder->decode("\x1b[57351;0u")[0]->key);
    }

    public function testKittyKeypadBlockIsNotArrows(): void
    {
        // 57399-57402 are Kp0..Kp3 in the spec — the decoder used to invent
        // arrows here. Boundary pinned BOTH directions: 57398 is F35, 57399 is
        // KP0, 57408 is KP9, 57409 is KPDecimal.
        $this->assertSame('F35', $this->decoder->decode("\x1b[57398;0u")[0]->key);
        $this->assertSame('KP0', $this->decoder->decode("\x1b[57399;0u")[0]->key);
        $this->assertSame('KP1', $this->decoder->decode("\x1b[57400;0u")[0]->key);
        $this->assertSame('KP9', $this->decoder->decode("\x1b[57408;0u")[0]->key);
        $this->assertSame('KPDecimal', $this->decoder->decode("\x1b[57409;0u")[0]->key);
        // 57454 is the last table entry; 57455 must be a counted drop.
        $this->assertSame('IsoLevel5Shift', $this->decoder->decode("\x1b[57454;0u")[0]->key);
        $this->assertSame('MediaPlay', $this->decoder->decode("\x1b[57428;0u")[0]->key);
        $this->assertSame('LeftShift', $this->decoder->decode("\x1b[57441;0u")[0]->key);
    }

    public function testKittyF1(): void
    {
        // CSI-u space has NO functional F1 at keysym-style 11: 11 is the Ctrl-I
        // text codepoint region and the spec reserves <0xE000 for text. F1 is
        // 57364; the legacy 11u form is a complete-but-unknown drop.
        $this->assertSame('F1', $this->decoder->decode("\x1b[57364;0u")[0]->key);
        $this->assertSame('F12', $this->decoder->decode("\x1b[57375;0u")[0]->key);
        $this->assertSame('F35', $this->decoder->decode("\x1b[57398;0u")[0]->key);
    }

    public function testKittyLegacyTildeNumbersAreCountedDrops(): void
    {
        // 11;0u and 24;0u (old F1/F20/F12 pins) and 1..6 special-key forms now
        // fall through as counted drops: they are not text codepoints we can
        // name and not spec functional codes.
        $before = $this->decoder->droppedUnknownCount();
        $this->assertCount(0, $this->decoder->decode("\x1b[11;0u"));
        $this->assertCount(0, $this->decoder->decode("\x1b[24;0u"));
        $this->assertCount(0, $this->decoder->decode("\x1b[1;0u"));
        $this->assertCount(0, $this->decoder->decode("\x1b[6;0u"));
        $this->assertSame($before + 4, $this->decoder->droppedUnknownCount());
        // 57455 sits one past the table — also a counted drop.
        $this->assertCount(0, $this->decoder->decode("\x1b[57455;0u"));
        $this->assertSame($before + 5, $this->decoder->droppedUnknownCount());
    }

    public function testKittyTextCodepointsArriveAsTheCharacterThemselves(): void
    {
        // Everything below the 0xE000 functional boundary is a TEXT codepoint
        // per the spec — digits, punctuation and Unicode runes must arrive as
        // the character itself (the README's "complete vocabulary"), exactly
        // as the modifyOtherKeys leg already names keysyms 32..126. Counting
        // them as drops silently loses every unmodified digit and symbol typed
        // under Kitty protocol with disambiguation off.
        $before = $this->decoder->droppedUnknownCount();
        $this->assertSame('0', $this->decoder->decode("\x1b[48u")[0]->key);
        $this->assertSame('!', $this->decoder->decode("\x1b[33u")[0]->key);
        $this->assertSame('é', $this->decoder->decode("\x1b[233u")[0]->key);
        $this->assertSame('€', $this->decoder->decode("\x1b[8364u")[0]->key);
        // A released digit keeps the Release prefix; ucfirst is a no-op on a
        // non-letter, so the name is Release0 (match isRelease(), not prefix).
        $this->assertSame('Release0', $this->decoder->decode("\x1b[48;33u")[0]->key);
        $this->assertSame($before, $this->decoder->droppedUnknownCount());
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
        $this->assertTrue($events[0]->isRelease());
    }

    public function testKittyRepeatDecodesAsPress(): void
    {
        // Event type 2 (auto-repeat) is surfaced as an ordinary press annotated
        // with isRepeat (m7) — documented divergence; a repeat IS a keystroke.
        $events = $this->decoder->decode("\x1b[97;2:2u");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->isRepeat);
        $this->assertFalse($events[0]->isRelease());
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testPlainPressCarriesNoReleaseOrRepeatFlags(): void
    {
        $events = $this->decoder->decode("\x1b[97;2u"); // shift+a press
        $this->assertCount(1, $events);
        $this->assertFalse($events[0]->isRelease());
        $this->assertFalse($events[0]->isRepeat);
    }

    public function testKittyLockStateBitsRideTheExplicitEventTypeForm(): void
    {
        // m4: Kitty modifier bits 6=CapsLock(64) and 7=NumLock(128) map to the
        // CAPS_LOCK/NUM_LOCK constants on the explicit-event-type form.
        $caps = $this->decoder->decode("\x1b[97;65:1u");  // 1 + 64
        $this->assertTrue($caps[0]->modifiers->includes(KeyModifier::CAPS_LOCK));

        $num = $this->decoder->decode("\x1b[97;129:1u");  // 1 + 128
        $this->assertTrue($num[0]->modifiers->includes(KeyModifier::NUM_LOCK));

        // The legacy event-flag form masks the field to 0x1f before mapping,
        // so lock bits cannot ride it — and must not leak in as press flags.
        $legacy = $this->decoder->decode("\x1b[97;65u");
        $this->assertSame('a', $legacy[0]->key);
        $this->assertFalse($legacy[0]->modifiers->includes(KeyModifier::CAPS_LOCK));
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

    public function testStaleRemainderDoesNotSurvivePasteBoundary(): void
    {
        // Probe shape (campaign rerun A3b): an incomplete CSI buffered BEFORE
        // a paste must not stitch onto the first post-paste keystroke.
        $this->decoder->decode("\x1b[");
        $this->assertSame("\x1b[", $this->decoder->remainder());

        $this->decoder->decode("\x1b[200~pasted\x1b[201~");
        // The paste-start boundary flushed the orphan fragment.
        $this->assertSame('', $this->decoder->remainder());

        $events = $this->decoder->decode("x");
        $this->assertCount(1, $events);
        $this->assertSame('x', $events[0]->key);
    }

    public function testArrowKeyAfterStaleRemainderPasteSurfaces(): void
    {
        // Same boundary, real escape-sequence keystroke: "\x1b[" + "\x1b[A"
        // must decode as a lone ArrowUp, never as a swallowed unknown CSI.
        $this->decoder->decode("\x1b[");
        $this->decoder->decode("\x1b[200~abc\x1b[201~");
        $events = $this->decoder->decode("\x1b[A");
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
        // Spec functional code for Delete is 57349 (the tilde-era number 3 is
        // text space and no longer names keys in CSI-u frames).
        $events = $this->decoder->decode("\x1b[57349;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Delete', $events[0]->key);
    }

    public function testKittyPageUp(): void
    {
        $events = $this->decoder->decode("\x1b[57354;0u");
        $this->assertCount(1, $events);
        $this->assertSame('PageUp', $events[0]->key);
    }

    public function testKittyPageDown(): void
    {
        $events = $this->decoder->decode("\x1b[57355;0u");
        $this->assertCount(1, $events);
        $this->assertSame('PageDown', $events[0]->key);
    }

    public function testKittyHome(): void
    {
        $events = $this->decoder->decode("\x1b[57356;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Home', $events[0]->key);
    }

    public function testKittyEnd(): void
    {
        $events = $this->decoder->decode("\x1b[57357;0u");
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

    public function testAltModifiedControlKeysShareTheBareVocabulary(): void
    {
        // M12: the ESC-prefixed forms used to surface the raw control byte as
        // the key name while the bare form named them symbolically — two
        // vocabularies for one key family. Alt-modified Tab/Enter/Backspace
        // now carry the same names as unmodified presses, raw preserved.
        foreach ([
            ["\x1b\t", 'Tab', "\x1b\t"],
            ["\x1b\r", 'Enter', "\x1b\r"],
            ["\x1b\n", 'Enter', "\x1b\n"],
            ["\x1b\x7f", 'Backspace', "\x1b\x7f"],
        ] as [$wire, $name, $raw]) {
            $events = $this->decoder->decode($wire);
            $this->assertCount(1, $events, $wire);
            $this->assertSame($name, $events[0]->key);
            $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
            $this->assertSame($raw, $events[0]->raw);
        }
    }

    public function testTripleEscapeDecodesAltEscapeThenArrowNotPhantomBrackets(): void
    {
        // M12 evidence pin: xterm delivers Alt+ArrowRight as ESC ESC then
        // CSI C. The old review phantom ('[' + 'A' keys leaking) does not
        // reproduce on the current core — ESC ESC settles as Alt+Escape and
        // the following CSI decodes as the arrow it is, nothing else.
        $events = $this->decoder->decode("\x1b\x1b\x1b[C");
        $this->assertCount(2, $events);
        $this->assertSame('Escape', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame('ArrowRight', $events[1]->key);
        $this->assertFalse($events[1]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame('', $this->decoder->remainder());
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

    // ─── Alt + multibyte UTF-8 (bubbletea decodes the full rune after ESC) ──

    public function testAltModifiedTwoByteRune(): void
    {
        // ESC + 'é' (0xc3 0xa9): ONE Alt+é event — the codepoint must never be
        // split into Alt+0xc3 plus a stray 0xa9 lone-byte key.
        $events = $this->decoder->decode("\x1b\xc3\xa9");
        $this->assertCount(1, $events);
        $this->assertSame('é', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame("\x1b\xc3\xa9", $events[0]->raw);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testAltModifiedThreeByteRuneFollowedByKeys(): void
    {
        $euro = "\xe2\x82\xac";
        $events = $this->decoder->decode("\x1b" . $euro . 'x');
        $this->assertCount(2, $events);
        $this->assertSame($euro, $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame("\x1b" . $euro, $events[0]->raw);
        $this->assertSame('x', $events[1]->key);
        $this->assertFalse($events[1]->modifiers->includes(KeyModifier::ALT));
    }

    public function testAltModifiedFourByteRune(): void
    {
        $party = "\xf0\x9f\x8e\x89";
        $events = $this->decoder->decode("\x1b" . $party);
        $this->assertCount(1, $events);
        $this->assertSame($party, $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame("\x1b" . $party, $events[0]->raw);
    }

    public function testAltRuneSplitAcrossChunksCompletesWhole(): void
    {
        // Incomplete-tail boundary: ESC + lead byte at the chunk end must be
        // HELD (not shredded into Alt+half-rune), then completed by the next
        // read — the same stitching the plain UTF-8 path already does.
        $this->assertSame([], $this->decoder->decode("\x1b\xc3"));
        $this->assertSame("\x1b\xc3", $this->decoder->remainder());

        $events = $this->decoder->decode("\xa9");
        $this->assertCount(1, $events);
        $this->assertSame('é', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame("\x1b\xc3\xa9", $events[0]->raw);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testAltRuneWithInvalidContinuationResyncs(): void
    {
        // ESC + lead + NON-continuation byte: malformed — Alt+lead alone, then
        // the offending byte decodes normally (mirrors the plain resync path).
        $events = $this->decoder->decode("\x1b\xc3x");
        $this->assertCount(2, $events);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
        $this->assertSame("\x1b\xc3", $events[0]->raw);
        $this->assertSame('x', $events[1]->key);
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

    public function testSurrogateEncodedLeadResyncsByteWise(): void
    {
        // ED A0 80 encodes U+D800 — a surrogate half, which cannot legally
        // exist in well-formed UTF-8 (mbstring, json and MySQL all reject it).
        // The walker must resync byte-by-byte instead of minting a codepoint
        // every downstream codec would choke on (m12).
        $events = $this->decoder->decode("\xed\xa0\x80");
        $this->assertCount(3, $events);
        $this->assertSame("\xed", $events[0]->key);
        $this->assertSame("\xa0", $events[1]->key);
        $this->assertSame("\x80", $events[2]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testEdLeadBelowTheSurrogatesStillFormsOneCodepoint(): void
    {
        // ED 9F BF is U+D7FF, the last legal codepoint below the surrogate
        // range: the classifier must not over-refuse the whole ED block.
        $events = $this->decoder->decode("\xed\x9f\xbf");
        $this->assertCount(1, $events);
        $this->assertSame("\xed\x9f\xbf", $events[0]->key);
        $this->assertSame('', $this->decoder->remainder());
    }

    public function testEscapeWithSurrogateEncodedLeadKeepsAltOnTheLeadAlone(): void
    {
        // ESC + ED A0 80: Alt applies to the (invalid) lead byte alone and the
        // continuation strays resync separately — never a forged Alt+rune
        // whose payload no consumer can re-encode.
        $events = $this->decoder->decode("\x1b\xed\xa0\x80");
        $this->assertCount(3, $events);
        $this->assertSame("\x1b\xed", $events[0]->raw);
        $this->assertSame("\xa0", $events[1]->key);
        $this->assertSame("\x80", $events[2]->key);
    }

    public function testSurrogateSplitAcrossChunksStillResyncs(): void
    {
        // A bare 0xED at a chunk end is buffered as a plausible lead; when the
        // next chunk reveals A0, the re-walk re-classifies and the sequence
        // resyncs byte-by-byte — chunk-invariant with the one-read case above.
        $this->assertCount(0, $this->decoder->decode("\xed"));
        $events = $this->decoder->decode("\xa0\x80");
        $this->assertCount(3, $events);
        $this->assertSame("\xed", $events[0]->key);
        $this->assertSame("\xa0", $events[1]->key);
        $this->assertSame("\x80", $events[2]->key);
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

    // ─── C2: cap force-close must never re-inject leftover bytes as keys ──────

    public function testOversizedSequenceTailBytesAreNeverKeys(): void
    {
        // One read: ESC [ + 200 param bytes + 'A' (the ECMA-48 final byte)
        // + 'BC'. 'A' terminates the dead sequence and is consumed with it;
        // only genuine fresh traffic ('BC') may surface as keys.
        $wire = "\x1b[" . str_repeat('9', 200) . "ABC";
        $oneRead = $this->decoder->decode($wire);
        $this->assertSame(['B', 'C'], array_map(static fn ($e) => $e instanceof KeyEvent ? $e->key : null, $oneRead));
        $this->assertSame(1, $this->decoder->droppedUnknownCount());

        // Split reads (the attacker's chunking lever): the same bytes arriving
        // across three reads must produce the SAME events — the pre-fix code
        // turned the continuation '999…A' into typed keys on the next read.
        $split = new EscapeDecoder();
        $events = [];
        $events = array_merge($events, $split->decode("\x1b[" . str_repeat('9', 200)));
        $this->assertSame([], $events, 'no events while the dead sequence is still open');
        $events = array_merge($events, $split->decode("A"));
        $this->assertSame([], $events, "the final byte is consumed by the discard, never keyed");
        $events = array_merge($events, $split->decode("BC"));
        $this->assertSame(['B', 'C'], array_map(static fn ($e) => $e instanceof KeyEvent ? $e->key : null, $events));
        $this->assertSame(1, $split->droppedUnknownCount());

        // 8192-chunk drip (a real TTY read shape): across every split the
        // hostile sequence contributes NO digit keystroke. (The junk's ':'
        // stays in the parameter range 0x30-0x3F; 'n' of "nested" is the first
        // final byte and closes the dead sequence there.)
        $drip = new EscapeDecoder();
        $all = [];
        $stream = "\x1b[" . str_repeat('9', 10_000) . ":nested\x1b" . "ABC";
        foreach (str_split($stream, 8192) as $chunk) {
            $all = array_merge($all, $drip->decode($chunk));
        }
        foreach ($all as $event) {
            if ($event instanceof KeyEvent) {
                $this->assertNotSame('9', $event->key, 'overflowed parameter byte surfaced as a keystroke');
            }
        }
    }

    public function testOversizedSequenceResyncsOnEmbeddedEscape(): void
    {
        // A bare ESC inside parameter traffic resynchronizes the parser: the
        // ESC starts a NEW sequence that decodes normally, and it is not
        // swallowed forever (liveness: a hostile open sequence cannot mute the
        // keyboard for the rest of the session).
        $decoder = new EscapeDecoder();
        $this->assertSame([], $decoder->decode("\x1b[" . str_repeat('9', 200)));
        $events = $decoder->decode("\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
        $this->assertSame(1, $decoder->droppedUnknownCount(), 'ESC resync does not re-count the drop');
    }

    public function testForgedPasteTailCannotBecomeKeys(): void
    {
        // 1 MiB + 600 filler arriving WITHOUT a terminator in the pass (the
        // old force-close hole): announce once, then an attacker-shaped
        // command tail, then the real terminator in later reads. Expect
        // exactly ONE truncated PasteEvent and ZERO keystrokes from the
        // tail; traffic after the 201~ is live.
        $tail = "rm -rf /\nABC";
        $events = $this->decoder->decode("\x1b[200~" . str_repeat('x', PasteEvent::MAX_SIZE + 600));
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);
        $this->assertTrue($events[0]->truncated);
        $this->assertSame(600, $this->decoder->discardedPasteBytes(), 'the cap-straddle overshoot is counted');

        $this->assertSame([], $this->decoder->decode($tail), 'the command tail became nothing, not keys');
        $this->assertSame(600 + strlen($tail), $this->decoder->discardedPasteBytes());

        $events = $this->decoder->decode("\x1b[201~A");
        $this->assertCount(1, $events);
        $this->assertSame('A', $events[0]->key, 'only the post-terminator A is a key');
    }

    public function testForgedPasteWithoutTerminatorStaysOpenUntilReset(): void
    {
        // Safety direction: a forged paste whose 201~ never arrives keeps the
        // paste open and discards everything — keystrokes are muted (loud via
        // the announce + counter), never fabricated. reset() is the recovery
        // lever, unlocked for hosts through the driver seam.
        $decoder = new EscapeDecoder();
        $events = $decoder->decode("\x1b[200~" . str_repeat('y', PasteEvent::MAX_SIZE + 100));
        $this->assertCount(1, $events);
        $this->assertInstanceOf(PasteEvent::class, $events[0]);

        $this->assertSame([], $decoder->decode("ABC\n"), 'post-cap body must not become keys');
        $this->assertSame([], $decoder->decode("\x1b[A"), 'even arrows are swallowed while open');
        $this->assertGreaterThanOrEqual(5, $decoder->discardedPasteBytes());

        $this->assertSame([], $decoder->decode("\x1b[201~"), 'the real terminator closes quietly');
        $events = $decoder->decode("A");
        $this->assertCount(1, $events);
        $this->assertSame('A', $events[0]->key, 'keyboard live again after the close');

        // And reset() recovers from the never-terminated state directly.
        $stuck = new EscapeDecoder();
        $stuck->decode("\x1b[200~" . str_repeat('z', PasteEvent::MAX_SIZE + 1));
        $this->assertSame([], $stuck->decode("dangerous\n"));
        $stuck->reset();
        $events = $stuck->decode("A");
        $this->assertCount(1, $events);
        $this->assertSame('A', $events[0]->key);
        $this->assertSame(0, $stuck->discardedPasteBytes(), 'reset clears the discard ledger');
    }

    public function testPasteOverflowAnnouncesOnceAcrossChunkedBody(): void
    {
        // Same forged paste dripped in 8192-byte reads (the tmux pipe-pane
        // shape): still exactly ONE announce, ZERO keystrokes from the body,
        // close on the real terminator.
        $decoder = new EscapeDecoder();
        $all = [];
        $body = "\x1b[200~" . str_repeat('w', 3 * 1024 * 1024) . "evil-key\x1b[201~";
        foreach (str_split($body, 8192) as $chunk) {
            $all = array_merge($all, $decoder->decode($chunk));
        }
        $pastes = array_filter($all, static fn ($e) => $e instanceof PasteEvent);
        $keys = array_filter($all, static fn ($e) => $e instanceof KeyEvent);
        $this->assertCount(1, $pastes, '3 MiB past the cap announces exactly once');
        $this->assertTrue(array_values($pastes)[0]->truncated);
        $this->assertSame([], $keys, 'no body byte, however key-shaped, is promoted');
        // Everything past the 1 MiB clip — the whole rest of the body before
        // the terminator — is accounted as discarded, not re-typed.
        $this->assertSame(3 * 1024 * 1024 - PasteEvent::MAX_SIZE + 8, $decoder->discardedPasteBytes());
        // And the keyboard is live after the close:
        $events = $decoder->decode("A");
        $this->assertCount(1, $events);
        $this->assertSame('A', $events[0]->key);
    }

    public function testSequenceDiscardStateSurvivesAndIsClearedByReset(): void
    {
        $decoder = new EscapeDecoder();
        $decoder->decode("\x1b[" . str_repeat('9', 200));
        // Bytes keep being swallowed while open…
        $this->assertSame([], $decoder->decode("99999"));
        // …but reset() drops the discard state too: a fresh ESC-sequence decodes.
        $decoder->reset();
        $this->assertSame(0, $decoder->droppedUnknownCount(), 'reset zeroes the counters');
        $events = $decoder->decode("\x1b[A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
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

        $Kitty = $decoder->decode("\x1b[97;1u");
        $this->assertCount(1, $Kitty);
        $this->assertInstanceOf(KeyEvent::class, $Kitty[0]);
        $this->assertSame('a', $Kitty[0]->key);
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
        // A disabled family's frames are counted drops, not drained replies.
        $this->assertSame(1, $decoder->droppedUnknownCount());
        $this->assertSame(0, $decoder->drainedReplyCount());
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
     * Load-bearing: raw X10 (ESC M + 3 payload bytes) disabled must tally like
     * every other disabled family — a counted silent drop that stays in byte
     * sync, not an uncounted pass-through (parity with SGR/urxvt-1015/Kitty).
     */
    public function testX10MouseDisabledIsACountedDrop(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableMouse: false));
        // chr(32)=left press, chr(33)/chr(34) = 1-based x/y; trailing 'z' proves sync.
        $events = $decoder->decode("\x1b[M" . chr(32) . chr(33) . chr(34) . "z");
        $this->assertCount(1, $events);
        $this->assertSame('z', $events[0]->key);
        $this->assertSame('', $decoder->remainder());
        $this->assertSame(1, $decoder->droppedUnknownCount());
        $this->assertSame(0, $decoder->drainedReplyCount());
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
     * Load-bearing: Kitty disabled → CSI ?…u emits no KeyEvent and is consumed.
     */
    public function testKittyDisabledSuppressesKeyEvent(): void
    {
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableKitty: false));
        $events = $decoder->decode("\x1b[97;1u");
        $this->assertSame([], $events);
        $this->assertSame('', $decoder->remainder());
        $this->assertSame(1, $decoder->droppedUnknownCount());
        $this->assertSame(0, $decoder->drainedReplyCount());
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
     * Kitty / paste still decode normally.
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

    // ─── Kitty F13-F35 key codes (spec block 57376..) ─────────────────────

    public function testKittyF13(): void
    {
        $events = $this->decoder->decode("\x1b[57376;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F13', $events[0]->key);
    }

    public function testKittyF14(): void
    {
        $events = $this->decoder->decode("\x1b[57377;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F14', $events[0]->key);
    }

    public function testKittyF15(): void
    {
        $events = $this->decoder->decode("\x1b[57378;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F15', $events[0]->key);
    }

    public function testKittyF16(): void
    {
        $events = $this->decoder->decode("\x1b[57379;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F16', $events[0]->key);
    }

    public function testKittyF17(): void
    {
        $events = $this->decoder->decode("\x1b[57380;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F17', $events[0]->key);
    }

    public function testKittyF18IsSpace(): void
    {
        // The legacy table listed 32 => F18, shadowed by the Space text code.
        // In the spec's CSI-u space 32 IS Space and F18 travels as 57381.
        $events = $this->decoder->decode("\x1b[32;0u");
        $this->assertCount(1, $events);
        $this->assertSame('Space', $events[0]->key);
        $this->assertSame('F18', $this->decoder->decode("\x1b[57381;0u")[0]->key);
    }

    public function testKittyF19(): void
    {
        $events = $this->decoder->decode("\x1b[57382;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F19', $events[0]->key);
    }

    public function testKittyF20(): void
    {
        $events = $this->decoder->decode("\x1b[57383;0u");
        $this->assertCount(1, $events);
        $this->assertSame('F20', $events[0]->key);
    }

    // F21-F35 (57384-57398) are pinned row-by-row end-to-end in
    // KeySpecTableParityTest::testTheDecoderEndToEndAgreesWithItsOwnTable.

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

    // ─── Mouse 1015 (urxvt) / 1016 (SGR pixels) ──────────────────────────────
    //
    // The wave-4 audit claimed these were "collapsed or not distinguished" with
    // the 1006 SGR path. Re-deriving from the current decoder proved the truth:
    // 1006 (`ESC [ < …`) was already correct; the urxvt 1015 form (no '<') was
    // silently dropped, and 1016 is byte-identical to 1006 (a stateless decoder
    // cannot know the coordinates are pixels). These lock in each verdict.

    public function testUrxvtMouse1015Press(): void
    {
        // urxvt bias: left press sends the X10 code 0 + 32 = 32, final byte 'M'.
        $events = $this->decoder->decode("\x1b[32;100;200M");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(MouseEvent::class, $events[0]);
        $m = $events[0];
        $this->assertSame(100, $m->x);
        $this->assertSame(200, $m->y);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $m->button);
        $this->assertSame(MouseEvent::ACTION_PRESS, $m->action);
    }

    public function testUrxvtMouse1015Release(): void
    {
        // 1015 has no lowercase 'm' — release is the X10 low bits == 3 (3+32=35).
        $events = $this->decoder->decode("\x1b[35;7;9M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::ACTION_RELEASE, $m->action);
        $this->assertSame(7, $m->x);
        $this->assertSame(9, $m->y);
    }

    public function testUrxvtMouse1015UnbiasedButton(): void
    {
        // The audit brief's illustrative form carries the raw (unbiased) code 3.
        // Normalisation must still classify it as a release, not a wheel/garbage.
        $events = $this->decoder->decode("\x1b[3;100;200M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertInstanceOf(MouseEvent::class, $m);
        $this->assertSame(MouseEvent::ACTION_RELEASE, $m->action);
        $this->assertSame(100, $m->x);
        $this->assertSame(200, $m->y);
    }

    public function testUrxvtMouse1015Scroll(): void
    {
        // Wheel-up = X10 code 64 (+32 = 96) → must be a scroll, never a left click.
        $events = $this->decoder->decode("\x1b[96;10;5M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertTrue($m->isScroll());
    }

    public function testUrxvtMouse1015DoesNotCollideWithSgr1006(): void
    {
        // Feed the 1015 form and the 1006 form back-to-back: each must decode via
        // its own branch (absence vs presence of '<') to independent coordinates.
        $events = $this->decoder->decode("\x1b[32;100;200M\x1b[<1;10;20M");
        $this->assertCount(2, $events);
        [$a, $b] = $events;
        $this->assertInstanceOf(MouseEvent::class, $a);
        $this->assertInstanceOf(MouseEvent::class, $b);
        $this->assertSame(100, $a->x);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $a->button);
        $this->assertSame(10, $b->x);
        $this->assertSame(20, $b->y);
        $this->assertSame(MouseEvent::BUTTON_MIDDLE, $b->button);
        $this->assertSame(MouseEvent::ACTION_PRESS, $b->action);
    }

    public function testUrxvtMouse1015DisabledConsumesSilently(): void
    {
        // Mouse disabled → structurally consumed (byte-sync kept), no event, no
        // remainder, the surrounding key survives, and the sequence is tallied as a
        // silent drop (not a drained reply) via the exposed counter.
        $decoder = new EscapeDecoder(new EscapeDecoderOptions(enableMouse: false));
        $events = $decoder->decode("\x1b[32;10;5Mz");
        $this->assertCount(1, $events);
        $this->assertSame('z', $events[0]->key);
        $this->assertSame('', $decoder->remainder());
        $this->assertSame(1, $decoder->droppedUnknownCount());
        $this->assertSame(0, $decoder->drainedReplyCount());
    }

    public function testUrxvtMouse1015Drag(): void
    {
        // Drag: X10 motion code 32 (+32 = 64). The +32 de-bias lands exactly on the
        // motion bit, so getting 1015 wrong silently swaps every press for a drag —
        // this is the single most fragile normalization outcome and is pinned here.
        $events = $this->decoder->decode("\x1b[64;7;8M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::BUTTON_LEFT, $m->button);
        $this->assertSame(MouseEvent::ACTION_DRAG, $m->action);
        $this->assertSame(7, $m->x);
        $this->assertSame(8, $m->y);
    }

    public function testUrxvtMouse1015ReleaseWithShiftModifier(): void
    {
        // Release + Shift: X10 code 3 | 4 = 7, biased +32 = 39. Verifies the
        // modifier bits (bit2 shift) survive de-biasing and are not lost.
        $events = $this->decoder->decode("\x1b[39;11;12M");
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertSame(MouseEvent::ACTION_RELEASE, $m->action);
        $this->assertTrue($m->modifiers->includes(KeyModifier::SHIFT));
        $this->assertSame(11, $m->x);
        $this->assertSame(12, $m->y);
    }

    public function testUrxvtMouse1015SplitAcrossChunks(): void
    {
        // A 1015 report may straddle read boundaries — the partial must buffer, not
        // leak as keys, then complete once the tail arrives (CALIBER_LEARNINGS:10).
        $this->assertCount(0, $this->decoder->decode("\x1b[3"));
        $this->assertCount(0, $this->decoder->decode('2;10'));
        $this->assertNotSame('', $this->decoder->remainder());
        $events = $this->decoder->decode("0;200M");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(MouseEvent::class, $events[0]);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $events[0]->button);
        $this->assertSame(100, $events[0]->x);
    }

    public function testSgrPixels1016IsByteIdenticalToSgr1006(): void
    {
        // Mode 1016 reuses the exact 1006 wire ("same response format … in pixels").
        // With no DECSET state the two are indistinguishable: feeding the identical
        // bytes as a "1006 host" frame and a "1016 host" frame MUST yield field-for-
        // field equal MouseEvents, with the coordinate field reported verbatim (a
        // pixel value passes through unscaled). That equivalence is the actual claim
        // under test — not merely that one decode happens to succeed.
        $asSgr1006 = $this->decoder->decode("\x1b[<1;640;480M")[0];
        $this->decoder->reset();
        $asSgrPixels1016 = $this->decoder->decode("\x1b[<1;640;480M")[0];

        $this->assertInstanceOf(MouseEvent::class, $asSgr1006);
        $this->assertInstanceOf(MouseEvent::class, $asSgrPixels1016);
        $this->assertSame($asSgr1006->x, $asSgrPixels1016->x);
        $this->assertSame($asSgr1006->y, $asSgrPixels1016->y);
        $this->assertSame($asSgr1006->button, $asSgrPixels1016->button);
        $this->assertSame($asSgr1006->action, $asSgrPixels1016->action);
        $this->assertSame($asSgr1006->modifiers->value(), $asSgrPixels1016->modifiers->value());
        // Coordinate field is reported verbatim (pixels not scaled to cells).
        $this->assertSame(640, $asSgrPixels1016->x);
        $this->assertSame(480, $asSgrPixels1016->y);
        $this->assertSame(MouseEvent::BUTTON_MIDDLE, $asSgrPixels1016->button);
    }

    // ─── modifyOtherKeys (xterm extended keys) ───────────────────────────────

    public function testModifyOtherKeysCtrlLetter(): void
    {
        // Ctrl+'a': the 27; prefix + mods=5 (1+CTRL) + keysym 97 ('a').
        $events = $this->decoder->decode("\x1b[27;5;97~");
        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyEvent::class, $events[0]);
        $this->assertSame('a', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::CTRL));
        $this->assertSame("\x1b[27;5;97~", $events[0]->raw);
    }

    public function testModifyOtherKeysShiftTab(): void
    {
        // Shift+Tab reported as modifyOtherKeys keysym 9 (Tab) with mods=2 (1+SHIFT).
        $events = $this->decoder->decode("\x1b[27;2;9~");
        $this->assertCount(1, $events);
        $this->assertSame('Tab', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
    }

    public function testModifyOtherKeysPunctuationNotMisNamedAsFunctionKey(): void
    {
        // Regression for the keysym-vs-kitty-codepoint conflation: X11 keysym 33 is
        // ASCII '!' and 34 is '"'. The Kitty legacy 11..34 F-key table used to shadow
        // these into 'F19'/'F20'. Printable keysyms must report the literal glyph.
        $bang = $this->decoder->decode("\x1b[27;1;33~")[0];
        $this->assertSame('!', $bang->key);
        $this->assertSame(0, $bang->modifiers->value());

        $quote = $this->decoder->decode("\x1b[27;1;34~")[0];
        $this->assertSame('"', $quote->key);

        // Ctrl+'!' — the glyph survives, the modifier is orthogonal to it.
        $ctrlBang = $this->decoder->decode("\x1b[27;5;33~")[0];
        $this->assertSame('!', $ctrlBang->key);
        $this->assertTrue($ctrlBang->modifiers->includes(KeyModifier::CTRL));
    }

    public function testModifyOtherKeysSpaceIsLiteralSpace(): void
    {
        // keysym 32 must match the plain-key path (a bare ' ' char), not 'Space'.
        $events = $this->decoder->decode("\x1b[27;1;32~")[0];
        $this->assertSame(' ', $events->key);
        $plain = $this->decoder->decode(' ')[0];
        $this->assertSame($plain->key, $events->key);
    }

    public function testModifyOtherKeysPreservesLetterCase(): void
    {
        // The keysym field carries the exact character; case must not be forced to
        // lower (Kitty's table maps both 65 and 97 to 'a'). keysym 65 -> 'A'.
        $shiftA = $this->decoder->decode("\x1b[27;2;65~")[0];
        $this->assertSame('A', $shiftA->key);
        $this->assertTrue($shiftA->modifiers->includes(KeyModifier::SHIFT));

        $plainA = $this->decoder->decode("\x1b[27;1;97~")[0];
        $this->assertSame('a', $plainA->key);
    }

    public function testModifyOtherKeysZeroModsIsMalformed(): void
    {
        // The wire modifier field is 1 + bitmask; 0 would make fromXtermParam(0)
        // compute (0-1) = -1 and fabricate every modifier. It must be dropped, not
        // emitted with phantom SHIFT|ALT|CTRL|META, and the next key must survive.
        $events = $this->decoder->decode("\x1b[27;0;97~k");
        $this->assertCount(1, $events);
        $this->assertSame('k', $events[0]->key);
    }

    public function testModifyOtherKeysAltDigit(): void
    {
        // Alt+'6': printable keysym outside the named table must decode to the
        // character, not be dropped — mods=3 (1+ALT), keysym 54 ('6').
        $events = $this->decoder->decode("\x1b[27;3;54~");
        $this->assertCount(1, $events);
        $this->assertSame('6', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::ALT));
    }

    public function testModifyOtherKeysPlainUnderLevelTwo(): void
    {
        // modifyOtherKeys=2 also wraps UNmodified keys (mods=1 => no modifier);
        // it must reproduce the plain keystroke so typing does not break.
        $events = $this->decoder->decode("\x1b[27;1;97~");
        $this->assertCount(1, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame(0, $events[0]->modifiers->value());
    }

    public function testModifyOtherKeysDistinguishedFromPlainKey(): void
    {
        // The point of modifyOtherKeys: Ctrl+'a' (this frame) must be told apart
        // from a bare 'a' keypress — a phantom collision is the bug being locked.
        $ctrlA = $this->decoder->decode("\x1b[27;5;97~")[0];
        $plainA = $this->decoder->decode('a')[0];
        $this->assertSame($ctrlA->key, $plainA->key);
        $this->assertTrue($ctrlA->modifiers->includes(KeyModifier::CTRL));
        $this->assertFalse($plainA->modifiers->includes(KeyModifier::CTRL));
    }

    public function testModifyOtherKeysUnknownKeysymConsumed(): void
    {
        // A 27;-framed keysym we neither name nor print (e.g. 0xFFFF) is drained,
        // never leaked, and the following key still decodes.
        $events = $this->decoder->decode("\x1b[27;1;65535~k");
        $this->assertCount(1, $events);
        $this->assertSame('k', $events[0]->key);
    }

    // ─── Application keypad (DECKPAM SS3 table) ──────────────────────────────

    public function testKeypadApplicationDigits(): void
    {
        // ansicode.txt:744-753 — Op..Oy are keypad 0..9 in application mode.
        $expect = [
            'p' => 'KP0', 'q' => 'KP1', 'r' => 'KP2', 's' => 'KP3', 't' => 'KP4',
            'u' => 'KP5', 'v' => 'KP6', 'w' => 'KP7', 'x' => 'KP8', 'y' => 'KP9',
        ];
        foreach ($expect as $final => $name) {
            $events = $this->decoder->decode("\x1bO" . $final);
            $this->assertCount(1, $events, "SS3 O{$final} should yield one event");
            $this->assertSame($name, $events[0]->key);
            $this->assertSame(0, $events[0]->modifiers->value());
        }
    }

    public function testKeypadDecimalAndMinus(): void
    {
        // ansicode.txt:742-743 — Ol is the decimal point, Om is minus.
        $dec = $this->decoder->decode("\x1bOl");
        $this->assertSame('KPDecimal', $dec[0]->key);
        $minus = $this->decoder->decode("\x1bOm");
        $this->assertSame('KPSubtract', $minus[0]->key);
    }

    public function testKeypadEnterStillPlainEnter(): void
    {
        // ESC O M already decoded to 'Enter' before this wave — locking it so the
        // keypad additions cannot change that established name.
        $events = $this->decoder->decode("\x1bOM");
        $this->assertSame('Enter', $events[0]->key);
    }

    // ─── Already-handled items from the stale wave-4 list (lock-in) ───────────

    public function testBacktabLockedIn(): void
    {
        // ESC [ Z was reported missing but is already handled (EscapeDecoder CSI Z
        // branch) — this regression test locks it in.
        $events = $this->decoder->decode("\x1b[Z");
        $this->assertCount(1, $events);
        $this->assertSame('Backtab', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(KeyModifier::SHIFT));
        $this->assertSame("\x1b[Z", $events[0]->raw);
    }

    public function testBacktabFollowedByBytesLockedIn(): void
    {
        // Backtab then ordinary keys in one chunk must partition at the final byte.
        $events = $this->decoder->decode("\x1b[Zxy");
        $this->assertCount(3, $events);
        $this->assertSame('Backtab', $events[0]->key);
        $this->assertSame('x', $events[1]->key);
        $this->assertSame('y', $events[2]->key);
    }

    public function testX10MouseLockedIn(): void
    {
        // X10 (mode 1000) `ESC [ M <b> <x> <y>` was reported leaking as printable
        // keys but is already handled — locking the 3-byte payload decode.
        $events = $this->decoder->decode("\x1b[M" . chr(32) . chr(35) . chr(37));
        $this->assertCount(1, $events);
        $m = $events[0];
        $this->assertInstanceOf(MouseEvent::class, $m);
        $this->assertSame(MouseEvent::BUTTON_LEFT, $m->button);
        $this->assertSame(MouseEvent::ACTION_PRESS, $m->action);
        $this->assertSame(3, $m->x);
        $this->assertSame(5, $m->y);
    }

    public function testX10MouseReleaseLockedIn(): void
    {
        // X10 release: button low bits == 3 (code 35 = 3 + 32 bias).
        $events = $this->decoder->decode("\x1b[M" . chr(35) . chr(43) . chr(44));
        $this->assertCount(1, $events);
        $this->assertSame(MouseEvent::ACTION_RELEASE, $events[0]->action);
    }
}
