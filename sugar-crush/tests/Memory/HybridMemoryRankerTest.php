<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Memory;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Memory\HybridMemoryRanker;
use SugarCraft\Crush\Memory\MemoryEntry;

/**
 * Roadmap 5.3-2: the hybrid ranker behind the per-turn memory recall — BM25
 * keyword leg, optional vector leg merged 0.7 / 0.3, 30-day half-life, MMR.
 */
final class HybridMemoryRankerTest extends TestCase
{
    private const NOW = '2026-10-01 12:00:00 UTC';

    public function testAWholePromptAsTheQueryFindsTheNoteThatSharesItsWords(): void
    {
        $notes = [
            self::note('a', 'Deploys go through the staging cluster first, never straight to prod.'),
            self::note('b', 'The user prefers tabs over spaces in PHP files.'),
            self::note('c', 'Session titles are written by the title model.'),
        ];

        $ranked = self::ranker()->rank('How do I deploy this to the staging cluster?', $notes);

        self::assertSame(['a'], self::ids($ranked), 'only the note sharing distinctive words is recalled');
    }

    public function testStopwordsAloneRecallNothing(): void
    {
        $notes = [self::note('a', 'This is what we want to do with the thing.')];

        self::assertSame([], self::ranker()->rank('what is this and how do we do it', $notes));
        self::assertSame([], HybridMemoryRanker::queryTerms('the a of to is'));
    }

    public function testTheBestKeywordMatchRanksFirstAndTagsWeighMoreThanProse(): void
    {
        $notes = [
            self::note('prose', 'We once talked about the renderer in passing.'),
            self::note('tagged', 'Frames clip to the terminal height.', tags: ['renderer']),
        ];

        self::assertSame(['tagged', 'prose'], self::ids(self::ranker()->rank('renderer bug', $notes)));
    }

    public function testInflectionsMeetTheirStem(): void
    {
        $notes = [self::note('a', 'Every deploy is announced in the channel.')];

        self::assertSame(['a'], self::ids(self::ranker()->rank('Who announces deploys?', $notes)));
        self::assertSame(['a'], self::ids(self::ranker()->rank('deploying now', $notes)));
    }

    public function testCamelCaseIdentifiersMeetTheirWords(): void
    {
        $notes = [self::note('a', 'The repo map tool caches tags per project.')];

        self::assertSame(['a'], self::ids(self::ranker()->rank('Why is RepoMapTool slow?', $notes)));
    }

    public function testRecencyReordersEquallyRelevantNotesButKeepsTheOldOne(): void
    {
        $notes = [
            self::note('old', 'Migrations run with the deploy script.', ageDays: 120),
            self::note('new', 'Migrations run with the deploy script.', ageDays: 1),
        ];

        $ranked = self::ranker()->rank('run migrations', $notes);

        self::assertSame(['new', 'old'], self::ids($ranked));
        // 120 days is four half-lives: 1/16 of the fresh note's score.
        self::assertEqualsWithDelta($ranked[0]['score'] / 16, $ranked[1]['score'], 0.01);
    }

    public function testMmrPrefersADistinctNoteOverANearDuplicate(): void
    {
        $notes = [
            self::note('dup1', 'Cache invalidation runs on deploy for the cache cluster nodes.'),
            self::note('dup2', 'Cache invalidation runs on deploy for the cache cluster nodes!'),
            self::note('other', 'The cache cluster has three nodes in eu-west.'),
        ];

        $ranked = self::ranker()->rank('cache cluster', $notes, 2);

        self::assertCount(2, $ranked);
        self::assertContains('other', self::ids($ranked), 'the second slot goes to the distinct note, not the duplicate');
    }

    public function testTheVectorLegRecallsANoteTheKeywordLegMisses(): void
    {
        $notes = [
            self::note('semantic', 'Production rollouts happen on Tuesdays.'),
            self::note('unrelated', 'Colour theme is tokyonight.'),
        ];
        // "ship" shares no word with the note, but the embedder says they mean the same.
        $embedder = static fn (array $texts): array => array_map(
            static fn (string $text): array => preg_match('/ship|rollout/i', $text) === 1 ? [1.0, 0.0] : [0.0, 1.0],
            $texts,
        );

        $ranker = self::ranker($embedder);
        $ranked = $ranker->rank('when do we ship?', $notes);

        self::assertSame(['semantic'], self::ids($ranked));
        self::assertSame('hybrid', $ranker->mode());
        self::assertNull($ranker->degradedReason());
        // 0.7 × cosine 1 + 0.3 × keyword 0, decayed by one day.
        self::assertEqualsWithDelta(0.7 * 0.5 ** (1 / 30), $ranked[0]['score'], 1e-9);
    }

    public function testAFailingEmbedderDegradesToKeywordOnlyAndSaysWhy(): void
    {
        $notes = [self::note('a', 'Deploys go through staging.')];
        $ranker = self::ranker(static fn (array $texts): array => throw new \RuntimeException('endpoint down'));

        $ranked = $ranker->rank('deploy to staging', $notes);

        self::assertSame(['a'], self::ids($ranked));
        self::assertSame('keyword', $ranker->mode());
        self::assertSame('endpoint down', $ranker->degradedReason());
    }

    public function testAnEmbedderAnsweringTheWrongShapeDegradesToo(): void
    {
        $ranker = self::ranker(static fn (array $texts): array => [[1.0]]);

        $ranker->rank('deploy', [self::note('a', 'deploy'), self::note('b', 'other')]);

        self::assertSame('keyword', $ranker->mode());
        self::assertNotNull($ranker->degradedReason());
    }

    public function testWithoutAnEmbedderTheModeIsKeywordAndNothingIsDegraded(): void
    {
        $ranker = self::ranker();
        $ranker->rank('deploy', [self::note('a', 'deploy')]);

        self::assertSame('keyword', $ranker->mode());
        self::assertNull($ranker->degradedReason());
    }

    public function testTiesBreakOnTheNoteId(): void
    {
        $notes = [self::note('b', 'deploy notes'), self::note('a', 'deploy notes')];

        self::assertSame(['a'], self::ids(self::ranker()->rank('deploy', $notes, 1)));
    }

    public function testTheLimitBoundsTheResult(): void
    {
        $notes = [];
        foreach (range(1, 6) as $i) {
            $notes[] = self::note("n{$i}", "deploy step {$i} uses target{$i}");
        }

        self::assertCount(3, self::ranker()->rank('deploy', $notes));
        self::assertSame([], self::ranker()->rank('deploy', $notes, 0));
        self::assertSame([], self::ranker()->rank('deploy', []));
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private static function ranker(?\Closure $embedder = null): HybridMemoryRanker
    {
        return HybridMemoryRanker::new($embedder, new \DateTimeImmutable(self::NOW));
    }

    /** @param list<string> $tags */
    private static function note(string $id, string $content, array $tags = [], int $ageDays = 1): MemoryEntry
    {
        $at = (new \DateTimeImmutable(self::NOW))->modify("-{$ageDays} days");

        return new MemoryEntry($id, 'decision', $tags, 'project', $content, $at, $at);
    }

    /**
     * @param list<array{entry: MemoryEntry, score: float}> $ranked
     * @return list<string>
     */
    private static function ids(array $ranked): array
    {
        return array_map(static fn (array $hit): string => $hit['entry']->id(), $ranked);
    }
}
