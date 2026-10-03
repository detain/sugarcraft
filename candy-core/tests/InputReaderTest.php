<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use SugarCraft\Core\InputReader;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\ModeState;
use SugarCraft\Core\Modifiers;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Msg\BlurMsg;
use SugarCraft\Core\Msg\ClipboardMsg;
use SugarCraft\Core\Msg\CursorColorMsg;
use SugarCraft\Core\Msg\CursorPositionMsg;
use SugarCraft\Core\Msg\FocusGainedMsg;
use SugarCraft\Core\Msg\ForegroundColorMsg;
use SugarCraft\Core\Msg\KeyboardEnhancementsMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyPressMsg;
use SugarCraft\Core\Msg\KeyReleaseMsg;
use SugarCraft\Core\Msg\KeyRepeatMsg;
use SugarCraft\Core\Msg\PasteEndMsg;
use SugarCraft\Core\Msg\PasteStartMsg;
use SugarCraft\Core\Msg\ModeReportMsg;
use SugarCraft\Core\Msg\TerminalVersionMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\PasteMsg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InputReaderTest extends TestCase
{
    public function testPrintableAscii(): void
    {
        $r = new InputReader();
        $msgs = $r->parse('abc');
        $this->assertCount(3, $msgs);
        foreach (['a', 'b', 'c'] as $i => $rune) {
            $this->assertInstanceOf(KeyMsg::class, $msgs[$i]);
            $this->assertSame(KeyType::Char, $msgs[$i]->type);
            $this->assertSame($rune, $msgs[$i]->rune);
            $this->assertFalse($msgs[$i]->ctrl);
            $this->assertFalse($msgs[$i]->alt);
        }
    }

    public function testCtrlC(): void
    {
        $msgs = (new InputReader())->parse("\x03");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Char, $msgs[0]->type);
        $this->assertSame('c', $msgs[0]->rune);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertSame('ctrl+c', $msgs[0]->string());
    }

    public function testTabEnterBackspaceSpace(): void
    {
        $msgs = (new InputReader())->parse("\t\r\x7f ");
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertSame(KeyType::Enter, $msgs[1]->type);
        $this->assertSame(KeyType::Backspace, $msgs[2]->type);
        $this->assertSame(KeyType::Space, $msgs[3]->type);
    }

    public function testArrowKeys(): void
    {
        $msgs = (new InputReader())->parse("\x1b[A\x1b[B\x1b[C\x1b[D");
        $this->assertCount(4, $msgs);
        $this->assertSame(KeyType::Up, $msgs[0]->type);
        $this->assertSame(KeyType::Down, $msgs[1]->type);
        $this->assertSame(KeyType::Right, $msgs[2]->type);
        $this->assertSame(KeyType::Left, $msgs[3]->type);
    }

    public function testHomeEndDeletePageKeys(): void
    {
        $msgs = (new InputReader())->parse("\x1b[H\x1b[F\x1b[3~\x1b[5~\x1b[6~");
        $this->assertSame(KeyType::Home, $msgs[0]->type);
        $this->assertSame(KeyType::End, $msgs[1]->type);
        $this->assertSame(KeyType::Delete, $msgs[2]->type);
        $this->assertSame(KeyType::PageUp, $msgs[3]->type);
        $this->assertSame(KeyType::PageDown, $msgs[4]->type);
    }

    public function testAltPrefixedKey(): void
    {
        $msgs = (new InputReader())->parse("\x1ba");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Char, $msgs[0]->type);
        $this->assertSame('a', $msgs[0]->rune);
        $this->assertTrue($msgs[0]->alt);
        $this->assertFalse($msgs[0]->ctrl);
        $this->assertSame('alt+a', $msgs[0]->string());
    }

    public function testAltBackspaceDecodesAsOneAltFlaggedBackspaceNotEscapePlusBackspace(): void
    {
        // ESC + DEL (0x7f) is how most terminals send Alt+Backspace.
        $msgs = (new InputReader())->parse("\x1b\x7f");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Backspace, $msgs[0]->type);
        $this->assertTrue($msgs[0]->alt);
    }

    public function testAltEnterDecodesAsOneAltFlaggedEnter(): void
    {
        $msgs = (new InputReader())->parse("\x1b\r");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Enter, $msgs[0]->type);
        $this->assertTrue($msgs[0]->alt);
    }

    public function testAltTabDecodesAsOneAltFlaggedTab(): void
    {
        $msgs = (new InputReader())->parse("\x1b\t");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertTrue($msgs[0]->alt);
    }

    public function testBareEscapeIsBufferedThenFlushed(): void
    {
        $r = new InputReader();
        // Single ESC alone is ambiguous (could be the start of a sequence),
        // so it's buffered.
        $this->assertSame([], $r->parse("\x1b"));

        $flushed = $r->flushPending();
        $this->assertInstanceOf(KeyMsg::class, $flushed);
        $this->assertSame(KeyType::Escape, $flushed->type);
    }

    public function testHasPendingEscape(): void
    {
        $r = new InputReader();
        $this->assertFalse($r->hasPendingEscape());
        $r->parse("\x1b");
        $this->assertTrue($r->hasPendingEscape());
        $r->flushPending();
        $this->assertFalse($r->hasPendingEscape());
    }

    public function testSplitCsiAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b"));
        $this->assertSame([], $r->parse('['));
        $msgs = $r->parse('A');
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Up, $msgs[0]->type);
    }

    public function testMixedStream(): void
    {
        $r = new InputReader();
        $msgs = $r->parse("hi\x1b[Aq");
        $this->assertCount(4, $msgs);
        $this->assertSame('h', $msgs[0]->rune);
        $this->assertSame('i', $msgs[1]->rune);
        $this->assertSame(KeyType::Up, $msgs[2]->type);
        $this->assertSame('q', $msgs[3]->rune);
    }

    public function testKeyMsgString(): void
    {
        $this->assertSame('a', (new KeyMsg(KeyType::Char, 'a'))->string());
        $this->assertSame('ctrl+a', (new KeyMsg(KeyType::Char, 'a', ctrl: true))->string());
        $this->assertSame('alt+a', (new KeyMsg(KeyType::Char, 'a', alt: true))->string());
        $this->assertSame('up', (new KeyMsg(KeyType::Up))->string());
        $this->assertSame('ctrl+alt+a', (new KeyMsg(KeyType::Char, 'a', alt: true, ctrl: true))->string());
    }

    // ---- focus ------------------------------------------------------------

    public function testFocusIn(): void
    {
        $msgs = (new InputReader())->parse("\x1b[I");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(FocusGainedMsg::class, $msgs[0]);
    }

    public function testFocusOut(): void
    {
        $msgs = (new InputReader())->parse("\x1b[O");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(BlurMsg::class, $msgs[0]);
    }

    // ---- modified Tab (CSI ... I) -----------------------------------------

    public function testCtrlTab(): void
    {
        $msgs = (new InputReader())->parse("\x1b[1;5I");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertFalse($msgs[0]->shift);
        $this->assertFalse($msgs[0]->alt);
    }

    public function testCtrlShiftTab(): void
    {
        $msgs = (new InputReader())->parse("\x1b[1;6I");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertTrue($msgs[0]->shift);
    }

    /** Modified Tab must not cannibalise the parameterless focus-in report. */
    public function testFocusInStillDecodedAfterModifiedTabSupport(): void
    {
        $msgs = (new InputReader())->parse("\x1b[I\x1b[1;5I");
        $this->assertCount(2, $msgs);
        $this->assertInstanceOf(FocusGainedMsg::class, $msgs[0]);
        $this->assertInstanceOf(KeyMsg::class, $msgs[1]);
        $this->assertSame(KeyType::Tab, $msgs[1]->type);
    }

    public function testModifiedTabSplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b[1;"));
        $msgs = $r->parse('6I');
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertTrue($msgs[0]->shift);
    }

    // ---- backtab (CSI Z) --------------------------------------------------

    /**
     * `CSI Z` is Shift+Tab, and it is the one modified Tab that carries its
     * modifier in the FINAL BYTE rather than in a `;<mod>` parameter.
     *
     * That is why it needs an arm of its own next to `'I'`: the generic
     * modifier strip above the key table only ever looks at the parameters, so
     * for backtab it finds nothing and `$mods` stays null. Before the arm
     * existed the sequence fell through to `default => null` and the reader
     * DROPPED it, which meant no candy-core application could bind Shift+Tab
     * at all — a handler could be written and would simply never fire.
     */
    public function testBacktabDecodesToAShiftedTab(): void
    {
        $msgs = (new InputReader())->parse("\x1b[Z");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertTrue($msgs[0]->shift, 'backtab decoded without the shift modifier');
        $this->assertFalse($msgs[0]->ctrl);
        $this->assertFalse($msgs[0]->alt);
        // The label a string-keyed binding table matches on.
        $this->assertSame('shift+tab', $msgs[0]->string());
    }

    /**
     * A terminal that spells backtab in the parameterised `CSI 1;2Z` form
     * lands on the same message.
     *
     * Here `$mods` IS non-null, so the post-match rebuild re-derives shift
     * from the parameter and overwrites the arm's own flag with an identical
     * one. Worth pinning separately because the two forms reach the same
     * result down different paths.
     */
    public function testParameterisedBacktabDecodesToTheSameShiftedTab(): void
    {
        $msgs = (new InputReader())->parse("\x1b[1;2Z");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertTrue($msgs[0]->shift);
        $this->assertFalse($msgs[0]->ctrl);
    }

    /**
     * The whole SHIFT-BIT-CLEAR family of parameterised backtabs, one case per
     * xterm modifier whose shift bit is 0.
     *
     * `Z` as a final byte IS the shift; a `;<mod>` parameter is a SECOND,
     * independent modifier channel. The rebuild below the key table used to
     * REPLACE the arm's KeyMsg from $mods alone, which silently discarded the
     * final byte's meaning for every modifier that does not itself encode
     * shift — `1;3Z` (alt), `1;5Z` (ctrl) and `1;7Z` (ctrl+alt) all decoded as
     * an UNSHIFTED tab. It now ORs, so the two channels are additive.
     *
     * Three cases, not one. The backlog entry that recorded this named only
     * `CSI 1;5Z`, and a fix scoped to that literal would have left the other
     * two broken — which is why this is a data provider over the modifier
     * arithmetic rather than a single assertion.
     *
     * @return iterable<string, array{0:string, 1:bool, 2:bool}>
     */
    public static function shiftBitClearBacktabProvider(): iterable
    {
        // xterm modifier = 1 + bitmask(shift=1, alt=2, ctrl=4). The odd values
        // are exactly the ones with the shift bit clear.
        yield 'mod 3 = alt'      => ['1;3Z', false, true];
        yield 'mod 5 = ctrl'     => ['1;5Z', true,  false];
        yield 'mod 7 = ctrl+alt' => ['1;7Z', true,  true];
    }

    #[DataProvider('shiftBitClearBacktabProvider')]
    public function testAShiftBitClearParameterisedBacktabKeepsTheShiftItsFinalByteEncodes(
        string $params,
        bool $ctrl,
        bool $alt,
    ): void {
        $msgs = (new InputReader())->parse("\x1b[" . $params);
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertTrue(
            $msgs[0]->shift,
            "ESC[{$params} dropped the shift its Z final byte encodes",
        );
        $this->assertSame($ctrl, $msgs[0]->ctrl, "ESC[{$params} ctrl");
        $this->assertSame($alt, $msgs[0]->alt, "ESC[{$params} alt");
    }

    /**
     * The merge must not INVENT a modifier. `'Z'` is the only arm in the key
     * table that sets a flag of its own, so for every other final byte the
     * rebuild's OR has to come out identical to the assignment it replaced.
     *
     * Without this the merge could be "fixed" by ORing in a constant true and
     * the family test above would still pass while every ctrl+arrow gained a
     * phantom shift.
     *
     * @return iterable<string, array{0:string, 1:string}>
     */
    public static function unshiftedModifiedKeyProvider(): iterable
    {
        yield 'ctrl+Up'    => ['1;5A', 'ctrl+up'];
        yield 'ctrl+Down'  => ['1;5B', 'ctrl+down'];
        yield 'alt+Right'  => ['1;3C', 'alt+right'];
        yield 'ctrl+Home'  => ['1;5H', 'ctrl+home'];
        yield 'ctrl+Tab'   => ['1;5I', 'ctrl+tab'];
        yield 'ctrl+F5'    => ['15;5~', 'ctrl+f5'];
    }

    #[DataProvider('unshiftedModifiedKeyProvider')]
    public function testTheModifierMergeDoesNotInventShiftOnKeysThatDoNotEncodeIt(
        string $params,
        string $label,
    ): void {
        $msgs = (new InputReader())->parse("\x1b[" . $params);
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertFalse($msgs[0]->shift, "ESC[{$params} gained a phantom shift");
        $this->assertSame($label, $msgs[0]->string());
    }

    /**
     * `CSI 1;5I` and `CSI 1;5Z` differ in exactly one bit of meaning, and the
     * difference lives in the final byte rather than the parameter.
     *
     * This is the pair that makes the merge load-bearing: the SAME modifier
     * parameter must produce a shifted tab for one final byte and an unshifted
     * one for the other. A rebuild that reads only the parameter cannot tell
     * them apart, and before the fix it did not — both came back `ctrl+tab`.
     */
    public function testTheSameModifierParameterDivergesOnTheFinalByte(): void
    {
        $forward = (new InputReader())->parse("\x1b[1;5I");
        $back    = (new InputReader())->parse("\x1b[1;5Z");
        $this->assertCount(1, $forward);
        $this->assertCount(1, $back);
        $this->assertSame('ctrl+tab', $forward[0]->string());
        $this->assertSame('ctrl+shift+tab', $back[0]->string());
        $this->assertNotSame(
            $forward[0]->string(),
            $back[0]->string(),
            'ESC[1;5I and ESC[1;5Z collapsed to one message',
        );
    }

    /** Backtab split across two reads must still emit exactly one KeyMsg. */
    public function testBacktabSplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b["));
        $msgs = $r->parse('Z');
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertTrue($msgs[0]->shift);
    }

    /**
     * Backtab must not disturb the plain Tab byte sitting next to it.
     *
     * 0x09 has no room for a modifier, so it is the unshifted Tab and stays
     * that way; a mix-up here would turn every forward Tab into a backward
     * one in any application that cycles focus.
     */
    public function testPlainTabByteStaysUnshiftedAlongsideBacktab(): void
    {
        $msgs = (new InputReader())->parse("\x09\x1b[Z");
        $this->assertCount(2, $msgs);
        $this->assertSame(KeyType::Tab, $msgs[0]->type);
        $this->assertFalse($msgs[0]->shift, 'a plain Tab came back shifted');
        $this->assertSame(KeyType::Tab, $msgs[1]->type);
        $this->assertTrue($msgs[1]->shift);
    }

    // ---- mouse (SGR encoded) ---------------------------------------------

    public function testMouseLeftPress(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<0;5;10M");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(MouseMsg::class, $msgs[0]);
        $this->assertSame(5, $msgs[0]->x);
        $this->assertSame(10, $msgs[0]->y);
        $this->assertSame(MouseButton::Left, $msgs[0]->button);
        $this->assertSame(MouseAction::Press, $msgs[0]->action);
        $this->assertFalse($msgs[0]->shift);
        $this->assertFalse($msgs[0]->alt);
        $this->assertFalse($msgs[0]->ctrl);
    }

    public function testMouseLeftRelease(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<0;5;10m");
        $this->assertSame(MouseAction::Release, $msgs[0]->action);
        $this->assertSame(MouseButton::Left, $msgs[0]->button);
    }

    public function testMouseRightPress(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<2;1;1M");
        $this->assertSame(MouseButton::Right, $msgs[0]->button);
    }

    public function testMouseModifiers(): void
    {
        // Button 0 (left) + shift(4) + alt(8) + ctrl(16) = 28
        $msgs = (new InputReader())->parse("\x1b[<28;3;4M");
        $this->assertTrue($msgs[0]->shift);
        $this->assertTrue($msgs[0]->alt);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertSame(MouseButton::Left, $msgs[0]->button);
    }

    public function testMouseMotionWithButton(): void
    {
        // Left + motion(32) = 32
        $msgs = (new InputReader())->parse("\x1b[<32;7;8M");
        $this->assertSame(MouseAction::Motion, $msgs[0]->action);
        $this->assertSame(MouseButton::Left, $msgs[0]->button);
    }

    public function testMouseWheelUp(): void
    {
        // 64 = wheel up
        $msgs = (new InputReader())->parse("\x1b[<64;1;1M");
        $this->assertSame(MouseButton::WheelUp, $msgs[0]->button);
        $this->assertSame(MouseAction::Press, $msgs[0]->action);
    }

    public function testMouseWheelDown(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<65;1;1M");
        $this->assertSame(MouseButton::WheelDown, $msgs[0]->button);
    }

    public function testMouseExtraBackward(): void
    {
        // 128 = extra btn 0 (backward)
        $msgs = (new InputReader())->parse("\x1b[<128;1;1M");
        $this->assertSame(MouseButton::Backward, $msgs[0]->button);
        $this->assertSame(MouseAction::Press, $msgs[0]->action);
    }

    public function testMouseSplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b[<0;5"));
        $msgs = $r->parse(";10M");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(MouseMsg::class, $msgs[0]);
        $this->assertSame(5, $msgs[0]->x);
        $this->assertSame(10, $msgs[0]->y);
    }

    // ---- mouse subclass dispatch (Bubble Tea v2 parity) ------------------

    public function testMousePressEmitsClickMsg(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<0;1;1M");
        $this->assertInstanceOf(MouseClickMsg::class, $msgs[0]);
    }

    public function testMouseReleaseEmitsReleaseMsg(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<0;1;1m");
        $this->assertInstanceOf(MouseReleaseMsg::class, $msgs[0]);
    }

    public function testMouseMotionEmitsMotionMsg(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<32;7;8M");
        $this->assertInstanceOf(MouseMotionMsg::class, $msgs[0]);
    }

    public function testMouseWheelEmitsWheelMsg(): void
    {
        $msgs = (new InputReader())->parse("\x1b[<64;1;1M");
        $this->assertInstanceOf(MouseWheelMsg::class, $msgs[0]);
    }

    // ---- function keys ----------------------------------------------------

    public function testFunctionKeysViaSs3(): void
    {
        $msgs = (new InputReader())->parse("\x1bOP\x1bOQ\x1bOR\x1bOS");
        $this->assertCount(4, $msgs);
        $this->assertSame(KeyType::F1, $msgs[0]->type);
        $this->assertSame(KeyType::F2, $msgs[1]->type);
        $this->assertSame(KeyType::F3, $msgs[2]->type);
        $this->assertSame(KeyType::F4, $msgs[3]->type);
    }

    public function testFunctionKeysViaCsiTilde(): void
    {
        $msgs = (new InputReader())->parse(
            "\x1b[15~\x1b[17~\x1b[18~\x1b[19~\x1b[20~\x1b[21~\x1b[23~\x1b[24~",
        );
        $expected = [
            KeyType::F5, KeyType::F6, KeyType::F7, KeyType::F8,
            KeyType::F9, KeyType::F10, KeyType::F11, KeyType::F12,
        ];
        $this->assertCount(count($expected), $msgs);
        foreach ($expected as $i => $type) {
            $this->assertSame($type, $msgs[$i]->type, "F-key #$i");
        }
    }

    public function testF1ThroughF4ViaCsiTildeAlsoWork(): void
    {
        // Some terminals send "ESC[11~" instead of "ESC OP".
        $msgs = (new InputReader())->parse("\x1b[11~\x1b[12~\x1b[13~\x1b[14~");
        $this->assertSame(KeyType::F1, $msgs[0]->type);
        $this->assertSame(KeyType::F4, $msgs[3]->type);
    }

    public function testSs3SplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1bO"));
        $msgs = $r->parse('P');
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::F1, $msgs[0]->type);
    }

    // ---- bracketed paste -------------------------------------------------

    public function testBracketedPasteSingleFrame(): void
    {
        $msgs = (new InputReader())->parse("\x1b[200~hello world\x1b[201~");
        $this->assertCount(3, $msgs);
        $this->assertInstanceOf(PasteStartMsg::class, $msgs[0]);
        $this->assertInstanceOf(PasteEndMsg::class, $msgs[1]);
        $this->assertInstanceOf(PasteMsg::class, $msgs[2]);
        $this->assertSame('hello world', $msgs[2]->content);
    }

    public function testBracketedPastePreservesNewlinesAndControl(): void
    {
        // Pasted content with embedded newline + CSI sequence should NOT
        // be parsed as keys — the whole envelope is one PasteMsg. Asserted
        // against the raw opt-out reader so the CSI survives verbatim (the
        // default-on sanitizer would strip it — see the sanitize tests below).
        $payload = "line1\nline2\x1b[31mred\x1b[0m";
        $msgs    = (new InputReader(sanitizePaste: false))->parse("\x1b[200~" . $payload . "\x1b[201~");
        $this->assertCount(3, $msgs);
        $this->assertInstanceOf(PasteStartMsg::class, $msgs[0]);
        $this->assertInstanceOf(PasteEndMsg::class, $msgs[1]);
        $this->assertInstanceOf(PasteMsg::class, $msgs[2]);
        $this->assertSame($payload, $msgs[2]->content);
    }

    public function testBracketedPasteSanitizesOsc52ByDefault(): void
    {
        // Default-on (default-secure): an OSC-52 clipboard-write escape
        // smuggled inside a paste must be neutralized before it reaches the
        // model as a PasteMsg. Reverting the default to raw leaves the escape
        // in $content and fails these assertions.
        $osc52   = "\x1b]52;c;ZWxpdGU=\x07";
        $payload = 'before' . $osc52 . 'after';
        $msgs    = (new InputReader())->parse("\x1b[200~" . $payload . "\x1b[201~");
        $this->assertCount(3, $msgs);
        $paste = $msgs[2];
        $this->assertInstanceOf(PasteMsg::class, $paste);
        $this->assertStringNotContainsString("\x1b", $paste->content);
        $this->assertStringNotContainsString('52;', $paste->content);
        $this->assertSame('beforeafter', $paste->content);
    }

    public function testBracketedPasteKeepsTextTabsNewlinesUnderSanitize(): void
    {
        // Sanitize strips escapes/control bytes but keeps printable text,
        // tabs, and newlines so ordinary multi-line pastes stay intact.
        $msgs  = (new InputReader())->parse("\x1b[200~a\tb\nc\x1b[201~");
        $paste = $msgs[2];
        $this->assertInstanceOf(PasteMsg::class, $paste);
        $this->assertSame("a\tb\nc", $paste->content);
    }

    public function testBracketedPasteStripsZoneSentinelsKeepsImageMarkers(): void
    {
        // A pasted candy-mouse zone sentinel (U+E000 open / U+E001 close) is
        // well-formed UTF-8 text, so the plain ANSI sweep lets it through —
        // yet once echoed into a zone-marked frame it forges click/frame
        // markup. untrustedForMarkedFrames() surgically drops exactly those
        // two codepoints; the rest of the reserved arena (an image marker at
        // U+E002 here) must survive untouched.
        $open   = "\xEE\x80\x80"; // U+E000 zone sentinel
        $close  = "\xEE\x80\x81"; // U+E001 zone sentinel
        $marker = "\xEE\x80\x82"; // U+E002 image marker (Sanitize::IMAGE_MARKER shape)
        $msgs   = (new InputReader())->parse("\x1b[200~a{$open}b{$close}c{$marker}\x1b[201~");
        $paste  = $msgs[2];
        $this->assertInstanceOf(PasteMsg::class, $paste);
        $this->assertSame("abc{$marker}", $paste->content);
        // Raw opt-out keeps the smuggled sentinels verbatim for callers that
        // sanitize themselves.
        $rawMsgs = (new InputReader(sanitizePaste: false))->parse("\x1b[200~a{$open}b{$close}c{$marker}\x1b[201~");
        $raw     = $rawMsgs[2];
        $this->assertInstanceOf(PasteMsg::class, $raw);
        $this->assertSame("a{$open}b{$close}c{$marker}", $raw->content);
    }

    public function testBracketedPasteRawUnderOptOut(): void
    {
        // The opt-out flag preserves the raw bytes verbatim for callers that
        // deliberately need them (and sanitize themselves).
        $osc52   = "\x1b]52;c;ZWxpdGU=\x07";
        $payload = 'before' . $osc52 . 'after';
        $msgs    = (new InputReader(sanitizePaste: false))->parse("\x1b[200~" . $payload . "\x1b[201~");
        $paste   = $msgs[2];
        $this->assertInstanceOf(PasteMsg::class, $paste);
        $this->assertSame($payload, $paste->content);
    }

    public function testBracketedPasteSplitAcrossReads(): void
    {
        $r = new InputReader();
        // First parse sees the start marker → emits PasteStartMsg.
        $startMsgs = $r->parse("\x1b[200~hel");
        $this->assertCount(1, $startMsgs);
        $this->assertInstanceOf(PasteStartMsg::class, $startMsgs[0]);
        $this->assertSame([], $r->parse('lo'));
        $msgs = $r->parse(" world\x1b[201~");
        $this->assertCount(2, $msgs);
        $this->assertInstanceOf(PasteEndMsg::class, $msgs[0]);
        $this->assertInstanceOf(PasteMsg::class, $msgs[1]);
        $this->assertSame('hello world', $msgs[1]->content);
    }

    /**
     * The END marker straddling a read boundary must still close the paste.
     * Previously the whole unmatched tail — marker head included — moved
     * into the paste buffer, so the next read could never see `ESC[201~`
     * whole: the paste never closed and the keyboard went dead (crush_libs.md
     * candy-core #5, the realistic way that envelope "never closes").
     */
    public function testBracketedPasteEndMarkerSplitAtEveryByte(): void
    {
        $end = "\x1b[201~";
        for ($k = 1; $k < strlen($end); $k++) {
            $r = new InputReader();
            $first = $r->parse("\x1b[200~hello" . substr($end, 0, $k));
            $this->assertCount(1, $first, "split at {$k}");
            $this->assertInstanceOf(PasteStartMsg::class, $first[0]);

            $msgs = $r->parse(substr($end, $k) . 'q');
            $this->assertCount(3, $msgs, "split at {$k}");
            $this->assertInstanceOf(PasteEndMsg::class, $msgs[0]);
            $this->assertInstanceOf(PasteMsg::class, $msgs[1]);
            $this->assertSame('hello', $msgs[1]->content, "split at {$k}");
            $this->assertInstanceOf(KeyMsg::class, $msgs[2]);
            $this->assertSame('q', $msgs[2]->rune);
        }
    }

    public function testHeldBackMarkerLookalikeStaysPasteContent(): void
    {
        $r = new InputReader(sanitizePaste: false);
        $r->parse("\x1b[200~a\x1b[2");
        $msgs = $r->parse("x\x1b[201~");
        $this->assertInstanceOf(PasteMsg::class, $msgs[1]);
        $this->assertSame("a\x1b[2x", $msgs[1]->content);
    }

    public function testEscBufferedInsideAPasteIsNeverPromotedToAnEscapeKey(): void
    {
        $r = new InputReader();
        $r->parse("\x1b[200~ab\x1b");
        // Program polls these to turn a lone ESC into KeyType::Escape after
        // 50 ms — inside a paste that would inject a key AND eat the marker.
        $this->assertFalse($r->hasPendingEscape());
        $this->assertNull($r->flushPending());

        $msgs = $r->parse('[201~');
        $this->assertCount(2, $msgs);
        $this->assertSame('ab', $msgs[1]->content);
    }

    /**
     * crush_libs.md candy-core #5: a `CSI 200~` with no `CSI 201~` grew the
     * paste buffer without bound. Past MAX_PASTE_BYTES the bytes are surfaced
     * and collection continues — nothing is lost and nothing pasted is
     * re-read as keystrokes.
     */
    public function testUnclosedPasteSurfacesChunksInsteadOfGrowingForever(): void
    {
        $r = new InputReader();
        $r->parse("\x1b[200~");
        $max = InputReader::MAX_PASTE_BYTES;
        $bufProp = new \ReflectionProperty(InputReader::class, 'pasteBuf');

        $surfaced = '';
        for ($n = 0; $n < 3; $n++) {
            $msgs = $r->parse(str_repeat('a', $max + 10));
            $this->assertCount(1, $msgs);
            $this->assertInstanceOf(PasteMsg::class, $msgs[0]);
            $surfaced .= $msgs[0]->content;
            $this->assertLessThan($max, strlen($bufProp->getValue($r)), 'paste buffer must stay bounded');
        }
        $this->assertSame(3 * ($max + 10), strlen($surfaced));

        // Still inside the envelope: the close marker ends it normally.
        $msgs = $r->parse("tail\x1b[201~q");
        $this->assertInstanceOf(PasteEndMsg::class, $msgs[0]);
        $this->assertSame('tail', $msgs[1]->content);
        $this->assertSame('q', $msgs[2]->rune);
    }

    public function testOversizePasteChunkNeverSplitsAUtf8Character(): void
    {
        $r = new InputReader();
        $r->parse("\x1b[200~");
        $max = InputReader::MAX_PASTE_BYTES;

        // Byte MAX is the lead of a 2-byte `é` whose continuation is in the next read.
        $msgs = $r->parse(str_repeat('a', $max - 1) . "\xc3");
        $this->assertCount(1, $msgs);
        $this->assertSame(str_repeat('a', $max - 1), $msgs[0]->content);

        $msgs = $r->parse("\xa9\x1b[201~");
        $this->assertSame("\u{e9}", $msgs[1]->content);
    }

    /**
     * crush_libs.md candy-core #5, liveness half: a short paste whose end
     * marker is lost never nears MAX_PASTE_BYTES, so without a way out every
     * later keystroke disappeared into it. Program calls flushStalePaste()
     * after PASTE_IDLE_TIMEOUT of silence; this pins the reader side.
     */
    public function testStalePasteFlushClosesTheEnvelopeAndRestoresKeys(): void
    {
        $r = new InputReader();
        $r->parse("\x1b[200~abc");
        $this->assertTrue($r->isPasting());
        // Keystrokes after the lost marker are swallowed while the envelope is open.
        $this->assertSame([], $r->parse('x'));

        $msgs = $r->flushStalePaste();
        $this->assertCount(2, $msgs);
        $this->assertInstanceOf(PasteEndMsg::class, $msgs[0]);
        $this->assertInstanceOf(PasteMsg::class, $msgs[1]);
        $this->assertSame('abcx', $msgs[1]->content);
        $this->assertFalse($r->isPasting());

        $msgs = $r->parse('q');
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame('q', $msgs[0]->rune);
    }

    public function testStalePasteFlushKeepsTheHeldBackMarkerLookalikeAsContent(): void
    {
        $r = new InputReader(sanitizePaste: false);
        $r->parse("\x1b[200~ab\x1b[20");

        $msgs = $r->flushStalePaste();
        $this->assertSame("ab\x1b[20", $msgs[1]->content);

        // The held tail was moved into the paste, not left for the key
        // parser to complete into a phantom sequence with the next byte.
        $msgs = $r->parse('1~');
        $this->assertSame(['1', '~'], array_map(static fn (KeyMsg $m): string => $m->rune, $msgs));
    }

    public function testStalePasteFlushSanitizesLikeANormalClose(): void
    {
        $r = new InputReader();
        $r->parse("\x1b[200~ok\x1b]52;c;aGk=\x07");
        $msgs = $r->flushStalePaste();
        $this->assertSame('ok', $msgs[1]->content);
    }

    public function testStalePasteFlushIsANoOpOutsideAPaste(): void
    {
        $r = new InputReader();
        $this->assertFalse($r->isPasting());
        $this->assertSame([], $r->flushStalePaste());

        $r->parse("\x1b[200~p\x1b[201~");
        $this->assertFalse($r->isPasting());
        $this->assertSame([], $r->flushStalePaste());

        // A lone ESC outside a paste is the Escape-flush's business, untouched here.
        $r->parse("\x1b");
        $this->assertSame([], $r->flushStalePaste());
        $this->assertTrue($r->hasPendingEscape());
    }

    public function testBracketedPasteFollowedByKey(): void
    {
        $msgs = (new InputReader())->parse("\x1b[200~paste\x1b[201~q");
        $this->assertCount(4, $msgs);
        $this->assertInstanceOf(PasteStartMsg::class, $msgs[0]);
        $this->assertInstanceOf(PasteEndMsg::class, $msgs[1]);
        $this->assertInstanceOf(PasteMsg::class, $msgs[2]);
        $this->assertSame('paste', $msgs[2]->content);
        $this->assertInstanceOf(KeyMsg::class, $msgs[3]);
        $this->assertSame('q', $msgs[3]->rune);
    }

    public function testKeyMsgStringForFunctionKeys(): void
    {
        $this->assertSame('f1', (new KeyMsg(KeyType::F1))->string());
        $this->assertSame('f12', (new KeyMsg(KeyType::F12))->string());
    }

    // ---- terminal-query replies ------------------------------------------

    public function testCursorPositionReply(): void
    {
        $msgs = (new InputReader())->parse("\x1b[12;34R");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(CursorPositionMsg::class, $msgs[0]);
        $this->assertSame(12, $msgs[0]->row);
        $this->assertSame(34, $msgs[0]->col);
    }

    public function testBareCsiRStillEmitsF3(): void
    {
        // No params → F3, not a cursor reply.
        $msgs = (new InputReader())->parse("\x1b[R");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame(KeyType::F3, $msgs[0]->type);
    }

    public function testForegroundColorReplyBel(): void
    {
        $msgs = (new InputReader())->parse("\x1b]10;rgb:ffff/ffff/ffff\x07");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(ForegroundColorMsg::class, $msgs[0]);
        $this->assertSame(255, $msgs[0]->r);
        $this->assertSame(255, $msgs[0]->g);
        $this->assertSame(255, $msgs[0]->b);
        $this->assertTrue($msgs[0]->isDark() === false);
    }

    public function testBackgroundColorReplyStTerminator(): void
    {
        // ESC \ instead of BEL.
        $msgs = (new InputReader())->parse("\x1b]11;rgb:0000/0000/0000\x1b\\");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(BackgroundColorMsg::class, $msgs[0]);
        $this->assertSame(0, $msgs[0]->r);
        $this->assertTrue($msgs[0]->isDark());
    }

    public function testBackgroundColorReplyShortHex(): void
    {
        // 2-digit channels (no scaling needed since maxFor = 0xff).
        $msgs = (new InputReader())->parse("\x1b]11;rgb:80/40/20\x07");
        $this->assertSame(0x80, $msgs[0]->r);
        $this->assertSame(0x40, $msgs[0]->g);
        $this->assertSame(0x20, $msgs[0]->b);
    }

    public function testOscSplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b]11;rgb:ff"));
        $this->assertSame([], $r->parse("ff/0000/00"));
        $msgs = $r->parse("00\x07");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(BackgroundColorMsg::class, $msgs[0]);
        $this->assertSame(255, $msgs[0]->r);
    }

    public function testCursorPositionSplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1b[5;"));
        $msgs = $r->parse("10R");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(CursorPositionMsg::class, $msgs[0]);
        $this->assertSame(5, $msgs[0]->row);
        $this->assertSame(10, $msgs[0]->col);
    }

    public function testCursorColorReply(): void
    {
        $msgs = (new InputReader())->parse("\x1b]12;rgb:8080/4040/2020\x07");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(CursorColorMsg::class, $msgs[0]);
        // 0x8080 / 0xffff * 255 ≈ 128.
        $this->assertSame(128, $msgs[0]->r);
        $this->assertSame(64, $msgs[0]->g);
        $this->assertSame(32, $msgs[0]->b);
        $this->assertSame('#804020', $msgs[0]->hex());
    }

    public function testTerminalVersionReply(): void
    {
        // ESC P > | <text> ESC \
        $msgs = (new InputReader())->parse("\x1bP>|xterm(367)\x1b\\");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(TerminalVersionMsg::class, $msgs[0]);
        $this->assertSame('xterm(367)', $msgs[0]->version);
    }

    public function testTerminalVersionWithBelTerminator(): void
    {
        // Some sloppy terminals use BEL — accept it.
        $msgs = (new InputReader())->parse("\x1bP>|iTerm2 3.4.16\x07");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(TerminalVersionMsg::class, $msgs[0]);
        $this->assertSame('iTerm2 3.4.16', $msgs[0]->version);
    }

    public function testTerminalVersionSplitAcrossReads(): void
    {
        $r = new InputReader();
        $this->assertSame([], $r->parse("\x1bP>|kitt"));
        $this->assertSame([], $r->parse('y(0.31.0)'));
        $msgs = $r->parse("\x1b\\");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(TerminalVersionMsg::class, $msgs[0]);
        $this->assertSame('kitty(0.31.0)', $msgs[0]->version);
    }

    public function testEscPWithoutAngleBracketIsAltP(): void
    {
        // Plain ESC P (no XTVERSION marker) is Alt-P, not DCS.
        $msgs = (new InputReader())->parse("\x1bP");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        $this->assertSame('P', $msgs[0]->rune);
        $this->assertTrue($msgs[0]->alt);
    }

    // ---- DECRPM mode report (DECRQM reply) ------------------------------

    public function testModeReportPrivateSet(): void
    {
        // CSI ? 1006 ; 1 $ y → mouse-SGR mode is set.
        $msgs = (new InputReader())->parse("\x1b[?1006;1\$y");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(ModeReportMsg::class, $msgs[0]);
        $this->assertSame(1006, $msgs[0]->mode);
        $this->assertTrue($msgs[0]->private);
        $this->assertSame(ModeState::Set, $msgs[0]->state);
        $this->assertTrue($msgs[0]->state->isActive());
    }

    public function testModeReportAnsiReset(): void
    {
        // CSI 4 ; 2 $ y → ANSI mode 4 (insert/replace) is reset.
        $msgs = (new InputReader())->parse("\x1b[4;2\$y");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(ModeReportMsg::class, $msgs[0]);
        $this->assertSame(4, $msgs[0]->mode);
        $this->assertFalse($msgs[0]->private);
        $this->assertSame(ModeState::Reset, $msgs[0]->state);
        $this->assertFalse($msgs[0]->state->isActive());
    }

    public function testModeReportPermanentlySet(): void
    {
        // CSI ? 2026 ; 3 $ y → sync mode is permanently set.
        $msgs = (new InputReader())->parse("\x1b[?2026;3\$y");
        $this->assertSame(ModeState::PermanentlySet, $msgs[0]->state);
        $this->assertTrue($msgs[0]->state->isActive());
    }

    public function testModeReportNotRecognized(): void
    {
        $msgs = (new InputReader())->parse("\x1b[?9999;0\$y");
        $this->assertSame(ModeState::NotRecognized, $msgs[0]->state);
    }

    // ---- modified key sequences (xterm `1;<mod>` form) -------------------

    public function testCtrlUpArrow(): void
    {
        // CSI 1;5A → ctrl-Up.
        $msgs = (new InputReader())->parse("\x1b[1;5A");
        $this->assertCount(1, $msgs);
        $this->assertSame(KeyType::Up, $msgs[0]->type);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertFalse($msgs[0]->alt);
        $this->assertFalse($msgs[0]->shift);
    }

    public function testShiftAltDownArrow(): void
    {
        // mod 4 = shift+alt.
        $msgs = (new InputReader())->parse("\x1b[1;4B");
        $this->assertSame(KeyType::Down, $msgs[0]->type);
        $this->assertTrue($msgs[0]->shift);
        $this->assertTrue($msgs[0]->alt);
        $this->assertFalse($msgs[0]->ctrl);
    }

    public function testCtrlShiftAltLeft(): void
    {
        // mod 8 = shift+alt+ctrl.
        $msgs = (new InputReader())->parse("\x1b[1;8D");
        $this->assertSame(KeyType::Left, $msgs[0]->type);
        $this->assertTrue($msgs[0]->shift);
        $this->assertTrue($msgs[0]->alt);
        $this->assertTrue($msgs[0]->ctrl);
    }

    public function testModifiedTildeFormFunctionKey(): void
    {
        // CSI 15;3~ → alt-F5.
        $msgs = (new InputReader())->parse("\x1b[15;3~");
        $this->assertSame(KeyType::F5, $msgs[0]->type);
        $this->assertTrue($msgs[0]->alt);
        $this->assertFalse($msgs[0]->shift);
    }

    public function testKeyMsgTextAndCodeAliases(): void
    {
        $printable = (new InputReader())->parse('a')[0];
        $this->assertSame('a', $printable->text());
        $this->assertSame(KeyType::Char, $printable->code());

        $named = (new InputReader())->parse("\x1b[A")[0];
        $this->assertSame('', $named->text());
        $this->assertSame(KeyType::Up, $named->code());
    }

    public function testKeyMsgModifiersAccessor(): void
    {
        $ctrlUp = (new InputReader())->parse("\x1b[1;5A")[0];
        $mods = $ctrlUp->modifiers();
        $this->assertInstanceOf(Modifiers::class, $mods);
        $this->assertTrue($mods->ctrl);
        $this->assertFalse($mods->alt);
        $this->assertFalse($mods->shift);
        $this->assertSame(Modifiers::CTRL, $mods->toBitfield());

        $plain = (new InputReader())->parse('a')[0];
        $this->assertTrue($plain->modifiers()->isEmpty());
    }

    public function testKeyMsgStringIncludesShiftPrefix(): void
    {
        // Direct construction: shift-Up renders as "shift+up".
        $key = new KeyMsg(KeyType::Up, shift: true);
        $this->assertSame('shift+up', $key->string());
    }

    public function testModifiersFromXtermMod(): void
    {
        $this->assertTrue(Modifiers::fromXtermMod(1)->isEmpty()); // 1 = no mods
        $this->assertEquals(Modifiers::SHIFT, Modifiers::fromXtermMod(2)->toBitfield());
        $this->assertEquals(Modifiers::ALT, Modifiers::fromXtermMod(3)->toBitfield());
        $this->assertEquals(Modifiers::CTRL, Modifiers::fromXtermMod(5)->toBitfield());
        $this->assertEquals(
            Modifiers::SHIFT | Modifiers::ALT | Modifiers::CTRL,
            Modifiers::fromXtermMod(8)->toBitfield(),
        );
    }

    // ---- OSC 52 clipboard reply ------------------------------------------

    public function testClipboardReplyDecodesBase64(): void
    {
        $payload = base64_encode('hello world');
        $msgs = (new InputReader())->parse("\x1b]52;c;{$payload}\x07");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(ClipboardMsg::class, $msgs[0]);
        $this->assertSame('hello world', $msgs[0]->content);
        $this->assertSame('c', $msgs[0]->selection);
    }

    public function testClipboardReplyPrimarySelection(): void
    {
        $payload = base64_encode('xclip text');
        $msgs = (new InputReader())->parse("\x1b]52;p;{$payload}\x1b\\");
        $this->assertCount(1, $msgs);
        $this->assertSame('xclip text', $msgs[0]->content);
        $this->assertSame('p', $msgs[0]->selection);
    }

    public function testClipboardReplyEmptyContent(): void
    {
        $msgs = (new InputReader())->parse("\x1b]52;c;\x07");
        $this->assertCount(1, $msgs);
        $this->assertSame('', $msgs[0]->content);
    }

    public function testClipboardReplyDefaultSelection(): void
    {
        // Empty selection field defaults to 'c'.
        $payload = base64_encode('x');
        $msgs = (new InputReader())->parse("\x1b]52;;{$payload}\x07");
        $this->assertSame('c', $msgs[0]->selection);
    }

    public function testClipboardReplyInvalidBase64Ignored(): void
    {
        $msgs = (new InputReader())->parse("\x1b]52;c;!!!notbase64\x07");
        $this->assertCount(0, $msgs);
    }

    // ---- Kitty keyboard flag report --------------------------------------

    public function testKittyKeyboardFlagsReply(): void
    {
        // CSI ? 11 u → flags 1 (DISAMBIGUATE) + 2 (REPORT_EVENT_TYPES) + 8.
        $msgs = (new InputReader())->parse("\x1b[?11u");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyboardEnhancementsMsg::class, $msgs[0]);
        $this->assertSame(11, $msgs[0]->flags);
        $this->assertTrue($msgs[0]->has(KeyboardEnhancementsMsg::DISAMBIGUATE));
        $this->assertTrue($msgs[0]->has(KeyboardEnhancementsMsg::REPORT_EVENT_TYPES));
        $this->assertFalse($msgs[0]->has(KeyboardEnhancementsMsg::REPORT_ALTERNATES));
        $this->assertTrue($msgs[0]->has(KeyboardEnhancementsMsg::REPORT_ALL_AS_ESC));
    }

    public function testKittyKeyboardFlagsReplyZero(): void
    {
        $msgs = (new InputReader())->parse("\x1b[?0u");
        $this->assertCount(1, $msgs);
        $this->assertSame(0, $msgs[0]->flags);
        $this->assertFalse($msgs[0]->has(KeyboardEnhancementsMsg::DISAMBIGUATE));
    }

    // ---- Kitty per-key event format --------------------------------------

    public function testKittyPlainPrintablePress(): void
    {
        // CSI 97 u → 'a' press.
        $msgs = (new InputReader())->parse("\x1b[97u");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyPressMsg::class, $msgs[0]);
        $this->assertSame(KeyType::Char, $msgs[0]->type);
        $this->assertSame('a', $msgs[0]->rune);
    }

    public function testKittyKeyRelease(): void
    {
        // CSI 97;1:3 u → 'a' release.
        $msgs = (new InputReader())->parse("\x1b[97;1:3u");
        $this->assertCount(1, $msgs);
        $this->assertInstanceOf(KeyReleaseMsg::class, $msgs[0]);
        $this->assertSame('a', $msgs[0]->rune);
    }

    public function testKittyKeyRepeat(): void
    {
        // CSI 97;1:2 u → 'a' repeat.
        $msgs = (new InputReader())->parse("\x1b[97;1:2u");
        $this->assertInstanceOf(KeyRepeatMsg::class, $msgs[0]);
    }

    public function testKittyCtrlShiftA(): void
    {
        // mod 6 = shift+ctrl, event implicit press, no text section.
        $msgs = (new InputReader())->parse("\x1b[97;6u");
        $this->assertInstanceOf(KeyPressMsg::class, $msgs[0]);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertTrue($msgs[0]->shift);
    }

    public function testKittyTextSectionPreferredForRune(): void
    {
        // Code 65 ('A') with text section 97 ('a') — terminal is
        // reporting the typed character via the text leg; we should
        // use it as the rune.
        $msgs = (new InputReader())->parse("\x1b[65;1;97u");
        $this->assertSame('a', $msgs[0]->rune);
    }

    public function testKittyNamedKey(): void
    {
        // CSI 13 u → Enter.
        $msgs = (new InputReader())->parse("\x1b[13u");
        $this->assertSame(KeyType::Enter, $msgs[0]->type);

        // CSI 27 u → Escape.
        $msgs = (new InputReader())->parse("\x1b[27u");
        $this->assertSame(KeyType::Escape, $msgs[0]->type);
    }

    public function testKittyMsgsAreStillKeyMsg(): void
    {
        // Existing instanceof KeyMsg checks should keep working.
        $msgs = (new InputReader())->parse("\x1b[97u");
        $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
    }

    /**
     * Kitty progressive keyboard protocol uses Private Use Area
     * codepoints for named functional keys. Spot-check one entry per
     * group: F-key, keypad, media, lock, system, modifier-as-key.
     */
    public function testKittyFunctionalKeyTable(): void
    {
        $cases = [
            // F-keys
            57364 => KeyType::F1,
            57375 => KeyType::F12,
            57376 => KeyType::F13,
            57398 => KeyType::F35,
            // Keypad
            57399 => KeyType::Kp0,
            57408 => KeyType::Kp9,
            57414 => KeyType::KpEnter,
            57427 => KeyType::KpBegin,
            // Media
            57428 => KeyType::MediaPlay,
            57430 => KeyType::MediaPlayPause,
            57440 => KeyType::MuteVolume,
            // Lock + system
            57358 => KeyType::CapsLock,
            57361 => KeyType::PrintScreen,
            57363 => KeyType::Menu,
            // Modifier-as-key
            57441 => KeyType::LeftShift,
            57452 => KeyType::RightMeta,
            57454 => KeyType::IsoLevel5Shift,
        ];

        foreach ($cases as $code => $expected) {
            $msgs = (new InputReader())->parse("\x1b[{$code}u");
            $this->assertCount(1, $msgs, "code {$code} should yield one msg");
            $this->assertSame(
                $expected,
                $msgs[0]->type,
                "code {$code} should map to {$expected->name}",
            );
            $this->assertInstanceOf(KeyMsg::class, $msgs[0]);
        }
    }

    public function testKittyFunctionalKeyWithModifiers(): void
    {
        // Ctrl+Shift+F1 = CSI 57364 ; 6 u (mod byte 6 → bits 0b101 = ctrl+shift).
        $msgs = (new InputReader())->parse("\x1b[57364;6u");
        $this->assertSame(KeyType::F1, $msgs[0]->type);
        $this->assertTrue($msgs[0]->ctrl);
        $this->assertTrue($msgs[0]->shift);
        $this->assertFalse($msgs[0]->alt);
    }

    public function testKittyFunctionalKeyMapIsExhaustive(): void
    {
        // Sanity check: every Kitty PUA codepoint between 57344 and
        // 57454 should map to a distinct KeyType case.
        $map = KeyType::kittyFunctionalKeys();
        $this->assertCount(count(array_unique($map, SORT_REGULAR)), $map);
        $this->assertGreaterThanOrEqual(80, count($map));
    }

    // ------------------------------------------------------------------
    // UTF-8 rune assembly — candy-tetris audit finding #2 soft-lock pins.
    // Raw glyph bytes (paste / IME path) must never wedge the parser:
    // undecodable input is dropped, a read drains fully, and only a
    // plausible split mid-rune may park.
    // ------------------------------------------------------------------

    public function testGlyphBurstReadDrainsCompletelyAndQuitFollowsImmediately(): void
    {
        // The exact t1 bisect burst: literal "→→↓" pasted in one read,
        // then "q" in the next. Pre-fix, the sequence-length variable
        // clobbered the buffer-length driving the walk, so each read
        // parsed exactly one rune and deferred everything behind it —
        // the trailing q never surfaced without further input.
        $reader = new InputReader();
        $msgs   = $reader->parse("\xe2\x86\x92\xe2\x86\x92\xe2\x86\x93");
        $this->assertCount(3, $msgs);
        $this->assertSame(['→', '→', '↓'], array_map(fn (KeyMsg $m): string => $m->rune, $msgs));

        $after = $reader->parse('q');
        $this->assertCount(1, $after);
        $this->assertSame('q', $after[0]->rune);
    }

    public function testGlyphBurstAndQuitInASingleReadAreAllEmitted(): void
    {
        $msgs = (new InputReader())->parse("\xe2\x86\x92q");
        $this->assertCount(2, $msgs);
        $this->assertSame('→', $msgs[0]->rune);
        $this->assertSame('q', $msgs[1]->rune);
    }

    public function testParkedLeadByteSelfHealsWhenNextByteIsNotAContinuation(): void
    {
        // Fatal-wedge shape: a read that ends mid-rune parks the lead
        // byte; when the next read opens with an ASCII byte, the parked
        // sequence is already disproven. Pre-fix the length-only check
        // broke at $i = 0 on every later read, poisoning the buffer head
        // forever — no key, including q, ever parsed again.
        $reader = new InputReader();
        $this->assertCount(0, $reader->parse("\xe2"));

        $msgs = $reader->parse("q");
        $this->assertCount(1, $msgs);
        $this->assertSame('q', $msgs[0]->rune);
    }

    public function testPlausibleSplitRuneAcrossReadsStillAssembles(): void
    {
        // The park contract that MUST survive: every byte so far is a
        // real continuation, so the remainder is genuinely owed.
        $reader = new InputReader();
        $this->assertCount(0, $reader->parse("\xe2\x86"));

        $msgs = $reader->parse("\x92");
        $this->assertCount(1, $msgs);
        $this->assertSame('→', $msgs[0]->rune);
    }

    public function testUndecodableBytesAreDroppedNeverEmittedAndStreamKeepsDraining(): void
    {
        // Stray continuation, overlong form, disproven 2-byte lead, and
        // invalid lead — each drops one byte at a time and lets the real
        // key through. No malformed-UTF-8 rune ever reaches the model.
        $cases = [
            'stray-continuation' => "\x80q",
            'overlong'           => "\xc0\x80q",
            'disproven-lead'     => "\xc3q",
            'invalid-lead'       => "\xf8q",
            'truncated-emoji'    => "\xf0\x9fqa",
        ];
        foreach ($cases as $label => $bytes) {
            $msgs = (new InputReader())->parse($bytes);
            $runes = array_map(fn (KeyMsg $m): string => $m->rune, $msgs);
            $this->assertContains('q', $runes, $label);
            foreach ($runes as $rune) {
                $this->assertTrue(mb_check_encoding($rune, 'UTF-8'), $label . ' emitted malformed rune');
            }
        }
    }

    public function testMultibyteRuneBetweenKeysDoesNotDeferTheTail(): void
    {
        // Interleaved flow — key, CJK rune, key — in one read must emit
        // all three in order (the walk continues past a rune boundary).
        $msgs = (new InputReader())->parse("a\xe4\xbd\xa0b");
        $this->assertCount(3, $msgs);
        $this->assertSame(['a', '你', 'b'], array_map(fn (KeyMsg $m): string => $m->rune, $msgs));
    }
}
