<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Vim;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Vim\TextObject;
use SugarCraft\Forms\Vim\TextObjectScope;

final class TextObjectTest extends TestCase
{
    // =========================================================================
    // Target detection
    // =========================================================================

    #[DataProvider('targetKeys')]
    public function testIsTargetAcceptsSupportedKeys(string $key): void
    {
        $this->assertTrue(TextObject::isTarget($key));
    }

    /** @return array<string, array{string}> */
    public static function targetKeys(): array
    {
        return [
            'double quote'  => ['"'],
            'single quote'  => ["'"],
            'backtick'      => ['`'],
            'open paren'    => ['('],
            'close paren'   => [')'],
            'paren alias b' => ['b'],
            'open bracket'  => ['['],
            'close bracket' => [']'],
            'open brace'    => ['{'],
            'close brace'   => ['}'],
            'brace alias B' => ['B'],
            'open angle'    => ['<'],
            'close angle'   => ['>'],
            'word'          => ['w'],
        ];
    }

    #[DataProvider('nonTargetKeys')]
    public function testIsTargetRejectsUnsupportedKeys(string $key): void
    {
        $this->assertFalse(TextObject::isTarget($key));
    }

    /** @return array<string, array{string}> */
    public static function nonTargetKeys(): array
    {
        return [
            'letter z'  => ['z'],
            'letter W'  => ['W'],
            'digit'     => ['1'],
            'space'     => [' '],
            'escape'    => ['esc'],
            'paragraph' => ['p'],
        ];
    }

    // =========================================================================
    // Quotes — inner / around
    // =========================================================================

    public function testInnerDoubleQuoteCursorInside(): void
    {
        // say "hello" now — cursor on the first 'l' (index 6)
        $range = TextObject::resolve('say "hello" now', 6, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(5, $range->start);
        $this->assertSame(10, $range->end);
    }

    public function testAroundDoubleQuoteIncludesDelimiters(): void
    {
        $range = TextObject::resolve('say "hello" now', 6, TextObjectScope::Around, '"');
        $this->assertNotNull($range);
        $this->assertSame(4, $range->start);
        $this->assertSame(11, $range->end);
    }

    public function testInnerSingleQuote(): void
    {
        // it's → no; use: a 'bc' d — cursor on 'b' (index 3)
        $range = TextObject::resolve("a 'bc' d", 3, TextObjectScope::Inner, "'");
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(5, $range->end);
    }

    public function testInnerBacktick(): void
    {
        $range = TextObject::resolve('run `ls -la` here', 6, TextObjectScope::Inner, '`');
        $this->assertNotNull($range);
        $this->assertSame(5, $range->start);
        $this->assertSame(11, $range->end);
    }

    public function testQuoteCursorOnOpeningDelimiter(): void
    {
        // Cursor ON the opening quote (index 4) selects that pair
        $range = TextObject::resolve('say "hello" now', 4, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(5, $range->start);
        $this->assertSame(10, $range->end);
    }

    public function testQuoteCursorOnClosingDelimiter(): void
    {
        // Closing quote sits at index 10
        $range = TextObject::resolve('say "hello" now', 10, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(5, $range->start);
        $this->assertSame(10, $range->end);
    }

    public function testQuoteCursorBeforePairJumpsForward(): void
    {
        // vim: ci" with cursor before the quoted region operates on the next pair
        $range = TextObject::resolve('say "hello" now', 0, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(5, $range->start);
        $this->assertSame(10, $range->end);
    }

    public function testQuoteCursorAfterLastPairFails(): void
    {
        $this->assertNull(TextObject::resolve('say "hello" now', 13, TextObjectScope::Inner, '"'));
    }

    public function testQuoteSecondPairSelectedWhenCursorInIt(): void
    {
        // "ab" x "cd" — cursor on 'c' (index 8)
        $range = TextObject::resolve('"ab" x "cd"', 8, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(8, $range->start);
        $this->assertSame(10, $range->end);
    }

    public function testQuoteBetweenPairsSelectsNextPair(): void
    {
        // cursor on the ' x ' gap (index 5) → vim operates on the second pair
        $range = TextObject::resolve('"ab" x "cd"', 5, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(8, $range->start);
        $this->assertSame(10, $range->end);
    }

    public function testUnmatchedQuoteFails(): void
    {
        // Single quote char on the line — no pair
        $this->assertNull(TextObject::resolve('say "hello now', 6, TextObjectScope::Inner, '"'));
    }

    public function testOddTrailingQuoteIgnored(): void
    {
        // Three quotes: first two pair up; the third is unmatched
        $range = TextObject::resolve('"ab" c "d', 2, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(1, $range->start);
        $this->assertSame(3, $range->end);
        // Cursor past the pair, inside the unmatched region → fails
        $this->assertNull(TextObject::resolve('"ab" c "d', 8, TextObjectScope::Inner, '"'));
    }

    public function testEmptyQuotesInnerIsEmptyRange(): void
    {
        // ci" on "" succeeds with an empty range (insert between the quotes)
        $range = TextObject::resolve('x "" y', 3, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(3, $range->end);
    }

    // =========================================================================
    // Brackets — inner / around, aliases, nesting
    // =========================================================================

    public function testInnerParens(): void
    {
        // fn(arg) — cursor on 'a' (index 3)
        $range = TextObject::resolve('fn(arg)', 3, TextObjectScope::Inner, '(');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(6, $range->end);
    }

    public function testAroundParensIncludesDelimiters(): void
    {
        $range = TextObject::resolve('fn(arg)', 3, TextObjectScope::Around, '(');
        $this->assertNotNull($range);
        $this->assertSame(2, $range->start);
        $this->assertSame(7, $range->end);
    }

    #[DataProvider('parenTargetAliases')]
    public function testParenTargetAliasesResolveIdentically(string $target): void
    {
        $range = TextObject::resolve('fn(arg)', 3, TextObjectScope::Inner, $target);
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(6, $range->end);
    }

    /** @return array<string, array{string}> */
    public static function parenTargetAliases(): array
    {
        return ['open' => ['('], 'close' => [')'], 'alias b' => ['b']];
    }

    public function testInnerBrackets(): void
    {
        $range = TextObject::resolve('a[10] = 1', 2, TextObjectScope::Inner, ']');
        $this->assertNotNull($range);
        $this->assertSame(2, $range->start);
        $this->assertSame(4, $range->end);
    }

    #[DataProvider('braceTargetAliases')]
    public function testInnerBraces(string $target): void
    {
        // ${var} — cursor on 'v' (index 2)
        $range = TextObject::resolve('${var}', 2, TextObjectScope::Inner, $target);
        $this->assertNotNull($range);
        $this->assertSame(2, $range->start);
        $this->assertSame(5, $range->end);
    }

    /** @return array<string, array{string}> */
    public static function braceTargetAliases(): array
    {
        return ['open' => ['{'], 'close' => ['}'], 'alias B' => ['B']];
    }

    public function testInnerAngleBrackets(): void
    {
        $range = TextObject::resolve('List<int> x', 5, TextObjectScope::Inner, '<');
        $this->assertNotNull($range);
        $this->assertSame(5, $range->start);
        $this->assertSame(8, $range->end);
    }

    public function testNestedParensInnermostWins(): void
    {
        // a(b(c)d) — cursor on 'c' (index 4) → inner pair (c), not outer
        $range = TextObject::resolve('a(b(c)d)', 4, TextObjectScope::Inner, '(');
        $this->assertNotNull($range);
        $this->assertSame(4, $range->start);
        $this->assertSame(5, $range->end);
    }

    public function testNestedParensOuterWhenCursorBetween(): void
    {
        // a(b(c)d) — cursor on 'd' (index 6): inner pair is closed, outer contains it
        $range = TextObject::resolve('a(b(c)d)', 6, TextObjectScope::Inner, '(');
        $this->assertNotNull($range);
        $this->assertSame(2, $range->start);
        $this->assertSame(7, $range->end);
    }

    public function testBracketCursorOnOpener(): void
    {
        $range = TextObject::resolve('fn(arg)', 2, TextObjectScope::Inner, '(');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(6, $range->end);
    }

    public function testBracketCursorOnCloser(): void
    {
        $range = TextObject::resolve('fn(arg)', 6, TextObjectScope::Inner, '(');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(6, $range->end);
    }

    public function testBracketCursorOnCloserOfNestedPair(): void
    {
        // a(b(c)d) — cursor ON the inner ')' (index 5) → the inner pair
        $range = TextObject::resolve('a(b(c)d)', 5, TextObjectScope::Inner, '(');
        $this->assertNotNull($range);
        $this->assertSame(4, $range->start);
        $this->assertSame(5, $range->end);
    }

    public function testBracketCursorOutsideEveryPairFails(): void
    {
        $this->assertNull(TextObject::resolve('fn(arg) x', 8, TextObjectScope::Inner, '('));
        $this->assertNull(TextObject::resolve('x fn(arg)', 0, TextObjectScope::Inner, '('));
    }

    public function testUnmatchedOpenBracketFails(): void
    {
        $this->assertNull(TextObject::resolve('fn(arg', 4, TextObjectScope::Inner, '('));
    }

    public function testUnmatchedCloseBracketFails(): void
    {
        $this->assertNull(TextObject::resolve('arg) x', 1, TextObjectScope::Inner, '('));
    }

    public function testEmptyParensInnerIsEmptyRange(): void
    {
        $range = TextObject::resolve('fn()', 2, TextObjectScope::Inner, '(');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(3, $range->end);
    }

    // =========================================================================
    // Word objects — iw / aw
    // =========================================================================

    public function testInnerWordMidWord(): void
    {
        // foo bar baz — cursor on 'a' of bar (index 5)
        $range = TextObject::resolve('foo bar baz', 5, TextObjectScope::Inner, 'w');
        $this->assertNotNull($range);
        $this->assertSame(4, $range->start);
        $this->assertSame(7, $range->end);
    }

    public function testInnerWordOnWhitespaceSelectsWhitespaceRun(): void
    {
        // vim: iw on whitespace selects the whitespace run
        $range = TextObject::resolve('foo   bar', 4, TextObjectScope::Inner, 'w');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(6, $range->end);
    }

    public function testInnerWordOnPunctuationSelectsPunctuationRun(): void
    {
        // a +*= b — cursor on '*' (index 3): punct run is +*= (indices 2..5)
        $range = TextObject::resolve('a +*= b', 3, TextObjectScope::Inner, 'w');
        $this->assertNotNull($range);
        $this->assertSame(2, $range->start);
        $this->assertSame(5, $range->end);
    }

    public function testInnerWordUnderscoreIsWordChar(): void
    {
        $range = TextObject::resolve('my_var = 1', 3, TextObjectScope::Inner, 'w');
        $this->assertNotNull($range);
        $this->assertSame(0, $range->start);
        $this->assertSame(6, $range->end);
    }

    public function testAroundWordTakesTrailingWhitespace(): void
    {
        // foo bar baz — aw on bar takes "bar " (indices 4..8)
        $range = TextObject::resolve('foo bar baz', 5, TextObjectScope::Around, 'w');
        $this->assertNotNull($range);
        $this->assertSame(4, $range->start);
        $this->assertSame(8, $range->end);
    }

    public function testAroundWordAtEndOfLineTakesLeadingWhitespace(): void
    {
        // foo bar — aw on bar (no trailing space) takes " bar" (indices 3..7)
        $range = TextObject::resolve('foo bar', 5, TextObjectScope::Around, 'w');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(7, $range->end);
    }

    public function testAroundWordSingleWordNoWhitespace(): void
    {
        $range = TextObject::resolve('foo', 1, TextObjectScope::Around, 'w');
        $this->assertNotNull($range);
        $this->assertSame(0, $range->start);
        $this->assertSame(3, $range->end);
    }

    public function testAroundWordOnWhitespaceTakesFollowingWord(): void
    {
        // vim: aw on whitespace = whitespace + following word
        $range = TextObject::resolve('foo   bar', 4, TextObjectScope::Around, 'w');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(9, $range->end);
    }

    public function testAroundWordOnTrailingWhitespaceTakesPrecedingWord(): void
    {
        // whitespace ends the line → absorb the preceding run instead
        $range = TextObject::resolve('foo   ', 4, TextObjectScope::Around, 'w');
        $this->assertNotNull($range);
        $this->assertSame(0, $range->start);
        $this->assertSame(6, $range->end);
    }

    // =========================================================================
    // Multibyte safety
    // =========================================================================

    public function testMultibyteInnerQuote(): void
    {
        // héllo wörld inside quotes — indices are character counts, not bytes
        $range = TextObject::resolve('x "héllo wörld" y', 5, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(14, $range->end);
        $this->assertSame('héllo wörld', mb_substr('x "héllo wörld" y', $range->start, $range->end - $range->start, 'UTF-8'));
    }

    public function testMultibyteInnerWord(): void
    {
        // über cool — cursor on 'b' (index 1); ü is a single character
        $range = TextObject::resolve('über cool', 1, TextObjectScope::Inner, 'w');
        $this->assertNotNull($range);
        $this->assertSame(0, $range->start);
        $this->assertSame(4, $range->end);
    }

    public function testMultibyteBracketsWithCjkContent(): void
    {
        // (日本語) — cursor inside
        $range = TextObject::resolve('a (日本語) b', 4, TextObjectScope::Inner, '(');
        $this->assertNotNull($range);
        $this->assertSame(3, $range->start);
        $this->assertSame(6, $range->end);
    }

    // =========================================================================
    // Degenerate inputs
    // =========================================================================

    public function testEmptyBufferFails(): void
    {
        $this->assertNull(TextObject::resolve('', 0, TextObjectScope::Inner, '"'));
        $this->assertNull(TextObject::resolve('', 0, TextObjectScope::Around, 'w'));
    }

    public function testUnknownTargetFails(): void
    {
        $this->assertNull(TextObject::resolve('foo "bar"', 6, TextObjectScope::Inner, 'z'));
    }

    public function testCursorPastEndClampsToLastChar(): void
    {
        // Normal-mode cursors never sit past the end, but be defensive
        $range = TextObject::resolve('"ab"', 99, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(1, $range->start);
        $this->assertSame(3, $range->end);
    }

    public function testNegativeCursorClampsToStart(): void
    {
        $range = TextObject::resolve('"ab"', -5, TextObjectScope::Inner, '"');
        $this->assertNotNull($range);
        $this->assertSame(1, $range->start);
        $this->assertSame(3, $range->end);
    }
}
