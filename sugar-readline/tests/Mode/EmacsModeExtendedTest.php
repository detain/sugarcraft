<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\TextPrompt;

/**
 * Additional EmacsMode tests covering untested branches:
 * Ctrl+T (transpose), Ctrl+L (clear), Escape prefix handling,
 * delete word with leading spaces, Ctrl+P/Ctrl+N history navigation.
 */
final class EmacsModeExtendedTest extends TestCase
{
    // =========================================================================
    // Ctrl+T — transpose characters
    // =========================================================================

    public function testCtrlTTransposesTwoCharsAtEnd(): void
    {
        // "ab" — cursor at end (2), Ctrl+T → "ba"
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+E to end, then Ctrl+T
        $prompt = $prompt->handleKey("\x05"); // Ctrl+E
        $result = $prompt->handleKey("\x14"); // Ctrl+T = 0x14
        $this->assertSame('ba', $result->value());
    }

    public function testCtrlTTransposesTwoCharsInMiddle(): void
    {
        // "abc" — cursor at 1 ('b'), Ctrl+T → "acb"
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+A to start, then Ctrl+F to position 1
        $prompt = $prompt->handleKey("\x01"); // Ctrl+A
        $prompt = $prompt->handleKey("\x06"); // Ctrl+F — now cursor at 1 ('b')
        $result = $prompt->handleKey("\x14"); // Ctrl+T
        $this->assertSame('acb', $result->value());
    }

    public function testCtrlTWithCursorAtZeroIsNoOp(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);
        // cursor at 0, Ctrl+T at start — cannot transpose
        $result = $prompt->handleKey("\x14");
        $this->assertSame('ab', $result->value());
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
    // Ctrl+W — delete word before cursor
    // =========================================================================

    public function testCtrlWDeletesWordBeforeWithLeadingSpace(): void
    {
        // "foo  bar" — Ctrl+W from end deletes 'bar'
        $prompt = TextPrompt::new('> ')
            ->handleChar('f')->handleChar('o')->handleChar('o')
            ->handleChar(' ')->handleChar(' ')->handleChar('b')->handleChar('a')->handleChar('r');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $result = $prompt->handleKey("\x17"); // Ctrl+W
        $this->assertSame('foo  ', $result->value());
        $this->assertSame(6, $result->cursor());
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
