<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\UndoManager;

/**
 * Additional EmacsMode tests covering untested branches:
 * Alt+B/F/D (word movement), Ctrl+T (transpose), Ctrl+L (clear),
 * Escape prefix handling, word-forward/back, delete word, transpose chars.
 */
final class EmacsModeExtendedTest extends TestCase
{
    // =========================================================================
    // Alt+B / Alt+F — word backward / forward
    // =========================================================================

    public function testAltB MovesCursorOneWordBackward(): void
    {
        // "hello world" at cursor 11 (end), Alt+B → cursor at 6 (start of 'world')
        $prompt = TextPrompt::new('> ')
            ->handleChar('h')->handleChar('e')->handleChar('l')->handleChar('l')->handleChar('o')
            ->handleChar(' ')
            ->handleChar('w')->handleChar('o')->handleChar('r')->handleChar('l')->handleChar('d');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs)->handleKey(Key::Escape); // enter alt-prefix mode

        // Alt+B — move word backward
        $result = $prompt->handleKey(Key::Escape)->handleKey('b');
        $this->assertSame(6, $result->cursor());
    }

    public function testAltF MovesCursorOneWordForward(): void
    {
        // "hello world" at cursor 0, Alt+F → cursor at 6 (start of 'world')
        $prompt = TextPrompt::new('> ')
            ->handleChar('h')->handleChar('e')->handleChar('l')->handleChar('l')->handleChar('o')
            ->handleChar(' ')
            ->handleChar('w')->handleChar('o')->handleChar('r')->handleChar('l')->handleChar('d');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs)->handleKey(Key::Escape);

        // Alt+F — move word forward from start
        $result = $prompt->handleKey(Key::Escape)->handleKey('f');
        $this->assertSame(6, $result->cursor());
    }

    public function testAltFAtEndOfBufferIsNoOp(): void
    {
        // "ab cd" at cursor 0, move to end, Alt+F at end
        $prompt = TextPrompt::new('> ')
            ->handleChar('a')->handleChar('b')->handleChar(' ')->handleChar('c')->handleChar('d');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+E to end, then Alt+F
        $prompt = $prompt->handleKey("\x05"); // Ctrl+E
        $result = $prompt->handleKey(Key::Escape)->handleKey('f');
        $this->assertSame(5, $result->cursor()); // unchanged
    }

    public function testAltBAtStartOfBufferIsNoOp(): void
    {
        $prompt = TextPrompt::new('> ')
            ->handleChar('a')->handleChar('b')->handleChar(' ')->handleChar('c')->handleChar('d');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Already at start, Alt+B
        $result = $prompt->handleKey(Key::Escape)->handleKey('b');
        $this->assertSame(0, $result->cursor()); // unchanged
    }

    public function testAltD DeletesWordAfterCursor(): void
    {
        // "hello world" with cursor at 6 (start of 'world'), Alt+D → deletes 'world'
        $prompt = TextPrompt::new('> ')
            ->handleChar('h')->handleChar('e')->handleChar('l')->handleChar('l')->handleChar('o')
            ->handleChar(' ')
            ->handleChar('w')->handleChar('o')->handleChar('r')->handleChar('l')->handleChar('d');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+A to start, then right 6 times to 'w', then Alt+D
        $prompt = $prompt->handleKey("\x01"); // Ctrl+A
        for ($i = 0; $i < 6; $i++) {
            $prompt = $prompt->handleKey("\x06"); // Ctrl+F
        }
        // Now at 'w' (cursor 6), Alt+D
        $result = $prompt->handleKey(Key::Escape)->handleKey('d');
        $this->assertSame('hello ', $result->value());
    }

    public function testAltDAtEndOfBufferIsNoOp(): void
    {
        $prompt = TextPrompt::new('> ')
            ->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $result = $prompt->handleKey(Key::Escape)->handleKey('d');
        $this->assertSame('ab', $result->value()); // no-op
    }

    public function testUnknownAltKeyIsNoOp(): void
    {
        // Alt+Z — not handled
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $result = $prompt->handleKey(Key::Escape)->handleKey('z');
        $this->assertSame('ab', $result->value()); // unchanged
    }

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
        // "abc" — cursor at 2 ('b'), Ctrl+T → "acb"
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+A to start, then Ctrl+F to position 1
        $prompt = $prompt->handleKey("\x01"); // Ctrl+A
        $prompt = $prompt->handleKey("\x06"); // Ctrl+F — now cursor at 1 ('b')
        $result = $prompt->handleKey("\x14"); // Ctrl+T
        $this->assertSame('acb', $result->value());
    }

    public function testCtrlTWithSingleCharBufferIsNoOp(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $result = $prompt->handleKey("\x14"); // Ctrl+T
        $this->assertSame('a', $result->value()); // no-op
    }

    public function testCtrlTWithCursorAtZeroIsNoOp(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);
        // cursor at 0, Ctrl+T at start
        $result = $prompt->handleKey("\x14");
        $this->assertSame('ab', $result->value()); // no-op
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
        // Should return the same prompt (no-op), just keep the mode
        $this->assertSame('ab', $result->value());
        $this->assertSame(2, $result->cursor());
    }

    // =========================================================================
    // Escape prefix state management
    // =========================================================================

    public function testAltPrefixTwiceResetsAndUsesSecondAltKey(): void
    {
        // Escape twice → still in alt-prefix mode for the second key
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Press Escape once to enter alt-prefix, then press Escape again
        // This should clear the prefix and wait for the next real key
        $result = $prompt->handleKey(Key::Escape);
        // Second Escape while in alt-prefix mode
        $result2 = $result->handleKey(Key::Escape);
        // The prompt should not crash or change unexpectedly
        $this->assertSame('ab', $result2->value());
    }

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

        // Ctrl+N → history next
        $result2 = $result->handleKey("\x0e"); // Ctrl+N
        $this->assertSame('x', $result2->value());
    }

    // =========================================================================
    // Edge: delete word before at various positions
    // =========================================================================

    public function testCtrlWDeletesWordBeforeWithLeadingSpace(): void
    {
        // "foo  bar" with cursor at end → Ctrl+W deletes 'bar', leaves 'foo '
        $prompt = TextPrompt::new('> ')
            ->handleChar('f')->handleChar('o')->handleChar('o')
            ->handleChar(' ')->handleChar(' ')->handleChar('b')->handleChar('a')->handleChar('r');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $result = $prompt->handleKey("\x17"); // Ctrl+W
        $this->assertSame('foo  ', $result->value());
        $this->assertSame(6, $result->cursor());
    }

    public function testCtrlWWithOnlySpacesBeforeCursorIsNoOp(): void
    {
        // "   x" with cursor at 4 → Ctrl+W should delete only spaces before x? No,
        // word detection: spaces and word chars. Here we have only spaces.
        $prompt = TextPrompt::new('> ')
            ->handleChar(' ')->handleChar(' ')->handleChar('x');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Cursor at 3, there are only spaces before cursor, no word
        $result = $prompt->handleKey("\x17"); // Ctrl+W
        // Since there are only spaces before cursor, start stays at 0, no deletion
        $this->assertSame('   x', $result->value());
    }

    // =========================================================================
    // Immutability: withAltPrefix when already in that state
    // =========================================================================

    public function testEmacsModeWithAltPrefixSameStateReturnsSame(): void
    {
        $emacs = new EmacsMode();

        $refl = new \ReflectionClass($emacs);
        $method = $refl->getMethod('withAltPrefix');
        $method->setAccessible(true);

        $result = $method->invoke($emacs, false);
        // Should return same instance since state is already false
        $this->assertSame($emacs, $result);
    }
}
