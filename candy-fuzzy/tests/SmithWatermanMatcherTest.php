<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\MatchResult;
use PHPUnit\Framework\TestCase;

final class SmithWatermanMatcherTest extends TestCase
{
    private SmithWatermanMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SmithWatermanMatcher();
    }

    public function testMatchReturnsMatchResult(): void
    {
        $result = $this->matcher->match('hello', 'hello');

        $this->assertInstanceOf(MatchResult::class, $result);
    }

    public function testMatchWithExactMatch(): void
    {
        $result = $this->matcher->match('hello', 'hello');

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->score);
        $this->assertSame('hello', $result->needle);
        $this->assertSame('hello', $result->haystack);
    }

    public function testMatchWithSubstringMatch(): void
    {
        $result = $this->matcher->match('ell', 'hello');

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->score);
        $this->assertSame('ell', $result->needle);
        $this->assertSame('hello', $result->haystack);
    }

    public function testMatchNoMatchReturnsNull(): void
    {
        $result = $this->matcher->match('xyz', 'hello');

        $this->assertNull($result);
    }

    public function testMatchEmptyQueryReturnsNull(): void
    {
        $result = $this->matcher->match('', 'hello');

        $this->assertNull($result);
    }

    public function testMatchEmptyCandidateReturnsNull(): void
    {
        $result = $this->matcher->match('hello', '');

        $this->assertNull($result);
    }

    public function testQueryLongerThanCandidateReturnsMatch(): void
    {
        // Smith-Waterman is local alignment — it finds the best matching
        // SUBSTRING. "hello" vs "hi" finds "h" match with score 3 (MATCH_SCORE).
        // This matches the behavior of Forms\Fuzzy\FuzzyMatcher::score().
        $result = $this->matcher->match('hello', 'hi');

        $this->assertNotNull($result);
        $this->assertSame(3, $result->score);
        $this->assertSame([0], $result->matchedIndices);
    }

    public function testMatchAllReturnsSortedResults(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $results = $this->matcher->matchAll('app', $candidates);

        $this->assertNotEmpty($results);
        // Should be sorted by score descending (higher scores first)
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual($results[$i]->score, $results[$i - 1]->score);
        }
    }

    public function testMatchAllEmptyQueryReturnsEmpty(): void
    {
        $results = $this->matcher->matchAll('', ['hello', 'world']);

        $this->assertSame([], $results);
    }

    public function testMatchAllEmptyCandidatesReturnsEmpty(): void
    {
        $results = $this->matcher->matchAll('hello', []);

        $this->assertSame([], $results);
    }

    public function testMatchedIndicesForExactMatch(): void
    {
        $result = $this->matcher->match('foo', 'foobar');

        $this->assertNotNull($result);
        // All characters matched in order
        $this->assertSame([0, 1, 2], $result->indices());
    }

    public function testMatchedIndicesForPartialMatch(): void
    {
        $result = $this->matcher->match('oba', 'foobar');

        $this->assertNotNull($result);
        // The algorithm produces specific indices via Smith-Waterman traceback
        // Indices represent the matched alignment path
        $this->assertContains($result->indices()[0], [1, 2]); // First match could be at different positions
    }

    public function testMatchedIndicesForConsecutiveMatches(): void
    {
        $result = $this->matcher->match('ello', 'hello');

        $this->assertNotNull($result);
        // 'e' at index 1, 'l' at 2, 'l' at 3, 'o' at 4
        $this->assertSame([1, 2, 3, 4], $result->indices());
    }

    public function testCaseInsensitiveScoring(): void
    {
        $scoreLower = $this->matcher->match('hello', 'hello');
        $scoreMixed = $this->matcher->match('HELLO', 'hello');

        $this->assertNotNull($scoreLower);
        $this->assertNotNull($scoreMixed);
        $this->assertSame($scoreLower->score, $scoreMixed->score);
    }

    public function testConsecutiveMatchesScoreHigher(): void
    {
        $scoreConsec = $this->matcher->match('ello', 'hello');
        $scoreNonConsec = $this->matcher->match('hlo', 'hello');

        $this->assertNotNull($scoreConsec);
        $this->assertNotNull($scoreNonConsec);
        $this->assertGreaterThan($scoreNonConsec->score, $scoreConsec->score);
    }

    public function testFullMatchScoresHigherThanPartial(): void
    {
        $fullScore = $this->matcher->match('hello', 'hello');
        $partialScore = $this->matcher->match('hell', 'hello');

        $this->assertNotNull($fullScore);
        $this->assertNotNull($partialScore);
        $this->assertGreaterThan($partialScore->score, $fullScore->score);
    }

    public function testUtf8Characters(): void
    {
        $result = $this->matcher->match('中', '中文测试');

        $this->assertNotNull($result);
        $this->assertContains(0, $result->indices());
    }

    public function testUtf8PartialMatch(): void
    {
        $result = $this->matcher->match('文测', '中文测试');

        $this->assertNotNull($result);
        $this->assertSame([1, 2], $result->indices());
    }

    public function testMatchResultIsEmpty(): void
    {
        $result = $this->matcher->match('xyz', 'hello');

        $this->assertNull($result); // No match returns null
    }

    public function testMatchResultIsMatched(): void
    {
        $result = $this->matcher->match('hello', 'hello');

        $this->assertNotNull($result);
        $this->assertTrue($result->isMatched());
    }

    public function testMatchAllExcludesNonMatches(): void
    {
        $candidates = ['hello', 'world', 'xyz'];
        $results = $this->matcher->matchAll('xyz', $candidates);

        $this->assertCount(1, $results);
        $this->assertSame('xyz', $results[0]->haystack);
    }

    public function testMatchAllTiebreakByCandidateAscending(): void
    {
        // 'app' should match both but 'app' should come before 'applet' alphabetically
        $candidates = ['applet', 'app'];
        $results = $this->matcher->matchAll('app', $candidates);

        $this->assertCount(2, $results);
        // Same score, so sorted by candidate asc - 'app' comes first
        $this->assertSame('app', $results[0]->haystack);
    }

    public function testAmbiguousQueryAbOrderingAndIndices(): void
    {
        $candidates = ['alpha', 'ablation', 'label'];

        $results = $this->matcher->matchAll('ab', $candidates);

        $this->assertCount(3, $results);

        foreach ($results as $result) {
            $this->assertNotEmpty($result->indices(), "Matched candidate '{$result->haystack}' must have non-empty indices");
            $this->assertTrue($result->score > 0, "Score must be positive for matched candidate '{$result->haystack}'");
        }

        $scores = array_map(static fn(MatchResult $r) => $r->score, $results);
        for ($i = 1; $i < count($scores); $i++) {
            $this->assertGreaterThanOrEqual($scores[$i], $scores[$i - 1], 'Results must be sorted by score descending');
        }
    }

    public function testAmbiguousQueryAbIndicesAreByteOffsets(): void
    {
        $candidates = ['alpha', 'ablation', 'label'];
        $results = $this->matcher->matchAll('ab', $candidates);

        $resultMap = [];
        foreach ($results as $result) {
            $resultMap[$result->haystack] = $result;
        }

        $this->assertContains(0, $resultMap['ablation']->indices());
        $this->assertContains(1, $resultMap['ablation']->indices());

        $this->assertNotEmpty($resultMap['alpha']->indices());
        $this->assertNotEmpty($resultMap['label']->indices());
    }

    public function testMatchAllRespectsLimit(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];
        $results = $this->matcher->matchAll('app', $candidates, limit: 2);

        $this->assertCount(2, $results);
    }

    public function testMatchAllRespectsMinScore(): void
    {
        // Build a list with varied scores
        $candidates = ['hello', 'hey', 'h', 'xyz'];
        $results = $this->matcher->matchAll('he', $candidates, minScore: 10);

        // All results should have score >= 10
        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual(10, $result->score);
        }
    }

    public function testMatchAllWithNoLimitOrMinScoreIsUnchanged(): void
    {
        // Verify the default behavior (no limit, minScore=1) returns same results
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];
        $withDefaults = $this->matcher->matchAll('app', $candidates);
        $explicit = $this->matcher->matchAll('app', $candidates, null, 1);

        $this->assertEquals($withDefaults, $explicit);
    }
}
