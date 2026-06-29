<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\TextPrompt;

final class ViModeTest extends TestCase
{
    // =========================================================================
    // Mode identity
    // =========================================================================

    public function testNameIsVi(): void
    {
        $vi = new ViMode();
        $this->assertSame('vi', $vi->name());
    }

    public function testStartsInInsertMode(): void
    {
        $vi = new ViMode();
        $this->assertSame('insert', $vi->viMode());
    }

    // =========================================================================
    // Mode switching
    // =========================================================================

    public function testEscapeSwitchesToNormalMode(): void
    {
        $prompt = TextPrompt::new('> ');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // Escape enters normal mode
        $result = $prompt->handleKey(Key::Escape);
        $mode = $this->getModeFromPrompt($result);
        $this->assertSame('normal', $mode->viMode());
    }

    public function testNormalIAEntersInsertMode(): void
    {
        $prompt = TextPrompt::new('> ');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal, then 'i' → insert
        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('i');
        $mode = $this->getModeFromPrompt($prompt);
        $this->assertSame('insert', $mode->viMode());
    }

    // =========================================================================
    // Normal mode cursor movement
    // =========================================================================

    public function testNormalModeHKeyMovesCursorLeft(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal mode
        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame(3, $prompt->cursor());

        // 'h' → move left
        $prompt = $prompt->handleKey('h');
        $this->assertSame(2, $prompt->cursor());
    }

    public function testNormalModeLKeyMovesCursorRight(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal
        $prompt = $prompt->handleKey(Key::Escape);

        // 'l' → move right (should be at end, so no change)
        $prompt = $prompt->handleKey('l');
        $this->assertSame(3, $prompt->cursor());
    }

    public function testNormalModeZeroGoesToLineStart(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal, then '0' → line start
        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('0');
        $this->assertSame(0, $prompt->cursor());
    }

    public function testNormalModeDollarGoesToLineEnd(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal, then '$' → line end
        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('$');
        $this->assertSame(3, $prompt->cursor());
    }

    // =========================================================================
    // Insert mode delegates to TextPrompt
    // =========================================================================

    public function testInsertModeHandlesCharacterInput(): void
    {
        $prompt = TextPrompt::new('> ');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // In insert mode, typing should work
        $prompt = $prompt->handleChar('x');
        $this->assertSame('x', $prompt->value());

        // Escape enters normal mode
        $prompt = $prompt->handleKey(Key::Escape);
        $mode = $this->getModeFromPrompt($prompt);
        $this->assertSame('normal', $mode->viMode());
    }

    public function testInsertModeHandlesBackspace(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        $prompt = $prompt->handleKey(Key::Backspace);
        $this->assertSame('a', $prompt->value());
        $this->assertSame(1, $prompt->cursor());
    }

    public function testInsertModeHandlesArrowKeys(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        $prompt = $prompt->handleKey(Key::Left);
        $this->assertSame(2, $prompt->cursor());
    }

    // =========================================================================
    // Step 4 + Step 12 — Vi dd/yy/visual/word-motion
    // =========================================================================

    /**
     * Test that dd (delete line) leaves vi in NORMAL mode, not INSERT.
     * Regression test for Step 4 fix.
     */
    public function testDdLeavesNormalMode(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('h')->handleChar('e')->handleChar('l')->handleChar('l')->handleChar('o');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal mode
        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame('normal', $this->getViMode($prompt));

        // d → pending motion
        $prompt = $prompt->handleKey('d');
        // d again → delete line, should stay in normal mode
        $prompt = $prompt->handleKey('d');

        $this->assertSame('normal', $this->getViMode($prompt));
        $this->assertSame('', $prompt->value());
    }

    /**
     * Test that yy (yank line) leaves vi in NORMAL mode, not INSERT.
     * Regression test for Step 4 fix.
     */
    public function testYyLeavesNormalMode(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('t')->handleChar('e')->handleChar('s')->handleChar('t');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal mode
        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame('normal', $this->getViMode($prompt));

        // y → pending motion
        $prompt = $prompt->handleKey('y');
        // y again → yank line, should stay in normal mode
        $prompt = $prompt->handleKey('y');

        $this->assertSame('normal', $this->getViMode($prompt));
        $this->assertSame('test', $prompt->value()); // buffer unchanged by yank
    }

    /**
     * Test that v enters visual mode from normal mode.
     */
    public function testVEntersVisualMode(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('h')->handleChar('e')->handleChar('l')->handleChar('l')->handleChar('o');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal mode
        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame('normal', $this->getViMode($prompt));

        // v → visual mode
        $prompt = $prompt->handleKey('v');
        $this->assertSame('visual', $this->getViMode($prompt));
    }

    // =========================================================================
    // Helper
    // =========================================================================

    private function getModeFromPrompt(TextPrompt $prompt): ViMode
    {
        $reflection = new \ReflectionClass($prompt);
        $prop = $reflection->getProperty('mode');
        $prop->setAccessible(true);
        return $prop->getValue($prompt);
    }

    private function getViMode(TextPrompt $prompt): string
    {
        return $this->getModeFromPrompt($prompt)->viMode();
    }
}
