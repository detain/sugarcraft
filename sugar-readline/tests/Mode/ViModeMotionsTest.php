<?php

declare(strict_types=1);

namespace SugarCraft\Readline\Tests\Mode;

use PHPUnit\Framework\TestCase;
use SugarCraft\Readline\Key;
use SugarCraft\Readline\Mode\ViMode;
use SugarCraft\Readline\TextPrompt;

/**
 * Grammar pins for the uppercase motions D and V, and for x's cursor after
 * deleting the last character (findings 075, 076, 087, 071-dead-code leg).
 *
 * Before the cure, sugar-forms' VimKeyHandler lowercased every printable
 * key before dispatch (its documented byte-stable design), and the mode
 * only threaded $originalKey for EnterInsertMode — so D collapsed to a
 * pending 'd' and V to plain visual. The A/I rescue is the precedent;
 * these pins keep it from regressing.
 */
final class ViModeMotionsTest extends TestCase
{
    private function normalModePrompt(string $text): TextPrompt
    {
        $prompt = TextPrompt::new('> ')->withMode(new ViMode());
        $prompt = $prompt->handleKey(Key::Escape);           // normal mode
        $prompt = $prompt->insertPastedText($text);          // buffer + caret at EOL
        return $prompt->handleKey(Key::Escape);              // back to normal, caret steps one left
    }

    private function moveToColumn(TextPrompt $prompt, int $column): TextPrompt
    {
        while ($prompt->cursor() > $column) {
            $prompt = $prompt->handleKey(Key::Left);
        }
        while ($prompt->cursor() < $column) {
            $prompt = $prompt->handleKey(Key::Right);
        }
        return $prompt;
    }

    // =========================================================================
    // Finding 076 — x at the last character must not strand the cursor
    // =========================================================================

    public function testXAtLastCharacterClampsCursorOntoTheNewLastCharacter(): void
    {
        $prompt = $this->normalModePrompt('abc');   // cursor 2, on 'c'
        $prompt = $prompt->handleKey('x');

        $this->assertSame('ab', $prompt->value());
        $this->assertSame(1, $prompt->cursor(), 'x deleted the final character; the caret belongs on the new last character, not past end-of-line');
    }

    public function testXDeletingTheOnlyCharacterLeavesAnEmptyBufferAtZero(): void
    {
        $prompt = $this->normalModePrompt('a');     // cursor 0, on 'a'
        $prompt = $prompt->handleKey('x');

        $this->assertSame('', $prompt->value());
        $this->assertSame(0, $prompt->cursor());
    }

    public function testXMidLineStillEditsTheCharacterUnderTheCursor(): void
    {
        $prompt = $this->moveToColumn($this->normalModePrompt('abc'), 1); // on 'b'
        $prompt = $prompt->handleKey('x');

        $this->assertSame('ac', $prompt->value());
        $this->assertSame(1, $prompt->cursor(), 'mid-line x keeps the caret on the character that slid into place');
    }

    // =========================================================================
    // Finding 075 — D deletes to end-of-line now, not as a pending motion
    // =========================================================================

    public function testDFromMidWordDeletesToTheEndOfTheLineAndClamps(): void
    {
        $prompt = $this->moveToColumn($this->normalModePrompt('hello there'), 6); // on 't'
        $prompt = $prompt->handleKey('D');

        $this->assertSame('hello ', $prompt->value());
        $this->assertSame(5, $prompt->cursor(), 'D leaves the caret on the last surviving character, like x');
    }

    public function testDFromColumnZeroClearsTheLine(): void
    {
        $prompt = $this->moveToColumn($this->normalModePrompt('abc'), 0);
        $prompt = $prompt->handleKey('D');

        $this->assertSame('', $prompt->value());
        $this->assertSame(0, $prompt->cursor());
    }

    public function testDDoesNotArmAPendingMotion(): void
    {
        $prompt = $this->moveToColumn($this->normalModePrompt('hello there'), 6);
        $prompt = $prompt->handleKey('D');
        // If D had armed a pending lowercase d (the old collapse), this x
        // would have been swallowed as a motion instead of deleting a char.
        $prompt = $prompt->handleKey('x');

        $this->assertSame('hello', $prompt->value(), 'D resolves immediately; the next x behaves like x');
    }

    public function testDIntoTheRegisterRoundTripsThroughPut(): void
    {
        $prompt = $this->moveToColumn($this->normalModePrompt('hello there'), 6);
        $prompt = $prompt->handleKey('D');
        $this->assertSame('hello ', $prompt->value());

        $prompt = $prompt->handleKey('p');
        $this->assertSame('hello there', $prompt->value(), 'D yanks the deleted suffix into the unnamed register');
    }

    public function testLowercaseDStillArmsThePendingMotion(): void
    {
        $prompt = $this->moveToColumn($this->normalModePrompt('abc'), 1);
        $prompt = $prompt->handleKey('d');
        $prompt = $prompt->handleKey('d');   // dd — whole line

        $this->assertSame('', $prompt->value(), 'the lowercase pending grammar is untouched');
    }

    // =========================================================================
    // Finding 087 — V opens the line; v keeps the cursor
    // =========================================================================

    public function testVOpensVisualModeAtColumnZero(): void
    {
        $prompt = $this->normalModePrompt('line text');   // cursor 8
        $prompt = $prompt->handleKey('V');

        $this->assertSame('visual', $this->viModeOf($prompt));
        $this->assertSame(0, $prompt->cursor(), 'the line-wise spelling anchors at the start of the line');
    }

    public function testLowercaseVIsUnbound(): void
    {
        // Re-pinned by S-52 (G9 default): plain v no longer opens the
        // motion-only visual scaffold; V keeps the line-wise spelling.
        $prompt = $this->moveToColumn($this->normalModePrompt('line text'), 3);
        $prompt = $prompt->handleKey('v');

        $this->assertSame('normal', $this->viModeOf($prompt), 'plain v is unbound: no half-alive visual state');
        $this->assertSame(3, $prompt->cursor(), 'the unbound chord must not move anything');
        $this->assertSame('line text', $prompt->value());
    }

    // =========================================================================
    // Finding 071 (dead-code leg) — the unread $nextMode local is gone
    // =========================================================================

    public function testConsumeActionCarriesNoDeadLocal(): void
    {
        $src = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Mode/ViMode.php');
        $this->assertMatchesRegularExpression(
            '/private function consumeAction\(.*?\n    \}/s',
            $src,
        );
        \preg_match('/private function consumeAction\(.*?\n    \}/s', $src, $body);

        $this->assertStringNotContainsString(
            '$nextMode',
            (string) $body[0],
            'consumeAction pre-seeded a $nextMode local no arm ever read; the match arms thread mode through withViMode()',
        );
    }

    private function viModeOf(TextPrompt $prompt): string
    {
        $reflection = new \ReflectionClass($prompt);
        $prop = $reflection->getProperty('mode');
        $prop->setAccessible(true);
        /** @var ViMode $mode */
        $mode = $prop->getValue($prompt);
        return $mode->viMode()->value;
    }
}
