<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\EmacsMode;
use SugarCraft\Readline\TextPrompt;

final class EmacsModeTest extends TestCase
{
    // =========================================================================
    // Mode identity
    // =========================================================================

    public function testNameIsEmacs(): void
    {
        $emacs = new EmacsMode();
        $this->assertSame('emacs', $emacs->name());
    }

    // =========================================================================
    // Ctrl+A / Ctrl+E — line start / end
    // =========================================================================

    public function testCtrlAandCtrlEgoToLineStartAndEnd(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+E → end
        $prompt = $prompt->handleKey("\x05"); // Ctrl+E = 0x05
        $this->assertSame(3, $prompt->cursor());

        // Ctrl+A → start
        $prompt = $prompt->handleKey("\x01"); // Ctrl+A = 0x01
        $this->assertSame(0, $prompt->cursor());
    }

    // =========================================================================
    // Ctrl+B / Ctrl+F — left / right
    // =========================================================================

    public function testCtrlBAndCtrlFMoveCursorLeftAndRight(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        // Ctrl+B → left
        $prompt = $prompt->handleKey("\x02"); // Ctrl+B = 0x02
        $this->assertSame(2, $prompt->cursor());

        // Ctrl+F → right
        $prompt = $prompt->handleKey("\x06"); // Ctrl+F = 0x06
        $this->assertSame(3, $prompt->cursor());
    }

    // =========================================================================
    // Standard keys still work (delegate to TextPrompt)
    // =========================================================================

    public function testEnterSubmits(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $prompt = $prompt->handleKey(Key::Enter);
        $this->assertTrue($prompt->isSubmitted());
    }

    public function testCharacterInputWorks(): void
    {
        $prompt = TextPrompt::new('> ');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $prompt = $prompt->handleChar('x');
        $this->assertSame('x', $prompt->value());
    }

    public function testBackspaceWorks(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $prompt = $prompt->handleKey(Key::Backspace);
        $this->assertSame('a', $prompt->value());
    }

    // =========================================================================
    // Tab completion still works
    // =========================================================================

    public function testTabCompletionWorks(): void
    {
        $prompt = TextPrompt::new('> ')->withCompletions(['banana', 'mango'])->handleChar('b');
        $emacs = new EmacsMode();
        $prompt = $prompt->withMode($emacs);

        $prompt = $prompt->handleKey(Key::Tab);
        $this->assertSame('banana', $prompt->value());
    }

    // =========================================================================
    // Immutability
    // =========================================================================

    public function testEmacsModeIsImmutable(): void
    {
        $a = TextPrompt::new('> ');
        $emacs = new EmacsMode();
        $b = $a->withMode($emacs);

        // Original prompt has no mode, cloned has emacs mode
        $this->assertNotSame($a, $b);
    }
}
