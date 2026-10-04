<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Lint;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Lint\LintReport;

/**
 * The model-visible text of one lint (step 3.E): Aider's `## Running:` /
 * `█`-marked excerpt format, its line extraction and its bounds.
 *
 * @see LintReport
 */
final class LintReportTest extends TestCase
{
    public function testAPassingLintRendersNothing(): void
    {
        $this->assertSame('', self::report(0, 'No syntax errors detected')->render());
        $this->assertTrue(self::report(0, '')->passed());
    }

    public function testDistantLinesAreSeparatedByAGapMarkerAndPluralised(): void
    {
        $source = implode("\n", array_map(static fn (int $n): string => "line {$n}", range(1, 30))) . "\n";
        $report = self::report(1, "a.py:2: first\na.py:20: second", $source);

        $this->assertSame([2, 20], $report->lines());
        $this->assertSame(
            "# Fix any errors below, if possible.\n\n## Running: lint 'a.py'\n\na.py:2: first\na.py:20: second\n"
            . "\n## See relevant lines below marked with █.\n\na.py:\n"
            . " 1│line 1\n 2█line 2\n 3│line 3\n 4│line 4\n 5│line 5\n⋮...\n"
            . "17│line 17\n18│line 18\n19│line 19\n20█line 20\n21│line 21\n22│line 22\n23│line 23\n⋮...\n",
            $report->render(),
        );
    }

    public function testLinesAreReadInBothSpellingsDedupedAndBoundedByTheFile(): void
    {
        $source = str_repeat("x\n", 50);
        $report = self::report(255, "PHP Parse error: oops in a.py on line 7\n/abs/a.py:7:1: again\na.py:99: past the end\nother.py:3: someone else", $source);

        $this->assertSame([7], $report->lines());
    }

    public function testAtMostTenLinesAreMarked(): void
    {
        $output = implode("\n", array_map(static fn (int $n): string => "a.py:{$n}: e", range(1, 15)));

        $this->assertCount(LintReport::MAX_MARKED_LINES, self::report(1, $output, str_repeat("x\n", 20))->lines());
    }

    public function testASilentFailureSaysSoAndALongOutputIsCutBeforeTheExcerpt(): void
    {
        $this->assertStringContainsString('(the linter exited 2 and printed nothing)', self::report(2, '')->render());

        $long = self::report(1, 'a.py:1: ' . str_repeat('y', 10000), "x\n")->render();
        $this->assertStringContainsString('[lint output truncated: 4096 of 10008 bytes shown]', $long);
        $this->assertStringEndsWith("a.py:\n1█x\n", $long, 'the marked line survives a verbose linter');
    }

    public function testSourceLinesAreMadeSafeAndClipped(): void
    {
        $render = self::report(1, 'a.py:1: e', "bad\x1b[31m\xff" . str_repeat('z', 300) . "\n")->render();

        $this->assertStringNotContainsString("\x1b", $render);
        $this->assertTrue(mb_check_encoding($render, 'UTF-8'));
        $this->assertStringContainsString(str_repeat('z', 10) . '…', $render);
    }

    private static function report(int $exit, string $output, string $source = "x\n"): LintReport
    {
        return new LintReport('a.py', "lint 'a.py'", $exit, $output, false, 30.0, $source);
    }
}
