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

        // ESC → normal mode (cursor pulled back onto 'c', vi semantics)
        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame(2, $prompt->cursor());

        // 'h' → move left
        $prompt = $prompt->handleKey('h');
        $this->assertSame(1, $prompt->cursor());
    }

    public function testNormalModeLKeyMovesCursorRight(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal (cursor pulled back onto 'c' at index 2)
        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame(2, $prompt->cursor());

        // 'l' → move right
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

    public function testNormalModeDollarLandsOnLastChar(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi);

        // ESC → normal, '0' → start, then '$' must land ON 'c' (index 2),
        // not past it — vi normal mode never rests after the last char.
        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('0');
        $prompt = $prompt->handleKey('$');
        $this->assertSame(2, $prompt->cursor());
    }

    public function testEscapePullsCursorBackFromLineEnd(): void
    {
        // Type "abc" (cursor sits after 'c' at 3); Escape must land ON 'c'.
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame(2, $prompt->cursor());
    }

    public function testEscapeSingleCharLandsOnIt(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame(0, $prompt->cursor());
    }

    public function testEscapeEmptyBufferStaysAtZero(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame(0, $prompt->cursor());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testEscapeMidBufferDoesNotMoveCursor(): void
    {
        // Cursor at 1 (between 'a' and 'b') — Escape must not pull back.
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')
            ->handleKey(Key::Left)->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $this->assertSame(1, $prompt->cursor());
    }

    public function testDollarOnSingleCharBufferLandsAtZero(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('$');
        $this->assertSame(0, $prompt->cursor());
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
