<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\EditMatcher;
use SugarCraft\Code\Tools\Edit\WhitespaceNormalisedMatcher;

final class WhitespaceNormalisedMatcherTest extends TestCase
{
    public function testName(): void
    {
        self::assertSame(EditMatcher::STAGE_WHITESPACE, (new WhitespaceNormalisedMatcher())->name());
    }

    public function testSingleLineCollapsesRunsOfSpacesAndTabs(): void
    {
        $content = "x = foo(a,\t b)  + 1;\n";
        $found = (new WhitespaceNormalisedMatcher())->find($content, 'foo(a, b) +', 'bar() +');

        self::assertCount(1, $found);
        self::assertSame("foo(a,\t b)  +", $found[0]->matchedIn($content));
    }

    public function testSingleLineMatchSpansTheWholeWhitespaceRun(): void
    {
        $content = "let   a \t =  1";
        $found = (new WhitespaceNormalisedMatcher())->find($content, 'let a = 1', 'let a = 2');

        self::assertCount(1, $found);
        self::assertSame($content, $found[0]->matchedIn($content));
        self::assertSame('let a = 2', $found[0]->newString);
        self::assertStringContainsString('treated as one space', (string) $found[0]->detail);
    }

    public function testSingleLineMatchesEveryOccurrence(): void
    {
        self::assertCount(2, (new WhitespaceNormalisedMatcher())->find("a  b\na b\n", 'a b', 'c'));
    }

    public function testSingleWordIsLeftToStricterStages(): void
    {
        self::assertSame([], (new WhitespaceNormalisedMatcher())->find('foo', 'foo', 'bar'));
        self::assertSame([], (new WhitespaceNormalisedMatcher())->find('foo', '   ', 'bar'));
    }

    public function testLeadingAndTrailingWhitespaceOfNeedleIsNotRequoted(): void
    {
        $content = 'a   b';
        $found = (new WhitespaceNormalisedMatcher())->find($content, '  a b ', '  A B ');

        self::assertCount(1, $found);
        self::assertSame('A B', $found[0]->newString);
    }

    public function testMultiLineDelegatesToLineWindows(): void
    {
        $content = "    foo   bar\n    baz\n";
        $found = (new WhitespaceNormalisedMatcher())->find($content, "foo bar\nbaz\n", "foo bar\nqux\n");

        self::assertCount(1, $found);
        self::assertSame($content, $found[0]->matchedIn($content));
        self::assertSame("    foo bar\n    qux\n", $found[0]->newString);
    }

    public function testNoMatch(): void
    {
        self::assertSame([], (new WhitespaceNormalisedMatcher())->find('a b c', 'x y', 'z'));
    }
}
