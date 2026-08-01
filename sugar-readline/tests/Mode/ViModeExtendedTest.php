<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\TextPrompt;

/**
 * Additional ViMode tests covering untested branches:
 * 'a'/'A'/'I' insert-mode entry, 'b'/'w' word movement, 'cc' change line,
 * visual mode cursor movements, normal mode unknown keys.
 */
final class ViModeExtendedTest extends TestCase
{
    // =========================================================================
    // Insert mode entry via 'a' (append after cursor)
    // =========================================================================

    public function testLowerAAppendsAfterCurrentPosition(): void
    {
        // "abc" — Escape to normal (cursor 2), 'a' → move right then insert
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi)->handleKey(Key::Escape);

        $result = $prompt->handleKey('a');
        $this->assertSame('insert', $this->getViMode($result));
        // 'a' moves cursor right before entering insert mode
        $result = $result->handleChar('X');
        $this->assertSame('abcX', $result->value());
    }

    public function testUpperAAppendsAtEndOfLine(): void
    {
        // "abc" → Escape → normal, 'A' → end then insert
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi)->handleKey(Key::Escape);

        $result = $prompt->handleKey('A');
        $this->assertSame('insert', $this->getViMode($result));
        $result = $result->handleChar('X');
        $this->assertSame('abcX', $result->value());
    }

    public function testUpperIInsertsAtLineStart(): void
    {
        // "abc" → Escape → normal (cursor 2), 'I' → start then insert
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi)->handleKey(Key::Escape);

        $result = $prompt->handleKey('I');
        $this->assertSame('insert', $this->getViMode($result));
        $result = $result->handleChar('X');
        // 'I' moves cursor to line start (0), then insert mode
        $this->assertSame('Xabc', $result->value());
    }

    public function testLowerIInsertsAtCurrentPosition(): void
    {
        // "abc" → Escape → normal (cursor 2), 'l' → move right to 3, 'i' → insert at 3
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi)->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('l'); // move right to cursor 3 (past 'c')

        $result = $prompt->handleKey('i');
        $this->assertSame('insert', $this->getViMode($result));
        $result = $result->handleChar('X');
        $this->assertSame('abcX', $result->value());
    }

    // =========================================================================
    // 'b' / 'w' — word back / forward in normal mode
    // =========================================================================

    public function testBKeyMovesToPreviousWordStart(): void
    {
        // "foo bar baz" — position cursor after 'baz' (11), Escape, 'b' → cursor 4
        $prompt = $this->normalModeAt('foo bar baz', 11);
        $result = $prompt->handleKey('b');
        $this->assertSame(4, $result->cursor());
    }

    public function testWKeyMovesToNextWordStart(): void
    {
        // "foo bar baz" — cursor at 0, Escape, 'w' → cursor 4
        $prompt = $this->normalModeAt('foo bar baz', 0);
        $result = $prompt->handleKey('w');
        $this->assertSame(4, $result->cursor());
    }

    public function testBAtStartOfBufferIsNoOp(): void
    {
        $prompt = $this->normalModeAt('foo bar', 0);
        $result = $prompt->handleKey('b');
        $this->assertSame(0, $result->cursor());
    }

    public function testWAtEndOfBufferIsNoOp(): void
    {
        $prompt = $this->normalModeAt('foo bar', 7);
        $result = $prompt->handleKey('w');
        $this->assertSame(7, $result->cursor());
    }

    public function testBAndWWithUnicodeWords(): void
    {
        // "中文 中文" — cursor at end (5), Escape, 'b' → cursor 3
        $prompt = $this->normalModeAt('中文 中文', 5);
        $result = $prompt->handleKey('b');
        $this->assertSame(3, $result->cursor());
    }

    // =========================================================================
    // 'cc' — change whole line
    // =========================================================================

    public function testCcChangesWholeLineAndEntersInsertMode(): void
    {
        $prompt = $this->normalModeAt('hello world', 10);
        $result = $prompt->handleKey('c')->handleKey('c');
        $this->assertSame('', $result->value());
        $this->assertSame('insert', $this->getViMode($result));
    }

    public function testCcWithLeadingWhitespace(): void
    {
        $prompt = $this->normalModeAt('  hello', 7);
        $result = $prompt->handleKey('c')->handleKey('c');
        $this->assertSame('', $result->value());
        $this->assertSame('insert', $this->getViMode($result));
    }

    // =========================================================================
    // Visual mode — cursor movements
    // =========================================================================

    public function testVisualModeHMovement(): void
    {
        $prompt = $this->normalModeAt('abc', 0);
        $prompt = $prompt->handleKey('v');
        $this->assertSame('visual', $this->getViMode($prompt));

        $result = $prompt->handleKey('h');
        $this->assertSame('visual', $this->getViMode($result));
    }

    public function testVisualModeLMovement(): void
    {
        $prompt = $this->normalModeAt('abc', 0);
        $prompt = $prompt->handleKey('v');
        $result = $prompt->handleKey('l');
        $this->assertSame('visual', $this->getViMode($result));
    }

    public function testVisualModeBWordBackward(): void
    {
        $prompt = $this->normalModeAt('foo bar', 7);
        $prompt = $prompt->handleKey('v');
        $result = $prompt->handleKey('b');
        $this->assertSame('visual', $this->getViMode($result));
    }

    public function testVisualModeWWordForward(): void
    {
        $prompt = $this->normalModeAt('foo bar', 0);
        $prompt = $prompt->handleKey('v');
        $result = $prompt->handleKey('w');
        $this->assertSame('visual', $this->getViMode($result));
    }

    public function testVisualModeEscapeReturnsToNormal(): void
    {
        $prompt = $this->normalModeAt('abc', 0);
        $prompt = $prompt->handleKey('v');
        $this->assertSame('visual', $this->getViMode($prompt));

        $result = $prompt->handleKey(Key::Escape);
        $this->assertSame('normal', $this->getViMode($result));
    }

    public function testVisualModeDollarGoesToLineEnd(): void
    {
        $prompt = $this->normalModeAt('abc', 0);
        $prompt = $prompt->handleKey('v');
        $result = $prompt->handleKey('$');
        $this->assertSame('visual', $this->getViMode($result));
    }

    public function testVisualModeZeroGoesToLineStart(): void
    {
        $prompt = $this->normalModeAt('abc', 2);
        $prompt = $prompt->handleKey('v');
        $result = $prompt->handleKey('0');
        $this->assertSame('visual', $this->getViMode($result));
    }

    // =========================================================================
    // Normal mode — unknown keys are NoOp
    // =========================================================================

    public function testNormalModeUnknownKeyIsNoOp(): void
    {
        $prompt = $this->normalModeAt('abc', 1);
        $result = $prompt->handleKey('z'); // 'z' is not a vim command
        $this->assertSame('abc', $result->value());
        $this->assertSame('normal', $this->getViMode($result));
    }

    public function testNormalModeCtrlPMapsToHistoryUp(): void
    {
        $history = new \SugarCraft\Readline\History\InMemoryHistory();
        $history->push('prev cmd');
        $prompt = TextPrompt::new('> ')->withHistory($history)->handleChar('x');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi)->handleKey(Key::Escape);

        // Ctrl+P in normal mode = history up
        $result = $prompt->handleKey("\x10");
        $this->assertSame('prev cmd', $result->value());
    }

    public function testNormalModeCtrlNMapsToHistoryDown(): void
    {
        $history = new \SugarCraft\Readline\History\InMemoryHistory();
        $history->push('prev cmd');
        $prompt = TextPrompt::new('> ')->withHistory($history)->handleChar('x');
        $vi = new ViMode();
        // Navigate to history
        $prompt = $prompt->withMode($vi)->handleKey(Key::Escape)->handleKey("\x10");
        $this->assertSame('prev cmd', $prompt->value());

        // Ctrl+N goes back to live buffer
        $result = $prompt->handleKey("\x0e");
        $this->assertSame('x', $result->value());
    }

    // =========================================================================
    // Escape cancels pending motion
    // =========================================================================

    public function testEscapeCancelsPendingMotion(): void
    {
        $prompt = $this->normalModeAt('hello world', 5);
        // Press 'd' to enter pending motion
        $prompt = $prompt->handleKey('d');
        // Press Escape to cancel
        $result = $prompt->handleKey(Key::Escape);
        $this->assertSame('normal', $this->getViMode($result));
        $this->assertSame('hello world', $result->value());
    }

    // =========================================================================
    // Empty buffer edge cases
    // =========================================================================

    public function testEmptyBufferEscapeStaysInNormalMode(): void
    {
        $prompt = TextPrompt::new('> ')->withMode(new ViMode());
        $result = $prompt->handleKey(Key::Escape);
        $this->assertSame('normal', $this->getViMode($result));
        $this->assertSame('', $result->value());
    }

    public function testSingleCharBufferDollarAtZero(): void
    {
        // Single char "x" at cursor 0, '$' → cursor 0 (can't go past)
        $prompt = $this->normalModeAt('x', 0);
        $result = $prompt->handleKey('$');
        $this->assertSame(0, $result->cursor());
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function normalModeAt(string $text, int $cursor): TextPrompt
    {
        $prompt = TextPrompt::new('> ');
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $prompt = $prompt->handleChar($char);
        }
        $prompt = $prompt->withMode(new ViMode());
        $prompt = $prompt->handleKey(Key::Escape); // enter normal mode

        // '0' → line start, then 'l' × cursor
        $prompt = $prompt->handleKey('0');
        for ($i = 0; $i < $cursor; $i++) {
            $prompt = $prompt->handleKey('l');
        }
        $this->assertSame($cursor, $prompt->cursor());

        return $prompt;
    }

    private function getViMode(TextPrompt $prompt): string
    {
        $refl = new \ReflectionClass($prompt);
        $prop = $refl->getProperty('mode');
        $prop->setAccessible(true);
        /** @var ViMode $mode */
        $mode = $prop->getValue($prompt);
        return $mode->viMode();
    }
}
