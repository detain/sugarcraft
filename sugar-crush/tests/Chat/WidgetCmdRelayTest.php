<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\RawMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Forms\TextArea\TextArea;

/**
 * E744 WS1-WS3: the frame consumes its widget's vocabulary.
 *
 * Before this lane, Chat dropped the Cmd slot of every delegated keystroke
 * (`[$next] = $this->input->update($msg)`), typed its own paste around the
 * widget's paste arm, and answered Ctrl+C itself even when the draft held a
 * selection — so TextArea's copy/cut chords (r89) raised clipboard Cmds that
 * evaporated inside update(). These pins own the three rulings in design.md:
 *
 *  - WS2 precedence: selection up → the chord is COPY; no selection → quit,
 *    byte-unchanged; raw "\x03" → quit in ANY state (escape-hatch polarity).
 *  - WS1 relay: the clipboard Cmd reaches the terminal as OSC 52 through a
 *    LAZY wrapper that clips oversized copies to {@see Chat::OSC52_MAX_CHARS}
 *    and announces the clip as a runtime notice; everything else relays.
 *  - WS3 paste: the widget's own paste arm runs (selection replaced, E736
 *    5.13), with the pre-E744 insertString fallback for the unfocused state.
 */
final class WidgetCmdRelayTest extends TestCase
{
    protected function setUp(): void
    {
        // In-process backend: records reach drain() without a fork pair.
        RuntimeNoticeSink::arm(false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
    }

    // ── WS2: Ctrl+C precedence ────────────────────────────────────────────

    public function testCtrlCCopiesTheSelectionWhenOneIsUp(): void
    {
        $chat = $this->chatWithSelection('hello world', 0, 5);

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        $this->assertNotNull($cmd, 'with a selection, Ctrl+C is the editor copy chord');
        $msg = $cmd();
        $this->assertInstanceOf(RawMsg::class, $msg, 'the copy must reach the terminal, not the quit path');
        $this->assertSame(
            Ansi::setClipboard('hello', 'c'),
            $msg->bytes,
            'the relay re-emits the widget Cmd byte-for-byte: OSC 52 selection c, base64 payload, BEL'
        );
        $this->assertInstanceOf(Chat::class, $next);
        $this->assertFalse($next->inFlight, 'copying is not quitting');
    }

    public function testCtrlCWithoutSelectionStillQuits(): void
    {
        $chat = new Chat(input: $this->focusedTextArea('hello world'));

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));

        $this->assertNotNull($cmd);
        $this->assertInstanceOf(QuitMsg::class, $cmd(), 'no selection keeps the quit law byte-unchanged');
    }

    public function testRawControlCRuneQuitsEvenWithASelection(): void
    {
        $chat = $this->chatWithSelection('hello world', 0, 5);

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Char, "\x03"));

        $this->assertNotNull($cmd);
        $this->assertInstanceOf(
            QuitMsg::class,
            $cmd(),
            'the synthesized raw rune is the programmatic escape hatch — it means the signal, not the copy gesture'
        );
    }

    // ── WS1: the relay's OSC 52 policy ────────────────────────────────────

    public function testOversizedCopyIsClippedAndAnnounced(): void
    {
        $needle = 'é' . str_repeat('ab☃', 30_000); // 90_001 chars, 220_003 bytes
        $this->assertSame(90_001, mb_strlen($needle, 'UTF-8'), 'fixture: over the cap by characters');
        $chat = new Chat(input: $this->selectedTextArea($needle, 0, 90_001));
        $this->assertTrue($chat->input->hasSelection(), 'fixture: the whole buffer is selected');

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'c', ctrl: true));
        $this->assertNotNull($cmd);
        $msg = $cmd();
        $this->assertInstanceOf(RawMsg::class, $msg);

        [$selection, $decoded] = $this->decodeOsc52($msg);
        $this->assertSame('c', $selection, 'the clip re-emits through the same selection slot');
        $this->assertSame(
            mb_substr($needle, 0, Chat::OSC52_MAX_CHARS, 'UTF-8'),
            $decoded,
            'the payload is the FIRST cap characters — characters, not bytes'
        );
        $this->assertSame(
            ['Clipboard copy clipped to 65536 of 90001 characters.'],
            RuntimeNoticeSink::drain(),
            'the clip is announced on the transcript notice channel, once, and NOT on stderr'
        );
    }

    public function testUnparseableOsc52PayloadPassesThroughVerbatim(): void
    {
        $forged = new RawMsg(Ansi::OSC . '52;c;not!base64!' . Ansi::BEL);

        $relayed = $this->relayed(new Chat(), static fn (): RawMsg => $forged);

        $this->assertSame($forged, $relayed, 'the relay refuses to fabricate a terminal sequence it cannot decode');
    }

    public function testNonClipboardTrafficIsRelayedUnchanged(): void
    {
        $other = new QuitMsg();
        $this->assertSame($other, $this->relayed(new Chat(), static fn (): QuitMsg => $other), 'the OSC 52 policy is exactly one rule wide');

        $raw = new RawMsg('plain bytes, no OSC');
        $this->assertSame($raw, $this->relayed(new Chat(), static fn (): RawMsg => $raw), 'a raw write outside the clipboard shape rides through');

        $this->assertNull($this->relayed(new Chat(), static fn (): ?RawMsg => null), 'a Cmd that produces nothing relays nothing');
    }

    public function testTheRelayStaysLazy(): void
    {
        $invoked = 0;
        $inner = static function () use (&$invoked): ?Msg {
            $invoked++;

            return null;
        };
        $wrapper = $this->relayFor(new Chat())($inner);

        $this->assertSame(0, $invoked, 'wrapping must not evaluate the widget Cmd early — Program ticks it');
        $wrapper();
        $this->assertSame(1, $invoked, 'and the wrapper invokes the inner Cmd exactly once per call');
    }

    // ── WS3: paste goes through the widget ────────────────────────────────

    public function testPasteReplacesASelection(): void
    {
        $chat = $this->chatWithSelection('hello world', 0, 5);

        [$next] = $chat->update(new PasteMsg('X'));

        $this->assertSame('X world', $next->inputBuf, 'the selection is replaced, not ignored (E736 5.13)');
        $this->assertSame($next->input->value(), $next->inputBuf, 'the draft projection stays derived, not parallel');
    }

    public function testPasteWithoutSelectionMatchesTheLegacyInsertStringByteForByte(): void
    {
        $chat = new Chat(input: $this->focusedTextArea("line\tones")->setCursor(1, 0));
        $legacy = $chat->input->insertString("\ttabbed");

        [$next] = $chat->update(new PasteMsg("\ttabbed"));

        $this->assertSame($legacy->value(), $next->input->value(), 'E704 verbatim: no selection, no behavior change');
        $this->assertSame($legacy->line(), $next->input->line());
        $this->assertSame($legacy->column(), $next->input->column());
    }

    public function testPasteIntoAnUnfocusedDraftStillLands(): void
    {
        $chat = new Chat(input: TextArea::new()->setValue('draft')->blur());

        [$next] = $chat->update(new PasteMsg('ed text'));

        $this->assertSame('drafted text', $next->inputBuf, 'the fallback keeps the pre-E744 contract: paste lands');
    }

    // ── fixtures ───────────────────────────────────────────────────────────

    /**
     * A draft with the span (0,$anchorCol)→(0,$caretCol) selected — the shape
     * a shift-selection or a drag leaves behind (house idiom copied from
     * candy-forms' own TextAreaClipboardTest).
     */
    private function chatWithSelection(string $value, int $anchorCol, int $caretCol): Chat
    {
        return new Chat(input: $this->selectedTextArea($value, $anchorCol, $caretCol));
    }

    /**
     * Focused editor holding `$value` with the span (0,$anchorCol)→(0,$caretCol)
     * selected — house idiom from candy-forms' own TextAreaClipboardTest
     * (focus() is the Model contract's [self, ?Cmd] pair).
     */
    private function selectedTextArea(string $value, int $anchorCol, int $caretCol): TextArea
    {
        // charLimit(0): the chat draft is unbounded (freshInput law) — and the
        // default 65536-char buffer cap would silently clip the oversized
        // fixture below the OSC 52 cap this test exists to cross.
        $input = TextArea::new()->withCharLimit(0)->setValue($value);
        [$input] = $input->focus();

        return $input->withSelect(0, $anchorCol)->setCursor(0, $caretCol);
    }

    /**
     * The frame's private relay as a callable — reflection is the house seam
     * for pinning the un-reachable middle layer directly (lane-ab precedent).
     */
    private function relayFor(Chat $chat): \Closure
    {
        return (new \ReflectionMethod(Chat::class, 'relayWidgetCmd'))->getClosure($chat);
    }

    /** Focused editor holding `$value`, caret wherever setValue left it. */
    private function focusedTextArea(string $value): TextArea
    {
        [$input] = TextArea::new()->withCharLimit(0)->setValue($value)->focus();

        return $input;
    }

    /**
     * One widget Cmd, driven through the frame relay to the Msg it raises —
     * the relay is lazy, so the pin calls the wrapper exactly like Program's
     * scheduled tick does.
     */
    private function relayed(Chat $chat, \Closure $inner): ?Msg
    {
        return $this->relayFor($chat)($inner)();
    }

    /**
     * Parse a relayed OSC 52 write into its selection slot and decoded text.
     *
     * @return array{0: string, 1: string}
     */
    private function decodeOsc52(RawMsg $msg): array
    {
        $pattern = '/\A' . preg_quote(Ansi::OSC, '/') . '52;(.);([A-Za-z0-9+\/=]*)'
            . preg_quote(Ansi::BEL, '/') . '\z/s';
        $this->assertSame(1, preg_match($pattern, $msg->bytes, $out), 'relay output must be a decodable OSC 52 write');
        $decoded = base64_decode($out[2], true);
        $this->assertNotFalse($decoded);

        return [$out[1], $decoded];
    }
}
