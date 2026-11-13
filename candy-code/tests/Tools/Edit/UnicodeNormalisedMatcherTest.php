<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\EditMatcher;
use SugarCraft\Code\Tools\Edit\UnicodeNormalisedMatcher;

final class UnicodeNormalisedMatcherTest extends TestCase
{
    public function testName(): void
    {
        self::assertSame(EditMatcher::STAGE_UNICODE, (new UnicodeNormalisedMatcher())->name());
    }

    public function testCurlyQuotesInFileMatchStraightQuotesInOldString(): void
    {
        $content = "say \u{201C}hi\u{201D} it\u{2019}s fine\n";
        $found = (new UnicodeNormalisedMatcher())->find($content, 'say "hi" it\'s fine', 'say "bye"');

        self::assertCount(1, $found);
        self::assertSame("say \u{201C}hi\u{201D} it\u{2019}s fine", $found[0]->matchedIn($content));
        self::assertSame('say "bye"', $found[0]->newString);
        self::assertStringContainsString('normalising quotes, dashes, spaces', (string) $found[0]->detail);
    }

    public function testStraightQuotesInFileMatchCurlyInOldString(): void
    {
        $content = 'a "b" c';
        $found = (new UnicodeNormalisedMatcher())->find($content, "a \u{201C}b\u{201D} c", 'z');

        self::assertCount(1, $found);
        self::assertSame($content, $found[0]->matchedIn($content));
    }

    public function testDashesAndNbspAndEllipsis(): void
    {
        $content = "a\u{2014}b\u{00A0}c\u{2026}";

        $found = (new UnicodeNormalisedMatcher())->find($content, 'a-b c...', 'x');

        self::assertCount(1, $found);
        self::assertSame($content, $found[0]->matchedIn($content));
    }

    public function testCompatibilityFormsFoldWhenIntlIsAvailable(): void
    {
        if (!class_exists(\Normalizer::class)) {
            self::markTestSkipped('ext-intl required');
        }
        $content = "\u{FB01}le"; // fi ligature

        $found = (new UnicodeNormalisedMatcher())->find($content, 'file', 'x');

        self::assertCount(1, $found);
        self::assertSame($content, $found[0]->matchedIn($content));
    }

    public function testPureAsciiIsLeftToStricterStages(): void
    {
        self::assertSame([], (new UnicodeNormalisedMatcher())->find('abc abc', 'abc', 'x'));
    }

    public function testEmptyNormalisedNeedleMatchesNothing(): void
    {
        self::assertSame([], (new UnicodeNormalisedMatcher())->find("caf\u{E9}", '', 'x'));
    }

    public function testInvalidUtf8IsRefused(): void
    {
        self::assertSame([], (new UnicodeNormalisedMatcher())->find("\xFF\xFEabc", 'abc', 'x'));
        self::assertSame([], (new UnicodeNormalisedMatcher())->find("abc \u{2019}", "\xFFabc", 'x'));
    }

    public function testMatchMustAlignToWholeSourceCharacters(): void
    {
        // The ellipsis folds to three dots; a needle of two dots would end mid-character.
        self::assertSame([], (new UnicodeNormalisedMatcher())->find("a\u{2026}", 'a..', 'x'));
    }

    public function testMultipleOccurrencesWithMultibyteOffsetsStayByteCorrect(): void
    {
        $content = "\u{E9}\u{E9} \u{2019}x \u{2019}x";
        $found = (new UnicodeNormalisedMatcher())->find($content, "'x", 'y');

        self::assertCount(2, $found);
        foreach ($found as $c) {
            self::assertSame("\u{2019}x", $c->matchedIn($content));
        }
    }

    public function testAsciiNeedleInsideNonAsciiContentMatchesByByteOffset(): void
    {
        $content = "\u{4E16}\u{754C} hello \u{4E16}\u{754C}";
        $found = (new UnicodeNormalisedMatcher())->find($content, 'hello', 'bye');

        self::assertCount(1, $found);
        self::assertSame('hello', $found[0]->matchedIn($content));
    }
}
