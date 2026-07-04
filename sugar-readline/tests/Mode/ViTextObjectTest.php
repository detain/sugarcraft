<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\TextPrompt;

/**
 * End-to-end vi text-object key sequences (ci" / di( / da{ / yiw ...)
 * driven through ViMode::handleKey, mirroring ViModeTest's style.
 */
final class ViTextObjectTest extends TestCase
{
    // =========================================================================
    // change (c) — deletes the object and enters insert mode
    // =========================================================================

    public function testCiQuoteChangesInsideQuotes(): void
    {
        // cursor on 'e' of hello (index 6)
        $prompt = $this->normalModeAt('say "hello" now', 6);

        $prompt = $this->keys($prompt, ['c', 'i', '"']);

        $this->assertSame('say "" now', $prompt->value());
        $this->assertSame(5, $prompt->cursor());
        $this->assertSame('insert', $this->getViMode($prompt));

        // typing lands between the quotes
        $prompt = $prompt->handleChar('X');
        $this->assertSame('say "X" now', $prompt->value());
    }

    public function testCiQuoteCursorBeforeQuotesJumpsForward(): void
    {
        $prompt = $this->normalModeAt('x "ab"', 0);

        $prompt = $this->keys($prompt, ['c', 'i', '"']);

        $this->assertSame('x ""', $prompt->value());
        $this->assertSame(3, $prompt->cursor());
        $this->assertSame('insert', $this->getViMode($prompt));
    }

    public function testCiWordChangesWordUnderCursor(): void
    {
        // cursor on 'a' of bar (index 5)
        $prompt = $this->normalModeAt('foo bar baz', 5);

        $prompt = $this->keys($prompt, ['c', 'i', 'w']);

        $this->assertSame('foo  baz', $prompt->value());
        $this->assertSame(4, $prompt->cursor());
        $this->assertSame('insert', $this->getViMode($prompt));
    }

    public function testCiParenOnEmptyParensEntersInsertBetween(): void
    {
        // fn() — cursor on ')' (index 3)
        $prompt = $this->normalModeAt('fn()', 3);

        $prompt = $this->keys($prompt, ['c', 'i', '(']);

        $this->assertSame('fn()', $prompt->value());
        $this->assertSame(3, $prompt->cursor());
        $this->assertSame('insert', $this->getViMode($prompt));
    }

    public function testCcChangesWholeLine(): void
    {
        $prompt = $this->normalModeAt('hello', 4);

        $prompt = $this->keys($prompt, ['c', 'c']);

        $this->assertSame('', $prompt->value());
        $this->assertSame('insert', $this->getViMode($prompt));
    }

    // =========================================================================
    // delete (d) — removes the object, stays in normal mode
    // =========================================================================

    public function testDiParenCursorOnClosingDelimiter(): void
    {
        // ESC leaves cursor on ')' (index 6) — on-delimiter still resolves
        $prompt = $this->normalModeAt('fn(arg)', 6);

        $prompt = $this->keys($prompt, ['d', 'i', '(']);

        $this->assertSame('fn()', $prompt->value());
        $this->assertSame(3, $prompt->cursor());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testDaBraceRemovesDelimiters(): void
    {
        // a {b} c — cursor on 'b' (index 3)
        $prompt = $this->normalModeAt('a {b} c', 3);

        $prompt = $this->keys($prompt, ['d', 'a', '{']);

        $this->assertSame('a  c', $prompt->value());
        $this->assertSame(2, $prompt->cursor());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testDawDeletesWordWithTrailingSpace(): void
    {
        $prompt = $this->normalModeAt('foo bar baz', 5);

        $prompt = $this->keys($prompt, ['d', 'a', 'w']);

        $this->assertSame('foo baz', $prompt->value());
        $this->assertSame(4, $prompt->cursor());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testDiNestedParensDeletesInnermost(): void
    {
        // a(b(c)d) — cursor on 'c' (index 4)
        $prompt = $this->normalModeAt('a(b(c)d)', 4);

        $prompt = $this->keys($prompt, ['d', 'i', '(']);

        $this->assertSame('a(b()d)', $prompt->value());
        $this->assertSame(4, $prompt->cursor());
    }

    public function testDaParenAtEndOfBufferClampsCursor(): void
    {
        // ab(cd) — deleting the trailing parens leaves cursor past end → clamp
        $prompt = $this->normalModeAt('ab(cd)', 4);

        $prompt = $this->keys($prompt, ['d', 'a', '(']);

        $this->assertSame('ab', $prompt->value());
        $this->assertSame(1, $prompt->cursor());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testDiQuoteMultibyteContent(): void
    {
        // ä "öü" x — cursor on 'ö' (index 3); indices are characters, not bytes
        $prompt = $this->normalModeAt('ä "öü" x', 3);

        $prompt = $this->keys($prompt, ['d', 'i', '"']);

        $this->assertSame('ä "" x', $prompt->value());
        $this->assertSame(3, $prompt->cursor());
    }

    // =========================================================================
    // yank (y) — buffer untouched, cursor moves to object start
    // =========================================================================

    public function testYiQuoteLeavesBufferMovesCursorToStart(): void
    {
        // x "ab" y — cursor on 'b' (index 4)
        $prompt = $this->normalModeAt('x "ab" y', 4);

        $prompt = $this->keys($prompt, ['y', 'i', '"']);

        $this->assertSame('x "ab" y', $prompt->value());
        $this->assertSame(3, $prompt->cursor());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testYiwLeavesBufferUnchanged(): void
    {
        $prompt = $this->normalModeAt('foo bar baz', 6);

        $prompt = $this->keys($prompt, ['y', 'i', 'w']);

        $this->assertSame('foo bar baz', $prompt->value());
        $this->assertSame(4, $prompt->cursor());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    // =========================================================================
    // Failure paths — vim beeps, we no-op and clear the pending sequence
    // =========================================================================

    public function testCursorOutsideParensIsNoOp(): void
    {
        // x (y) z — cursor on 'z' (index 6): outside the pair
        $prompt = $this->normalModeAt('x (y) z', 6);

        $prompt = $this->keys($prompt, ['c', 'i', '(']);

        $this->assertSame('x (y) z', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testUnmatchedDelimiterIsNoOp(): void
    {
        $prompt = $this->normalModeAt('open { only', 6);

        $prompt = $this->keys($prompt, ['d', 'i', '{']);

        $this->assertSame('open { only', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testNoQuotesOnLineIsNoOp(): void
    {
        $prompt = $this->normalModeAt('no quotes here', 3);

        $prompt = $this->keys($prompt, ['d', 'i', '"']);

        $this->assertSame('no quotes here', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testUnknownTargetCancelsSequence(): void
    {
        $prompt = $this->normalModeAt('foo bar', 5);

        $prompt = $this->keys($prompt, ['d', 'i', 'z']);
        $this->assertSame('foo bar', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));

        // pending state fully cleared: a fresh dd still deletes the line
        $prompt = $this->keys($prompt, ['d', 'd']);
        $this->assertSame('', $prompt->value());
    }

    public function testEscapeCancelsPendingTextObject(): void
    {
        $prompt = $this->normalModeAt('say "hello" now', 6);

        $prompt = $this->keys($prompt, ['c', 'i']);
        $prompt = $prompt->handleKey(Key::Escape);
        $prompt = $prompt->handleKey('"');

        $this->assertSame('say "hello" now', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testFailedSequenceDoesNotEnterInsertMode(): void
    {
        $prompt = $this->normalModeAt('plain text', 2);

        $prompt = $this->keys($prompt, ['c', 'i', '(']);

        $this->assertSame('normal', $this->getViMode($prompt));
        // typing a printable in normal mode must not insert
        $prompt = $prompt->handleKey('q');
        $this->assertSame('plain text', $prompt->value());
    }

    // =========================================================================
    // dd / yy still work alongside text objects
    // =========================================================================

    public function testDdStillDeletesLine(): void
    {
        $prompt = $this->normalModeAt('hello', 2);

        $prompt = $this->keys($prompt, ['d', 'd']);

        $this->assertSame('', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    public function testYyStillYanksWithoutChange(): void
    {
        $prompt = $this->normalModeAt('hello', 2);

        $prompt = $this->keys($prompt, ['y', 'y']);

        $this->assertSame('hello', $prompt->value());
        $this->assertSame('normal', $this->getViMode($prompt));
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Build a prompt containing $text, attach ViMode, enter normal mode,
     * and park the cursor at character index $cursor.
     */
    private function normalModeAt(string $text, int $cursor): TextPrompt
    {
        $prompt = TextPrompt::new('> ');
        foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
            $prompt = $prompt->handleChar($char);
        }
        $prompt = $prompt->withMode(new ViMode());

        // ESC → normal mode (cursor pulled onto the last character)
        $prompt = $prompt->handleKey(Key::Escape);

        // '0' → line start, then 'l' × cursor
        $prompt = $prompt->handleKey('0');
        for ($i = 0; $i < $cursor; $i++) {
            $prompt = $prompt->handleKey('l');
        }
        $this->assertSame($cursor, $prompt->cursor());

        return $prompt;
    }

    /** @param list<string> $keys */
    private function keys(TextPrompt $prompt, array $keys): TextPrompt
    {
        foreach ($keys as $key) {
            $prompt = $prompt->handleKey($key);
        }
        return $prompt;
    }

    private function getViMode(TextPrompt $prompt): string
    {
        $reflection = new \ReflectionClass($prompt);
        $prop = $reflection->getProperty('mode');
        $prop->setAccessible(true);
        /** @var ViMode $mode */
        $mode = $prop->getValue($prompt);
        return $mode->viMode();
    }
}
