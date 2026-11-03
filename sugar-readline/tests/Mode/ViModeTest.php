<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\History\InMemoryHistory;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\TextPrompt;
use SugarCraft\Readline\UndoManager;

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
    // Uppercase A/I (were dead branches behind a strtolower)
    // =========================================================================

    public function testUpperAAppendsAtLineEndFromAnywhere(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape); // normal, cursor on 'c' (2)
        $prompt = $prompt->handleKey('0');         // line start
        $prompt = $prompt->handleKey('A');         // MUST jump to EOL and type there

        $this->assertSame('insert', $this->getViMode($prompt));
        $this->assertSame(3, $prompt->cursor());
        $prompt = $prompt->handleChar('d');
        $this->assertSame('abcd', $prompt->value());
    }

    public function testUpperIInsertsAtLineStartFromAnywhere(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape); // cursor on 'c' (2)
        $prompt = $prompt->handleKey('I');         // MUST jump to BOL and type there

        $this->assertSame('insert', $this->getViMode($prompt));
        $this->assertSame(0, $prompt->cursor());
        $prompt = $prompt->handleChar('X');
        $this->assertSame('Xabc', $prompt->value());
    }

    // =========================================================================
    // Normal-mode commands that used to be silently swallowed
    // =========================================================================

    public function testNormalEnterSubmits(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('l')->handleChar('s');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey(Key::Enter);

        $this->assertTrue($prompt->isSubmitted());
        $this->assertSame('ls', $prompt->value());
    }

    public function testNormalXDeletesCharUnderCursorIntoRegister(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape); // cursor on 'c'
        $prompt = $prompt->handleKey('x');

        $this->assertSame('ab', $prompt->value());
        $this->assertSame(2, $prompt->cursor());
        $this->assertSame('normal', $this->getViMode($prompt));
        $this->assertSame('c', $this->getModeFromPrompt($prompt)->register());
    }

    public function testNormalPPutsTheRegisterAsLiteralText(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('x');          // cut 'c'
        $prompt = $prompt->handleKey('0');          // back to start
        $prompt = $prompt->handleKey('p');          // put after cursor

        $this->assertSame('acb', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testNormalPUndoesTheLastEdit(): void
    {
        $prompt = TextPrompt::new('> ')
            ->withUndoManager(new UndoManager())
            ->handleChar('a')->handleChar('b')->handleChar('c'); // one typed run
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('u'); // vim undo: cancels the whole run

        $this->assertSame('', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testNormalJAndKWalkHistory(): void
    {
        $history = new InMemoryHistory();
        $history->push('one');
        $history->push('two'); // newest
        $prompt = TextPrompt::new('> ')->withHistory($history)->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape); // already normal — no-op path
        $prompt = $prompt->handleKey('k');         // HistoryUp → newest
        $this->assertSame('two', $prompt->value());
        $prompt = $prompt->handleKey('k');         // older
        $this->assertSame('one', $prompt->value());
        $prompt = $prompt->handleKey('j');         // newer
        $this->assertSame('two', $prompt->value());
    }

    public function testNormalSpaceMovesRightLikeL(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape); // cursor on 'c' (2)
        $prompt = $prompt->handleKey(' ');

        $this->assertSame(3, $prompt->cursor());
    }

    public function testNormalCtrlRStartsIncrementalSearchInAnyMode(): void
    {
        $history = new InMemoryHistory();
        $history->push('git status');
        $prompt = TextPrompt::new('> ')->withHistory($history)->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey(Key::CtrlR);

        $this->assertTrue($prompt->isSearching());
    }

    // =========================================================================
    // The unnamed register: yy / dd / diw populate it
    // =========================================================================

    public function testYyCopiesLineIntoRegister(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('t')->handleChar('e')->handleChar('s')->handleChar('t');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('y');
        $prompt = $prompt->handleKey('y');

        $this->assertSame('test', $this->getModeFromPrompt($prompt)->register());
        $this->assertSame('test', $prompt->value()); // yank does not cut
    }

    public function testDdCopiesThenCutsTheLine(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('h')->handleChar('i');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('d');
        $prompt = $prompt->handleKey('d');

        $this->assertSame('', $prompt->value());
        $this->assertSame('hi', $this->getModeFromPrompt($prompt)->register());
    }

    public function testDiwYanksTheDeletedWordIntoRegister(): void
    {
        $prompt = TextPrompt::new('> ')->handleChar('f')->handleChar('o')->handleChar('o')
            ->handleChar(' ')->handleChar('b')->handleChar('a')->handleChar('r');
        $prompt = $prompt->withMode(new ViMode());

        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('0');   // on 'f'
        $prompt = $prompt->handleKey('d');
        $prompt = $prompt->handleKey('i');
        $prompt = $prompt->handleKey('w');

        $this->assertSame(' bar', $prompt->value());
        $this->assertSame('foo', $this->getModeFromPrompt($prompt)->register());
    }

    public function testPastedTextInNormalModeIsLiteralNotCommands(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new ViMode());
        $prompt = $prompt->handleKey(Key::Escape); // normal mode

        $prompt = $prompt->insertPastedText("hi\x01there");

        $this->assertSame('hithere', $prompt->value()); // control byte dropped
        $this->assertFalse($prompt->isSubmitted());
        $this->assertSame('normal', $this->getViMode($prompt)); // stayed normal
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
