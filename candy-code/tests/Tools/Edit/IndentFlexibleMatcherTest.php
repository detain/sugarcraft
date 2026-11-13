<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\EditMatcher;
use SugarCraft\Code\Tools\Edit\IndentFlexibleMatcher;

final class IndentFlexibleMatcherTest extends TestCase
{
    public function testName(): void
    {
        self::assertSame(EditMatcher::STAGE_UNIFORM_INDENT, (new IndentFlexibleMatcher())->name());
    }

    public function testMatchesBlockIndentedDeeperAndReindentsReplacement(): void
    {
        $content = "class A {\n    function f() {\n        return 1;\n    }\n}\n";
        $old = "function f() {\n    return 1;\n}\n";
        $new = "function f() {\n    return 2;\n}\n";

        $found = (new IndentFlexibleMatcher())->find($content, $old, $new);

        self::assertCount(1, $found);
        self::assertSame("    function f() {\n        return 1;\n    }\n", $found[0]->matchedIn($content));
        self::assertSame("    function f() {\n        return 2;\n    }\n", $found[0]->newString);
        self::assertStringContainsString('re-indenting it from no indentation to 4 spaces', (string) $found[0]->detail);
    }

    public function testTabIndentedFileDoesNotMatchSpaceIndentedNeedle(): void
    {
        $content = "\tif (a) {\n\t\tb();\n\t}\n";
        $found = (new IndentFlexibleMatcher())->find($content, "  if (a) {\n    b();\n  }\n", "  if (a) {\n    c();\n  }\n");

        // Tab-indented file vs space-indented needle: the indent group must repeat verbatim, so no candidate.
        self::assertSame([], $found);
    }

    public function testInnerRelativeIndentMustAgree(): void
    {
        $content = "    a\n      b\n";

        self::assertCount(1, (new IndentFlexibleMatcher())->find($content, "a\n  b\n", "a\n  c\n"));
        self::assertSame([], (new IndentFlexibleMatcher())->find($content, "a\n    b\n", 'x'));
    }

    public function testBlankLinesInNeedleMatchBlankOrWhitespaceOnlyLines(): void
    {
        $content = "  a\n   \n  b\n";
        $found = (new IndentFlexibleMatcher())->find($content, "a\n\nb\n", "a\n\nz\n");

        self::assertCount(1, $found);
        self::assertSame("  a\n\n  z\n", $found[0]->newString);
    }

    public function testAllBlankNeedleMatchesNothing(): void
    {
        self::assertSame([], (new IndentFlexibleMatcher())->find("a\n", "  \n  ", 'x'));
    }

    public function testDigitLeadingBodyDoesNotConfuseBackreference(): void
    {
        $content = "  a\n  12\n";

        self::assertCount(1, (new IndentFlexibleMatcher())->find($content, "a\n12\n", "a\n13\n"));
    }

    public function testRegexMetacharactersAreLiteral(): void
    {
        $content = "  x = a.b(c)[0];\n";

        self::assertCount(1, (new IndentFlexibleMatcher())->find($content, "x = a.b(c)[0];\n", "y;\n"));
        self::assertSame([], (new IndentFlexibleMatcher())->find("  x = aXb(c)[0];\n", "x = a.b(c)[0];\n", 'y'));
    }

    public function testNewStringNotSharingOldIndentRejectsCandidate(): void
    {
        $content = "    a\n";

        // old's common indent is two spaces, new has a line indented less than that
        self::assertSame([], (new IndentFlexibleMatcher())->find($content, "  a\n", "b\n"));
    }

    public function testEveryOccurrenceIsReported(): void
    {
        $content = "  q\n\n    q\n";

        self::assertCount(2, (new IndentFlexibleMatcher())->find($content, "q\n", "r\n"));
    }
}
