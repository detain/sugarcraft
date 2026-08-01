<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\TextPrompt;

/**
 * Additional ViMode tests covering untested branches:
 * 'a'/'A' insert-mode entry, 'cc' change line,
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

    public function testLowerIInsertsAtCurrentPosition(): void
    {
        // "abc" — Escape to normal (cursor 2), 'l' to move to 3, 'i' → insert at 3
        $prompt = TextPrompt::new('> ')->handleChar('a')->handleChar('b')->handleChar('c');
        $vi = new ViMode();
        $prompt = $prompt->withMode($vi)->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('l'); // move right to cursor 3

        $result = $prompt->handleKey('i');
        $this->assertSame('insert', $this->getViMode($result));
        $result = $result->handleChar('X');
        $this->assertSame('abcX', $result->value());
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
