<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Mode\{EmacsMode, ViMode};
use SugarCraft\Readline\{Key, Readline, SelectionPrompt, TextPrompt};

/**
 * End-to-end chord dispatch: RAW terminal bytes driven through
 * Readline::run() — the production path a real TTY takes — and the chords
 * must actually fire.
 *
 * This is the regression family for the dead-dispatch defect: the pump
 * derives SYMBOLIC names ('ctrl_a') from KeyEvents while the emacs/vi
 * modes matched RAW bytes ("\x01"), so every binding looked implemented
 * and was unreachable. The fix canonicalises at the choke
 * ({@see Key::normalize()}); neutering that normalization reddens these
 * pins by name.
 *
 * Sequences always end on a terminal key (enter / ctrl_c) so the loop
 * exits on the final event — an exhausted injected memory stream is NOT
 * distinguishable from a live-empty one and would idle forever.
 */
final class ReadlineChordDispatchTest extends TestCase
{
    /**
     * Feed raw bytes through the real dispatch: bytes → StreamInputDriver
     * → Readline::run() → prompt state transitions.
     *
     * A socket pair, not php://memory: the driver selects on the stream, and
     * stream_select() cannot poll memory streams.
     */
    private function drive(string $bytes, ?TextPrompt $prompt = null, $output = null): TextPrompt
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        fwrite($pair[1], $bytes);
        fclose($pair[1]);

        $readline = new Readline(new StreamInputDriver($pair[0]));
        $final = $readline->run($prompt ?? TextPrompt::new('> '), $output);
        fclose($pair[0]);

        $this->assertInstanceOf(TextPrompt::class, $final);
        /** @var TextPrompt $final */
        return $final;
    }

    // =========================================================================
    // Emacs chords — each was dead through run() before the normalize choke
    // =========================================================================

    public function testCtrlAByteMovesToHomeAndInsertionLandsThere(): void
    {
        // 'abc', Ctrl+A (home), type X, Enter → 'Xabc'
        $final = $this->drive('abc' . "\x01" . 'X' . "\r");
        $this->assertTrue($final->isSubmitted());
        $this->assertSame('Xabc', $final->value());
    }

    public function testCtrlEAndCtrlBBytesMoveCursor(): void
    {
        // 'ab', Ctrl+B (left → between a|b), X, Ctrl+E (end), Y, Enter → 'aXbY'
        $final = $this->drive('ab' . "\x02" . 'X' . "\x05" . 'Y' . "\r");
        $this->assertSame('aXbY', $final->value());
    }

    public function testCtrlTTransposeByteSwapsAtEndOfLineThroughRun(): void
    {
        // Transpose is an EmacsMode binding; broken pre-fix (re-inserted
        // the original order — a no-op).
        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode());
        $final = $this->drive('abc' . "\x14" . "\r", $prompt);
        $this->assertSame('acb', $final->value());
    }

    public function testCtrlKAndCtrlUBytesKillRegions(): void
    {
        // 'abc', Ctrl+A → home, Ctrl+K kills 'abc' → '', Ctrl+U no-op,
        // type z, Enter → 'z'
        $final = $this->drive('abc' . "\x01" . "\x0b" . "\x15" . 'z' . "\r");
        $this->assertSame('z', $final->value());
    }

    public function testCtrlWWordBackspaceByte(): void
    {
        $final = $this->drive('hello world' . "\x17" . "\r");
        $this->assertSame('hello ', $final->value());
    }

    public function testCtrlLByteRepaintsWithoutEditing(): void
    {
        $final = $this->drive('keep' . "\x0c" . "\r");
        $this->assertSame('keep', $final->value());
    }

    public function testCtrlDOnEmptyBufferAbortsThroughRun(): void
    {
        // GNU delete-char-or-eof: empty line + Ctrl+D is EOF → abort.
        $final = $this->drive("\x04");
        $this->assertTrue($final->isAborted());
    }

    public function testCtrlDMidLineForwardDeletesThroughRun(): void
    {
        // 'ab', Ctrl+A (home), Ctrl+D deletes UNDER cursor ('a') → 'b'
        $final = $this->drive('ab' . "\x01" . "\x04" . "\r");
        $this->assertSame('b', $final->value());
    }

    // =========================================================================
    // Meta prefix — ESC-then-key cooked sequences
    // =========================================================================

    public function testEscBMetaWordBackThroughRun(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode());
        // 'one two', ESC b → cursor before 'two', type X → 'one Xtwo'
        $final = $this->drive('one two' . "\x1b" . 'b' . 'X' . "\r", $prompt);
        $this->assertSame('one Xtwo', $final->value());
    }

    public function testSecondEscCancelsTheMetaPrefixNotThePrompt(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new EmacsMode());
        // ESC arms meta, ESC again cancels it (GNU parity — NOT abort),
        // 'b' is then a plain char.
        $final = $this->drive('ab' . "\x1b" . "\x1b" . 'b' . "\r", $prompt);
        $this->assertFalse($final->isAborted(), 'double-ESC must not abort');
        $this->assertSame('abb', $final->value());
    }

    public function testAltLetterSymbolicChordReachesEmacsWordBack(): void
    {
        // Kitty keyboard protocol reports Alt+letter directly; the mode
        // must honor 'alt_b' without an ESC-prefix dance.
        $prompt = TextPrompt::new('> ')
            ->withMode(new EmacsMode())
            ->handleChar('o')
            ->handleChar('n')
            ->handleChar('e')
            ->handleChar(' ')
            ->handleChar('t')
            ->handleChar('w')
            ->handleChar('o');
        $prompt = $prompt->handleKey('alt_b');
        $this->assertSame(4, $prompt->cursor(), 'alt_b lands before the last word');
    }

    // =========================================================================
    // Vi — printable keys are COMMANDS in normal mode, not insertions
    // =========================================================================

    public function testViNormalModePrintablesAreCommandsThroughRun(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new ViMode());
        // 'ab', ESC → normal; 'dd' deletes the line; 'i' back to insert;
        // type z; Enter → 'z'. Pre-fix the d/d/i chars bypassed the mode
        // and inserted garbage.
        $final = $this->drive('ab' . "\x1b" . 'dd' . 'i' . 'z' . "\r", $prompt);
        $this->assertSame('z', $final->value());
    }

    public function testViDollarGoesToEndOfLineThroughRun(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new ViMode());
        // ESC+0 arrive in one chunk — sugar-input reports that pair as
        // 'alt_0', which vi reads as Escape (normal mode) then '0' (start
        // of line). From column 0, '$' must jump back to the last character
        // (vi '$' sits ON it), and 'a' appends after it. Without a working
        // '$' the 'X' would land after the 'a' at the line start instead.
        $final = $this->drive('ab cd' . "\x1b" . '0' . '$' . 'a' . 'X' . "\r", $prompt);
        $this->assertSame('ab cdX', $final->value());
    }

    public function testCtrlPByteWalksHistoryOnAPlainPrompt(): void
    {
        // The default (emacs-style) keymap: Ctrl+P reaches history even
        // with no mode attached — \x10 → 'ctrl_p' → Up alias. (Feeding ESC
        // + \x10 through one read chunk is NOT testable: sugar-input merges
        // them into a single meta-ctrl event with an empty key name — a
        // sugar-input seam, documented in the report.)
        $history = new InMemoryHistory();
        $history->push('older-entry');
        $prompt = TextPrompt::new('> ')->withHistory($history);
        $final = $this->drive("\x10" . "\r", $prompt);
        $this->assertSame('older-entry', $final->value());
    }

    // =========================================================================
    // onKey() registration — symbolic normalization at the registration edge
    // =========================================================================

    public function testOnKeyRegisteredWithEscapeNameFiresOnTheEscByte(): void
    {
        $fired = false;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], "\x1b");
        fclose($pair[1]);

        $readline = (new Readline(new StreamInputDriver($pair[0])))
            ->onKey('escape', static function () use (&$fired): void {
                $fired = true;
            });
        $final = $readline->run(TextPrompt::new('> '));

        fclose($pair[0]);
        $this->assertTrue($fired, "onKey('escape') must reach the ESC dispatch (name normalized at registration)");
        $this->assertTrue($final->isAborted());
    }

    // =========================================================================
    // Painting — run(p) with no output paints NOTHING (the docblock truth);
    // frames erase the previous frame's rows instead of staircasing.
    // =========================================================================

    public function testPaintFramesErasePreviousRows(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], 'a' . "\x03");
        fclose($pair[1]);

        $output = fopen('php://memory', 'w+');
        $readline = new Readline(new StreamInputDriver($pair[0]));
        $final = $readline->run(TextPrompt::new('> '), $output);

        fclose($pair[0]);
        rewind($output);
        $captured = (string) stream_get_contents($output);
        fclose($output);

        $this->assertTrue($final->isAborted());
        // Initial frame at the top: no upward move, just clear-to-end.
        $this->assertStringStartsWith("\r\x1b[0J", $captured);
        // Second frame carries the carriage-return clear for the prior row.
        $this->assertStringContainsString("\r\x1b[1A\x1b[0J", $captured);
    }

    public function testMultiRowViewFramesMoveUpByTheirFullHeight(): void
    {
        // A prompt whose view() renders two rows — the pre-fix single-row
        // erase left a staircase of stale rows beneath every repaint.
        $prompt = new class {
            public bool $done = false;

            public function handleKey(string $key): self
            {
                if ($key === Key::CtrlC) {
                    $this->done = true;
                }
                return $this;
            }

            public function isSubmitted(): bool
            {
                return false;
            }

            public function isAborted(): bool
            {
                return $this->done;
            }

            public function view(): string
            {
                return "first\nsecond";
            }
        };

        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        // 'up' repaints a second frame (no state change needed — the pump
        // repaints after every event); ctrl_c ends the loop BEFORE a final
        // repaint, so the 2-row erase sequence is the last thing written.
        fwrite($pair[1], "\x1b[A" . "\x03");
        fclose($pair[1]);
        $output = fopen('php://memory', 'w+');

        $readline = new Readline(new StreamInputDriver($pair[0]));
        $readline->run($prompt, $output);

        fclose($pair[0]);
        rewind($output);
        $captured = (string) stream_get_contents($output);
        fclose($output);

        $this->assertStringContainsString("\r\x1b[2A\x1b[0J", $captured, 'two-row frame must erase with a 2-row upward move');
    }

    // =========================================================================
    // Filter-as-you-type (README promise) — raw chars through the pump
    // reach the selection filter.
    // =========================================================================

    public function testTypingNarrowsSelectionThroughRun(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], 'an' . "\r");
        fclose($pair[1]);

        $readline = new Readline(new StreamInputDriver($pair[0]));
        $prompt = SelectionPrompt::new('Fruit: ', ['Apple', 'Banana', 'Cherry']);
        /** @var SelectionPrompt $final */
        $final = $readline->run($prompt);

        fclose($pair[0]);
        $this->assertTrue($final->isSubmitted());
        $this->assertSame('Banana', $final->selectedValue(), 'typing "an" must filter to Banana');
    }

    public function testBackspaceHealsTheSelectionFilterThroughRun(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], 'an' . "\x7f" . "\x7f" . 'ap' . "\r");
        fclose($pair[1]);

        $readline = new Readline(new StreamInputDriver($pair[0]));
        /** @var SelectionPrompt $final */
        $final = $readline->run(SelectionPrompt::new('Fruit: ', ['Apple', 'Banana', 'Cherry']));

        fclose($pair[0]);
        $this->assertTrue($final->isSubmitted());
        $this->assertSame('Apple', $final->selectedValue());
    }

    // =========================================================================
    // EOF on the owned pump (the stdin path) — child probe lives in
    // ReadlineEofTest; the seam that makes it work is exercised here:
    // an INJECTED exhausted stream is indistinguishable, so it keeps
    // idling (bounded by stop()).
    // =========================================================================

    public function testInjectedDriverAtEofKeepsIdlingUntilStop(): void
    {
        // An exhausted socket pair is indistinguishable from a live-empty
        // one for an injected driver: the pump must keep polling (bounded
        // by the idle ladder) and exit only via stop(). The EOF exit is a
        // privilege of the stream the pump OWNS.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        fwrite($pair[1], 'z');
        fclose($pair[1]); // write end gone — reads now hit EOF after 'z'

        $sleeps = 0;
        $readline = new Readline(
            new StreamInputDriver($pair[0]),
            static function (int $us) use (&$sleeps, &$readline): void {
                $sleeps++;
                if ($sleeps >= 2) {
                    $readline->stop();
                }
            },
        );
        $final = $readline->run(TextPrompt::new('> '));
        fclose($pair[0]);

        $this->assertSame('z', $final->value(), 'bytes before the EOF must still be delivered');
        $this->assertGreaterThanOrEqual(2, $sleeps, 'idle ladder must run past the EOF');
        $this->assertFalse($final->isSubmitted());
        $this->assertFalse($final->isAborted());
    }
}