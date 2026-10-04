<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Compaction;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Compaction\FilesTouched;
use SugarCraft\Crush\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Crush\Host\CompactionService;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 2.5: the holistic state block a compaction leaves — its fixed
 * headings, the audit that fills what a model left out, the merge with the
 * previous block, and the 16k bound.
 */
final class StateSummaryTemplateTest extends TestCase
{
    public function testARenderedBlockCarriesEveryHeadingInOrderAndEndsWithTheContinueLine(): void
    {
        $text = StateSummaryTemplate::new()->withSection('Goal', 'ship 2.5')->render();
        $lines = explode("\n", $text);

        $this->assertSame(StateSummaryTemplate::HEADER, $lines[0]);
        $this->assertSame(StateSummaryTemplate::CONTINUE_LINE, $lines[count($lines) - 1]);
        $headings = array_values(array_map(
            static fn (string $line): string => substr($line, 3),
            array_filter($lines, static fn (string $line): bool => str_starts_with($line, '## ')),
        ));
        $this->assertSame(StateSummaryTemplate::HEADINGS, $headings);
        $this->assertStringContainsString("## Goal\nship 2.5\n## Constraints\n" . StateSummaryTemplate::EMPTY, $text);
    }

    public function testParseReadsBackWhatRenderWroteAndKeepsSubHeadingsInsideTheirSection(): void
    {
        $block = StateSummaryTemplate::new()
            ->withSection('Goal', 'fix the login bug')
            ->withSection('Progress', "### Done\n- reproduced\n### In progress\n- patching\n### Blocked\n- none");

        $parsed = StateSummaryTemplate::parse($block->render());

        $this->assertSame('fix the login bug', $parsed->section('Goal'));
        $this->assertSame("### Done\n- reproduced\n### In progress\n- patching\n### Blocked\n- none", $parsed->section('Progress'));
        $this->assertSame('', $parsed->section('Constraints'), 'an EMPTY marker reads back as an empty section');
        $this->assertSame([], $parsed->missingHeadings(), 'a rendered block writes every heading');
        $this->assertStringNotContainsString(StateSummaryTemplate::CONTINUE_LINE, $parsed->section('Latest unresolved user request'));
    }

    public function testAnUnknownLevelTwoHeadingIsContentOfTheSectionAboveIt(): void
    {
        $parsed = StateSummaryTemplate::parse("## Goal\nfirst line\n## Not a heading we know\nsecond line\n## next step:\ndo it");

        $this->assertSame("first line\n## Not a heading we know\nsecond line", $parsed->section('Goal'));
        $this->assertSame('do it', $parsed->section('Next step'), 'headings match case-insensitively, a trailing colon allowed');
    }

    public function testTheAuditNamesTheModelHeadingsABlockNeverWrote(): void
    {
        $parsed = StateSummaryTemplate::parse("## Goal\ng\n## Progress\nnone\n## Next step\nn");

        $this->assertSame(
            ['Constraints', 'Key decisions', 'Current work', 'Pending tasks', 'Errors and fixes'],
            $parsed->missingHeadings(),
            'a heading written as "none" counts as written; the derived headings are never audited',
        );
    }

    public function testFilledFromFillsMissingAndEmptyHeadingsAndAlwaysTakesTheDerivedOnes(): void
    {
        $model = StateSummaryTemplate::parse(
            "## Goal\nmodel goal\n## Next step\nnone\n## Files modified\n- invented.php\n## Latest unresolved user request\nparaphrased",
        );
        $fallback = StateSummaryTemplate::new()
            ->withSection('Goal', 'heuristic goal')
            ->withSection('Next step', 'heuristic next')
            ->withSection('Constraints', 'heuristic constraint')
            ->withFiles(FilesTouched::new()->withModified('src/Real.php'))
            ->withSection('Latest unresolved user request', 'the user verbatim');

        $filled = $model->filledFrom($fallback);

        $this->assertSame('model goal', $filled->section('Goal'), 'what the model wrote stands');
        $this->assertSame('heuristic next', $filled->section('Next step'), 'an empty section is filled');
        $this->assertSame('heuristic constraint', $filled->section('Constraints'), 'a missing heading is filled');
        $this->assertSame('- src/Real.php', $filled->section('Files modified'), 'the model never writes the files');
        $this->assertSame('the user verbatim', $filled->section('Latest unresolved user request'));
    }

    public function testMergedWithUnionsFilesAndKeepsThePreviousTextWhereTheNewBlockIsEmpty(): void
    {
        $previous = StateSummaryTemplate::new()
            ->withSection('Goal', 'old goal')
            ->withSection('Key decisions', 'use sqlite')
            ->withFiles(FilesTouched::new()->withRead('a.php')->withRead('b.php')->withModified('c.php'));
        $current = StateSummaryTemplate::new()
            ->withSection('Goal', 'new goal')
            ->withFiles(FilesTouched::new()->withRead('d.php')->withModified('a.php'));

        $merged = $current->mergedWith($previous);

        $this->assertSame('new goal', $merged->section('Goal'));
        $this->assertSame('use sqlite', $merged->section('Key decisions'));
        $this->assertSame("- a.php\n- c.php", $merged->section('Files modified'));
        $this->assertSame("- d.php\n- b.php", $merged->section('Files read'), 'a.php is modified now, so it is no longer listed as read');
    }

    public function testTheRenderedBlockNeverExceedsTheCap(): void
    {
        $block = StateSummaryTemplate::new()
            ->withSection('Errors and fixes', str_repeat('E', 30_000))
            ->withSection('Current work', str_repeat('W', 9_000))
            ->withSection('Goal', 'short goal');

        $text = $block->render();

        $this->assertLessThanOrEqual(StateSummaryTemplate::MAX_CHARS, mb_strlen($text));
        $this->assertStringContainsString('…[trimmed]', $text);
        $this->assertStringContainsString("## Goal\nshort goal", $text, 'short sections are left alone');
        $this->assertStringEndsWith(StateSummaryTemplate::CONTINUE_LINE, $text, 'the closing line survives the trim');
    }

    public function testExtractFromReplyCutsTheLastTaggedBlockOutOfTheRecords(): void
    {
        $reply = "1.\nasked: a\n" . StateSummaryTemplate::OPEN_TAG . "\n## Goal\nold\n" . StateSummaryTemplate::CLOSE_TAG
            . "\n2.\nasked: b\n" . StateSummaryTemplate::OPEN_TAG . "\n## Goal\nnew\n" . StateSummaryTemplate::CLOSE_TAG . "\n";

        [$block, $rest] = StateSummaryTemplate::extractFromReply($reply);

        $this->assertSame("## Goal\nnew", $block);
        $this->assertStringNotContainsString('## Goal\nnew', $rest);
        $this->assertStringContainsString("2.\nasked: b", $rest);
        $this->assertSame([null, 'just records'], StateSummaryTemplate::extractFromReply('just records'));
        $this->assertSame('## Goal' . "\nunclosed", StateSummaryTemplate::extractFromReply("x\n<session-state>\n## Goal\nunclosed")[0]);
    }

    public function testAStoredStateRowIsRecognisedAndTheNewestWins(): void
    {
        $first = StateSummaryTemplate::ROW_PREFIX . StateSummaryTemplate::new()->withSection('Goal', 'first')->render();
        $second = StateSummaryTemplate::new()->withSection('Goal', 'second')->render();

        $this->assertTrue(StateSummaryTemplate::isStateRow($first));
        $this->assertTrue(StateSummaryTemplate::isStateRow($second), 'with or without the row marker');
        $this->assertFalse(StateSummaryTemplate::isStateRow('[summary] asked: x | did: y'));
        $this->assertNull(StateSummaryTemplate::fromRow('plain text'));
        $this->assertSame('first', StateSummaryTemplate::fromRow($first)?->section('Goal'));
        $this->assertSame('second', StateSummaryTemplate::latestIn([$first, 'noise', $second, 'more'])?->section('Goal'));
        $this->assertNull(StateSummaryTemplate::latestIn(['noise']));
    }

    public function testTheHeuristicFromCondensedPairsPointsTheLatestRequestAtTheTail(): void
    {
        $block = StateSummaryTemplate::fromPairs([
            ['user' => 'set up the project', 'assistant' => 'done'],
            ['user' => '', 'assistant' => 'a standalone row', 'standalone' => true],
            ['user' => 'add tests', 'assistant' => 'added three'],
        ]);

        $this->assertSame('set up the project', $block->section('Goal'));
        $this->assertSame('added three', $block->section('Current work'));
        $this->assertSame(StateSummaryTemplate::LATEST_IN_TAIL, $block->section('Latest unresolved user request'));
        $this->assertStringContainsString('2 exchanges recorded', $block->section('Progress'), 'the standalone row is not an exchange');
    }

    public function testTheHeuristicFromAHistoryReadsFilesErrorsAndTheLatestRequestOffTheRows(): void
    {
        $history = [
            Message::user('build the parser'),
            Message::assistant('reading it')->withToolResults([
                new ToolResult('Read', 'contents', arguments: ['file_path' => 'src/Parser.php']),
                new ToolResult('Edit', '', 'old_string not found', arguments: ['file_path' => 'src/Broken.php']),
            ]),
            Message::assistant('patched')->withToolResults([
                new ToolResult('Write', 'ok', arguments: ['file_path' => 'src/Parser.php']),
            ]),
            Message::user('/compact')->withUiOnly(),
            Message::user("now the lexer — exactly:\n  keep `\$tokens` intact"),
        ];

        $block = StateSummaryTemplate::fromHistory($history);

        $this->assertSame('build the parser', $block->section('Goal'));
        $this->assertSame("now the lexer — exactly:\n  keep `\$tokens` intact", $block->section('Latest unresolved user request'), 'verbatim, and a UI-only echo is never the request');
        $this->assertSame('- src/Parser.php', $block->section('Files modified'));
        $this->assertSame('', $block->section('Files read'), 'read then written is listed as modified only');
        $this->assertSame('- Edit: old_string not found', $block->section('Errors and fixes'));
    }

    public function testTheSummaryPromptAsksForEveryModelHeadingBetweenTheTags(): void
    {
        $prompt = CompactionService::COMPACT_SUMMARY_PROMPT;

        foreach (StateSummaryTemplate::MODEL_HEADINGS as $heading) {
            $this->assertStringContainsString('## ' . $heading, $prompt, "the prompt asks for '{$heading}'");
        }
        foreach (StateSummaryTemplate::DERIVED_HEADINGS as $heading) {
            $this->assertStringNotContainsString('## ' . $heading, $prompt, "'{$heading}' is derived, never asked for");
        }
        $this->assertStringContainsString(StateSummaryTemplate::OPEN_TAG, $prompt);
        $this->assertStringContainsString(StateSummaryTemplate::CLOSE_TAG, $prompt);
        $this->assertStringContainsString('"' . StateSummaryTemplate::HEADER . '"', $prompt, 'the merge rule names the block it merges');
        foreach (StateSummaryTemplate::PROGRESS_SUBHEADINGS as $sub) {
            $this->assertStringContainsString('### ' . $sub, $prompt);
        }
    }

    public function testAnUnknownHeadingIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        StateSummaryTemplate::new()->withSection('Mood', 'fine');
    }
}
