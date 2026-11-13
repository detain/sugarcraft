<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\EditMatcher;
use SugarCraft\Code\Tools\Edit\LineTrimmedMatcher;

final class LineTrimmedMatcherTest extends TestCase
{
    public function testName(): void
    {
        self::assertSame(EditMatcher::STAGE_LINE_TRIMMED, (new LineTrimmedMatcher())->name());
    }

    public function testIgnoresTrailingWhitespaceOnFileLines(): void
    {
        $content = "if (x) {   \n    go();\t\n}\n";
        $found = (new LineTrimmedMatcher())->find($content, "if (x) {\n    go();\n}\n", "if (y) {\n    stop();\n}\n");

        self::assertCount(1, $found);
        self::assertSame($content, $found[0]->matchedIn($content));
        self::assertStringContainsString('leading and trailing whitespace ignored', (string) $found[0]->detail);
    }

    public function testIgnoresLeadingWhitespaceDifference(): void
    {
        $content = "\t\tfoo();\n\t\tbar();\n";
        $found = (new LineTrimmedMatcher())->find($content, "foo();\nbar();\n", "baz();\n");

        self::assertCount(1, $found);
        self::assertSame("\t\tbaz();\n", $found[0]->newString);
    }

    public function testNoMatchOnDifferentTokens(): void
    {
        self::assertSame([], (new LineTrimmedMatcher())->find("a b\n", "a  c\n", 'x'));
    }

    public function testInnerWhitespaceStillMatters(): void
    {
        self::assertSame([], (new LineTrimmedMatcher())->find("a  b\n", "a b\n", 'x'));
    }
}
