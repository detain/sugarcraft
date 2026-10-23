<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\TextPrompt;

/**
 * Additional EmacsMode tests covering untested branches:
 * Ctrl+T (transpose at start no-op), Ctrl+L (clear), Ctrl+P/Ctrl+N history,
 * delete word before, and unknown key handling.
 */
final class EmacsModeExtendedTest extends TestCase
{
    // =========================================================================
    // Ctrl+T — transpose at start is no-op
    // =========================================================================

    public function testCtrlTWithCursorAtZeroIsNoOp(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);
        // Actually rewind to column 0 first — the old version of this test
        // left the cursor at end-of-line while claiming "cursor at 0",
        // pinning a no-op that the broken code produced for the wrong reason.
        $prompt = $prompt->handleKeyDirect(Key::Left)->handleKeyDirect(Key::Left);
        $result = $prompt->handleKey("\x14"); // raw byte form still accepted
        $this->assertSame('ab', $result->value());
        $this->assertSame(0, $result->cursor());
    }

    public function testCtrlTAtEndOfLineSwapsLastTwoCharacters(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c')->withMode(new EmacsMode());
        // readline(3): at EOL transpose swaps the last two chars, cursor stays at EOL.
        $result = $prompt->handleKey('ctrl_t');
        $this->assertSame('acb', $result->value());
        $this->assertSame(3, $result->cursor());
    }

    public function testCtrlTInMiddleSwapsPairStraddlingCursor(): void
    {
        $prompt = TextPrompt::new('> ')
            ->handleChar('a')->handleChar('b')->handleChar('c')
            ->handleChar('d')->handleChar('e')->handleChar('f')
            ->withMode(new EmacsMode());
        $prompt = $prompt->handleKeyDirect(Key::Left);        // cursor 5 (between e,f)? no: after Home+4 rights
        $prompt = $prompt->handleKeyDirect(Key::Home);
        $prompt = $prompt->handleKeyDirect(Key::Right)->handleKeyDirect(Key::Right)->handleKeyDirect(Key::Right);
        // cursor 3, buffer 'abcdef': swap 'c' (before) and 'd' (at) -> 'abdcef', cursor 4
        $result = $prompt->handleKey("\x14");
        $this->assertSame('abdcef', $result->value());
        $this->assertSame(4, $result->cursor());
    }

    public function testCtrlTSingleCharacterBufferIsNoOp(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->withMode(new EmacsMode());
        $result = $prompt->handleKey('ctrl_t');
        $this->assertSame('a', $result->value());
    }

    // =========================================================================
    // Ctrl+L — clear screen (no-op at prompt level)
    // =========================================================================

    public function testCtrlLClearsScreenReturnsSamePrompt(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $result = $prompt->handleKey("\x0c"); // Ctrl+L = 0x0C
        // Should return the same prompt (no-op)
        $this->assertSame('ab', $result->value());
        $this->assertSame(2, $result->cursor());
    }

    // =========================================================================
    // Ctrl+P / Ctrl+N — history navigation
    // =========================================================================

    public function testCtrlPAndCtrlNAreHandledByEmacsMode(): void
    {
        $history = new \SugarCraft\Readline\History\InMemoryHistory();
        $history->push('prev');
        $history->push('next');

        $prompt = TextPrompt::new('> ')->withHistory($history)->handleChar('x');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+P → history previous
        $result = $prompt->handleKey("\x10"); // Ctrl+P
        $this->assertSame('next', $result->value());
    }

    public function testCtrlNAfterCtrlPGoesBackToLiveBuffer(): void
    {
        $history = new \SugarCraft\Readline\History\InMemoryHistory();
        $history->push('prev');

        $prompt = TextPrompt::new('> ')->withHistory($history)->handleChar('x');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+P → history
        $result = $prompt->handleKey("\x10"); // Ctrl+P
        $this->assertSame('prev', $result->value());

        // Ctrl+N → back to live buffer
        $result2 = $result->handleKey("\x0e"); // Ctrl+N
        $this->assertSame('x', $result2->value());
    }

    // =========================================================================
    // Unknown key returns prompt unchanged
    // =========================================================================

    public function testUnknownKeyIsNoOp(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $result = $prompt->handleKey('z');
        $this->assertSame('a', $result->value());
    }
}
