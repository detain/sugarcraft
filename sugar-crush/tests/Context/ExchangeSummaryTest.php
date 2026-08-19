<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;

/**
 * crush_code.md Phase 5 item 6's compactor half: the seam that lets stage 2 use
 * a model-written summary instead of the truncate-and-placeholder heuristic.
 *
 * What the heuristic did, measured before this change: it truncated the user's
 * message to `summaryUserMaxChars` and appended either the assistant's reply
 * verbatim (when it fit `summaryAssistantMaxChars`) or the literal string
 * `[exchanged information]`. An exchange summarised as
 * "how do I add a route? → [exchanged information]" has preserved the question
 * and thrown the answer away, which is the opposite of what a compaction is for.
 *
 * The heuristic is still here and still correct — it is the only thing available
 * with no provider to ask — so every test below that asserts a model summary
 * won also has a sibling asserting the heuristic still runs for whatever the
 * model did not cover.
 */
final class ExchangeSummaryTest extends TestCase
{
    /** Pairs enough to push past recentPreserveCount so stage 2 actually runs. */
    private function history(int $pairs, int $answerLength = 400): array
    {
        $out = [];
        for ($i = 1; $i <= $pairs; $i++) {
            $out[] = ['role' => 'user', 'content' => "question {$i}"];
            $out[] = ['role' => 'assistant', 'content' => "answer {$i} " . str_repeat('x', $answerLength)];
        }

        return $out;
    }

    private function compactor(int $preserve = 2): ContextCompactor
    {
        return new ContextCompactor(CompactorConfig::new()->withRecentPreserveCount($preserve));
    }

    // =====================================================================
    // exchangesToSummarize() — the question a caller must answer before it can
    // ask a model anything
    // =====================================================================

    /**
     * The exchanges offered for summarising are exactly the ones stage 2 would
     * condense: everything before the preserved tail, in order.
     */
    public function testItOffersExactlyTheExchangesStageTwoWouldCondense(): void
    {
        $exchanges = $this->compactor(2)->exchangesToSummarize($this->history(5));

        $this->assertCount(3, $exchanges, 'five pairs, two preserved, three condensed');
        $this->assertSame('question 1', $exchanges[0]['user']);
        $this->assertSame('question 3', $exchanges[2]['user'], 'and in conversation order');
    }

    /**
     * The claim that makes the previous test meaningful: the offered set and the
     * set stage 2 actually replaces are the same set. Derived rather than
     * restated — every offered exchange's user text must be GONE from the
     * compacted output, and every preserved one still present.
     */
    public function testEveryOfferedExchangeIsOneTheCompactionReallyReplaces(): void
    {
        $history = $this->history(5);
        $compactor = $this->compactor(2);

        $offered = array_column($compactor->exchangesToSummarize($history), 'user');
        $compacted = implode("\n", array_column($compactor->compact($history), 'content'));

        foreach ($offered as $user) {
            $this->assertStringNotContainsString(
                $user . "\n",
                $compacted . "\n",
                "'{$user}' was offered for summarising but survived the compaction verbatim",
            );
        }
        $this->assertStringContainsString('question 4', $compacted, 'the preserved tail must survive');
        $this->assertStringContainsString('question 5', $compacted);
    }

    /** Nothing to compact means nothing to ask, and that is not an error. */
    public function testAHistoryTooShortToCompactOffersNothing(): void
    {
        $this->assertSame([], $this->compactor(10)->exchangesToSummarize($this->history(3)));
        $this->assertSame([], $this->compactor(2)->exchangesToSummarize([]));
    }

    /**
     * A standalone turn (a system message with no user turn before it) is NOT
     * offered: stage 2 truncates those to 120 characters rather than summarising
     * them, so a model summary for one would be produced and then ignored.
     */
    public function testStandaloneTurnsAreNotOfferedBecauseStageTwoDoesNotSummariseThem(): void
    {
        $history = [
            ['role' => 'system', 'content' => 'a standalone notice ' . str_repeat('y', 300)],
            ...$this->history(4),
        ];

        $offered = array_column($this->compactor(2)->exchangesToSummarize($history), 'user');

        $this->assertSame(['question 1', 'question 2'], $offered);
    }

    /**
     * A user turn with no reply yet has no exchange to summarise. (Reached by
     * making the LAST condensed pair unanswered.)
     */
    public function testAUserTurnWithNoReplyIsNotOffered(): void
    {
        $history = [
            ['role' => 'user', 'content' => 'unanswered question'],
            ...$this->history(3),
        ];
        // preserve 3 => the unanswered pair is the only one condensed
        $offered = $this->compactor(3)->exchangesToSummarize($history);

        $this->assertSame([], $offered);
    }

    /**
     * The text offered is the POST-stage-4/5 text, not the original. Stages 4 and
     * 5 run before summarization on purpose (see `ContextCompactor::compact()`),
     * so a model asked to summarise the raw file contents would be summarising
     * something the compaction had already replaced.
     */
    public function testTheTextOfferedIsWhatStagesFourAndFiveLeftBehind(): void
    {
        $body = str_repeat("line of source\n", 40);
        $history = [
            ['role' => 'user', 'content' => 'read the file'],
            ['role' => 'assistant', 'content' => "/src/Big.php\n" . $body],
            ...$this->history(3),
        ];

        $offered = $this->compactor(3)->exchangesToSummarize($history);

        $this->assertCount(1, $offered);
        $this->assertStringNotContainsString(
            'line of source',
            $offered[0]['assistant'],
            'stage 4 had already replaced the file body with metadata, so the model must not be shown it',
        );
        $this->assertStringStartsWith(
            '[file: ',
            $offered[0]['assistant'],
            'and what it IS shown is stage 4\'s metadata line',
        );
    }

    /** The key a caller files a summary under is the one the compactor will look up. */
    public function testTheKeyOnEachOfferedExchangeIsTheOneCompactWillLookUp(): void
    {
        $exchanges = $this->compactor(2)->exchangesToSummarize($this->history(4));

        foreach ($exchanges as $exchange) {
            $this->assertSame(
                ContextCompactor::exchangeKey($exchange['user'], $exchange['assistant']),
                $exchange['key'],
            );
        }
    }

    // =====================================================================
    // withExchangeSummaries() — using them
    // =====================================================================

    /**
     * The point of the whole item: a covered exchange's summary is the model's
     * sentence, and the `[exchanged information]` placeholder is gone from it.
     */
    public function testACoveredExchangeIsSummarisedByTheModelAndNotByThePlaceholder(): void
    {
        $history = $this->history(4);
        $compactor = $this->compactor(2);
        $exchanges = $compactor->exchangesToSummarize($history);

        $summaries = [];
        foreach ($exchanges as $exchange) {
            $summaries[$exchange['key']] = 'Explained routing; chose config/routes.php.';
        }

        $compacted = $compactor->withExchangeSummaries($summaries)->compact($history);
        $text = implode("\n", array_column($compacted, 'content'));

        $this->assertStringContainsString('[summary] Explained routing; chose config/routes.php.', $text);
        $this->assertStringNotContainsString('[exchanged information]', $text);
    }

    /**
     * Without the summaries the SAME history and the SAME compactor produce the
     * placeholder — which is what makes the assertion above about the summaries
     * rather than about the fixture.
     */
    public function testTheSameHistoryWithoutSummariesStillProducesThePlaceholder(): void
    {
        $history = $this->history(4);
        $text = implode("\n", array_column($this->compactor(2)->compact($history), 'content'));

        $this->assertStringContainsString('[exchanged information]', $text);
    }

    /**
     * A partially-obeyed instruction degrades rather than mis-attributing: the
     * covered exchange gets its sentence, the uncovered one falls back to the
     * heuristic. Nothing shifts along by one.
     */
    public function testAnUncoveredExchangeFallsBackToTheHeuristicInTheSamePass(): void
    {
        $history = $this->history(4);
        $compactor = $this->compactor(2);
        $exchanges = $compactor->exchangesToSummarize($history);

        $compacted = $compactor
            ->withExchangeSummaries([$exchanges[1]['key'] => 'The second one, summarised.'])
            ->compact($history);
        $text = implode("\n", array_column($compacted, 'content'));

        $this->assertStringContainsString('The second one, summarised.', $text);
        $this->assertStringContainsString(
            'question 1 → [exchanged information]',
            $text,
            'the exchange the model skipped keeps the heuristic, and keeps its OWN heuristic',
        );
    }

    /**
     * Keyed by content, not position — which is what makes a summary safe to
     * apply to a history that moved on while the request was out. A summary
     * filed under an exchange that is no longer being condensed is simply unused.
     */
    public function testASummaryForAnExchangeThatIsNoLongerCondensedIsUnused(): void
    {
        $history = $this->history(4);
        $compactor = $this->compactor(2);

        $stale = ContextCompactor::exchangeKey('question from a different session', 'and its answer');
        $compacted = $compactor
            ->withExchangeSummaries([$stale => 'A SUMMARY THAT BELONGS SOMEWHERE ELSE'])
            ->compact($history);
        $text = implode("\n", array_column($compacted, 'content'));

        $this->assertStringNotContainsString('SOMEWHERE ELSE', $text);
        $this->assertStringContainsString('[exchanged information]', $text, 'and the heuristic ran instead');
    }

    /**
     * Position-independence, stated as a property: inserting a turn ahead of the
     * condensed exchanges must not move a summary onto the wrong one. An
     * index-keyed map would have shifted every summary by one here.
     */
    public function testASummaryStaysWithItsOwnExchangeWhenTheHistoryShiftsAheadOfIt(): void
    {
        $compactor = $this->compactor(2);
        $before = $this->history(4);
        $exchanges = $compactor->exchangesToSummarize($before);
        $summaries = [$exchanges[0]['key'] => 'BELONGS TO QUESTION ONE'];

        // A standalone system notice arrives at the front, shifting every index.
        $after = [['role' => 'system', 'content' => 'a notice'], ...$before];

        $compacted = $compactor->withExchangeSummaries($summaries)->compact($after);
        $rows = array_column($compacted, 'content');

        $matched = array_values(array_filter(
            $rows,
            static fn(string $row): bool => str_contains($row, 'BELONGS TO QUESTION ONE'),
        ));
        $this->assertCount(1, $matched, 'the summary must still be applied exactly once');
        $this->assertSame('[summary] BELONGS TO QUESTION ONE', $matched[0]);
        $this->assertStringNotContainsString(
            'question 1 →',
            implode("\n", $rows),
            'and it must have replaced question 1, not some other exchange',
        );
    }

    /** An empty-string summary is treated as no summary rather than as a summary of nothing. */
    public function testAnEmptySummaryFallsBackToTheHeuristic(): void
    {
        $history = $this->history(4);
        $compactor = $this->compactor(2);
        $exchanges = $compactor->exchangesToSummarize($history);

        $compacted = $compactor
            ->withExchangeSummaries([$exchanges[0]['key'] => ''])
            ->compact($history);

        $this->assertStringContainsString(
            'question 1 → [exchanged information]',
            implode("\n", array_column($compacted, 'content')),
        );
    }

    /** `withExchangeSummaries()` is a copy — the original keeps using the heuristic. */
    public function testWithExchangeSummariesReturnsACopyAndLeavesTheOriginalAlone(): void
    {
        $history = $this->history(4);
        $compactor = $this->compactor(2);
        $exchanges = $compactor->exchangesToSummarize($history);
        $with = $compactor->withExchangeSummaries([$exchanges[0]['key'] => 'MODEL SUMMARY']);

        $this->assertNotSame($compactor, $with);
        $this->assertStringNotContainsString(
            'MODEL SUMMARY',
            implode("\n", array_column($compactor->compact($history), 'content')),
        );
        $this->assertStringContainsString(
            'MODEL SUMMARY',
            implode("\n", array_column($with->compact($history), 'content')),
        );
    }

    /** Two byte-identical exchanges share one key, and one summary covers both. */
    public function testTwoIdenticalExchangesShareOneKey(): void
    {
        $this->assertSame(
            ContextCompactor::exchangeKey('a', 'b'),
            ContextCompactor::exchangeKey('a', 'b'),
        );
        $this->assertNotSame(
            ContextCompactor::exchangeKey('a', 'b'),
            ContextCompactor::exchangeKey('ab', ''),
            'the separator must stop two different exchanges concatenating to one key',
        );
    }

    /**
     * The refactor that extracted stages 0/1/4/5 must not have changed what
     * `compact()` does. Same input, same output as the pre-refactor pipeline
     * described in its docblock: a history at or below recentPreserveCount comes
     * back with tool-result messages stripped and nothing else touched, and
     * savingsPercentage() reads zero.
     */
    public function testAHistoryTooShortToCompactStillComesBackStrippedOfToolResultsOnly(): void
    {
        $compactor = $this->compactor(10);
        $history = [
            ['role' => 'user', 'content' => 'hello'],
            ['role' => 'assistant', 'content' => 'hi'],
        ];

        $this->assertSame($history, $compactor->compact($history));
        $this->assertSame(0, $compactor->savingsPercentage());
        $this->assertSame([], $compactor->compact([]));
    }
}
