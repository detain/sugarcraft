<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tools\Edit\BlockAnchorMatcher;
use SugarCraft\Crush\Tools\Edit\EditMatch;
use SugarCraft\Crush\Tools\Edit\EditMatcher;

/**
 * Roadmap 3.I-1: the fuzzy matcher chain behind Edit, stage by stage.
 *
 * Every stage is driven with an input only it (and nothing stricter) can
 * match, and the assertions read the stage the chain REPORTS, the span it
 * chose in the file's own spelling, and the replacement it built — so a stage
 * that silently stopped running, or ran out of order, shows up as the wrong
 * name rather than as a green edit made for the wrong reason.
 */
final class EditMatcherChainTest extends TestCase
{
    public function testTheStagesRunStrictestFirst(): void
    {
        self::assertSame(
            ['exact', 'uniform-indent', 'line-trimmed', 'whitespace-normalised', 'block-anchor', 'unicode-normalised'],
            EditMatcher::new()->stages(),
        );
    }

    public function testExactWinsBeforeAnyLooserStage(): void
    {
        $match = self::match("foo();\n  foo();\n", 'foo();', 'bar();');

        self::assertSame(EditMatcher::STAGE_EXACT, $match->stage);
        self::assertSame(2, $match->count, 'exact reports every occurrence; replace_all is the caller\'s rule');
        self::assertNull($match->offset);
        self::assertNull($match->note);
    }

    public function testLineTrimmedTakesAPerLineIndentationMismatchAndPlacesNewAtTheFilesDepth(): void
    {
        $content = "function f()\n{\n    if (\$x) {\n            return 1;\n    }\n}\n";
        // Trailing spaces and a different, non-uniform indentation per line.
        $old = "if (\$x) {  \n    return 1;\n}";
        $new = "if (\$x) {\n    return 2;\n}";

        $match = self::match($content, $old, $new);

        self::assertSame(EditMatcher::STAGE_LINE_TRIMMED, $match->stage);
        self::assertSame("    if (\$x) {\n            return 1;\n    }", $match->oldString, 'the span is the file\'s own lines');
        self::assertSame("    if (\$x) {\n        return 2;\n    }", $match->newString);
        self::assertStringContainsString('line-trimmed match', (string) $match->note);
        self::assertStringContainsString('re-indented from no indentation to 4 spaces', (string) $match->note);
        self::assertSame(
            "function f()\n{\n    if (\$x) {\n        return 2;\n    }\n}\n",
            $match->applyTo($content),
        );
    }

    public function testACrlfFileKeepsItsLineEndings(): void
    {
        $content = "a\r\n  b  \r\nc\r\n";
        $match = self::match($content, "a\nb\nc", "a\nB\nc");

        self::assertSame(EditMatcher::STAGE_LINE_TRIMMED, $match->stage);
        self::assertSame("a\r\nB\r\nc\r\n", $match->applyTo($content));
    }

    public function testWhitespaceNormalisedMatchesARetypedRunInsideALine(): void
    {
        $content = "\$total = sum(\$a,   \$b) + 1;\n";

        $match = self::match($content, ' sum($a, $b) ', ' max($a, $b) ');

        self::assertSame(EditMatcher::STAGE_WHITESPACE, $match->stage);
        self::assertSame('sum($a,   $b)', $match->oldString);
        self::assertSame("\$total = max(\$a, \$b) + 1;\n", $match->applyTo($content), 'the run, not the line, and no stray spaces');
    }

    public function testWhitespaceNormalisedMatchesMultiLineBlocksWithCollapsedRuns(): void
    {
        $content = "x  =  1;\ny =\t2;\n";

        $match = self::match($content, "x = 1;\ny = 2;", "x = 3;\ny = 4;");

        self::assertSame(EditMatcher::STAGE_WHITESPACE, $match->stage);
        self::assertSame("x = 3;\ny = 4;\n", $match->applyTo($content));
    }

    public function testBlockAnchorFindsAParaphrasedMiddleBetweenExactEnds(): void
    {
        $content = implode("\n", [
            'function total(array $items): int',
            '{',
            '    $sum = 0;',
            '    foreach ($items as $item) {',
            '        $sum += $item->price;',
            '    }',
            '    return $sum;',
            '}',
            '',
        ]);
        $old = implode("\n", [
            'function total(array $items): int',
            '{',
            '    $sum = 0;',
            '    foreach ($items as $it) {',
            '        $sum += $it->price;',
            '    }',
            '    return $sum;',
            '}',
        ]);

        $match = self::match($content, $old, "function total(array \$items): int\n{\n    return 0;\n}");

        self::assertSame(EditMatcher::STAGE_BLOCK_ANCHOR, $match->stage);
        self::assertStringContainsString('$item->price', $match->oldString, 'the span is the file\'s text, not old_string');
        self::assertMatchesRegularExpression('/with the 6 line\(s\) between them \d+% alike/', (string) $match->note);
        self::assertSame("function total(array \$items): int\n{\n    return 0;\n}\n", $match->applyTo($content));
    }

    public function testBlockAnchorBelowTheThresholdIsNoMatch(): void
    {
        $content = "start\nalpha beta gamma\ndelta epsilon\nend\n";

        self::assertNull(EditMatcher::new()->match($content, "start\nnothing like it at all\nzzz qqq\nend", 'x'));
        self::assertGreaterThanOrEqual(0.65, BlockAnchorMatcher::THRESHOLD);
    }

    public function testBlockAnchorSimilarityChargesMissingLines(): void
    {
        self::assertSame(1.0, BlockAnchorMatcher::similarity([], []));
        self::assertSame(1.0, BlockAnchorMatcher::similarity(['a', 'b'], ['a', 'b']));
        self::assertSame(0.5, BlockAnchorMatcher::similarity(['a', 'b'], ['a']));
    }

    public function testUnicodeNormalisedMatchesSmartQuotesAndDashesInEitherDirection(): void
    {
        $content = "echo \u{201C}it\u{2019}s done\u{201D}; // a \u{2014} b\n";

        $match = self::match($content, 'echo "it\'s done"; // a - b', 'echo "ok";');
        self::assertSame(EditMatcher::STAGE_UNICODE, $match->stage);
        self::assertSame("echo \u{201C}it\u{2019}s done\u{201D}; // a \u{2014} b", $match->oldString, 'the span is the file\'s spelling');
        self::assertSame("echo \"ok\";\n", $match->applyTo($content));

        $reverse = self::match("say 'hi' -- now\n", "say \u{2018}hi\u{2019} --\u{00A0}now", 'x');
        self::assertSame(EditMatcher::STAGE_UNICODE, $reverse->stage);
        self::assertSame("say 'hi' -- now", $reverse->oldString);
    }

    public function testAUnicodeMatchNeverStartsOrEndsInsideAnExpandedCharacter(): void
    {
        // `…` folds to `...`; `..` lies inside that expansion and must not match.
        self::assertNull(EditMatcher::new()->match("wait\u{2026} done\n", 'wait.. done', 'x'));
        self::assertNull(EditMatcher::new()->match("a\u{2026}b\n", '..b', 'x'));
    }

    public function testAnAmbiguousFuzzyStageIsRefusedWithTheLineOfEveryCandidate(): void
    {
        $content = "  a();\n  b();\n    a();\n    b();\n";

        $match = self::match($content, " a();\n b();", " x();\n y();");

        self::assertTrue($match->isRefused());
        self::assertSame(2, $match->count);
        self::assertSame([1, 3], $match->lines);
        self::assertSame(EditMatcher::STAGE_UNIFORM_INDENT, $match->stage, 'the FIRST ambiguous stage is the one reported');
        self::assertStringContainsString('it matches 2 places (starting at lines 1, 3)', (string) $match->refusal);
        self::assertStringContainsString('never applied to more than one place, replace_all included', (string) $match->refusal);
    }

    public function testADisproportionateBlockIsRefused(): void
    {
        $old = implode("\n", ['begin', 'l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'end']);
        $content = implode("\n", ['begin', 'l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'l7', 'x1', 'x2', 'x3', 'end', '']);

        $match = self::match($content, $old, 'replacement');

        self::assertTrue($match->isRefused());
        self::assertSame(EditMatcher::STAGE_BLOCK_ANCHOR, $match->stage);
        self::assertStringContainsString('the only near match (lines 1-12) is 12 line(s)', (string) $match->refusal);
        self::assertStringContainsString('too different to apply without guessing', (string) $match->refusal);
    }

    public function testIsDisproportionateMatchJudgesLinesAndNonWhitespaceBytes(): void
    {
        self::assertFalse(EditMatcher::isDisproportionateMatch("a\nb\nc", "  a\n  b\n  c"));
        self::assertFalse(EditMatcher::isDisproportionateMatch("a\nb\nc\nd", "a\nb\nc\nd\ne\nf"), 'two extra lines is within the floor');
        self::assertTrue(EditMatcher::isDisproportionateMatch("a\nb\nc\nd", "a\nb\nc\nd\ne\nf\ng"));
        self::assertTrue(EditMatcher::isDisproportionateMatch('short', str_repeat('long text ', 20)));
        self::assertTrue(EditMatcher::isDisproportionateMatch(str_repeat('long text ', 20), 'short'));
    }

    public function testANewStringThatCannotBePlacedAtTheFilesDepthIsNotAMatch(): void
    {
        // b() sits shallower than the block old_string describes.
        self::assertNull(EditMatcher::new()->match("{\n    a();\n    z();\n}\n", "  a();\n  z();", "  a();\nb();"));
    }

    private static function match(string $content, string $old, string $new): EditMatch
    {
        $match = EditMatcher::new()->match($content, $old, $new);
        self::assertNotNull($match, 'no stage matched');

        return $match;
    }
}
