<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Fuzzy;

use SugarCraft\Forms\Fuzzy\FuzzyMatcher;
use PHPUnit\Framework\TestCase;

final class FuzzyMatcherTest extends TestCase
{
    private FuzzyMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new FuzzyMatcher();
    }

    public function testExactMatchReturnsHighScore(): void
    {
        $score = $this->matcher->score('hello', 'hello');
        $this->assertGreaterThan(0, $score);
    }

    public function testSubstringMatchReturnsPositiveScore(): void
    {
        $score = $this->matcher->score('ell', 'hello');
        $this->assertGreaterThan(0, $score);
    }

    public function testNoMatchReturnsZeroOrNegative(): void
    {
        $score = $this->matcher->score('xyz', 'hello');
        $this->assertLessThanOrEqual(0, $score);
    }

    public function testEmptyQueryReturnsZero(): void
    {
        $score = $this->matcher->score('', 'hello');
        $this->assertSame(0, $score);
    }

    public function testEmptyCandidateReturnsNegative(): void
    {
        $score = $this->matcher->score('hello', '');
        $this->assertLessThan(0, $score);
    }

    public function testCaseInsensitiveScoring(): void
    {
        // Case should not matter - same characters should score the same
        $scoreLower = $this->matcher->score('hello', 'hello');
        $scoreMixed = $this->matcher->score('HELLO', 'hello');
        $this->assertSame($scoreLower, $scoreMixed);
    }

    public function testConsecutiveMatchesScoreHigher(): void
    {
        $score = $this->matcher->score('ello', 'hello');
        $this->assertGreaterThan(0, $score);

        // Non-consecutive match should score lower
        $scoreNonConsec = $this->matcher->score('hlo', 'hello');
        $this->assertGreaterThan($scoreNonConsec, $score);
    }

    public function testMatchReturnsSortedByScoreDescending(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $result = $this->matcher->match('app', $candidates);

        $this->assertNotEmpty($result);
        // Results should be sorted by score descending
        $scores = array_column($result, 1);
        for ($i = 1; $i < count($scores); $i++) {
            $this->assertGreaterThanOrEqual($scores[$i], $scores[$i - 1]);
        }
    }

    public function testMatchExcludesZeroOrNegativeScore(): void
    {
        $candidates = ['hello', 'world', 'xyz'];
        $result = $this->matcher->match('xyz', $candidates);

        $this->assertCount(1, $result);
        $this->assertSame('xyz', $result[0][0]);
    }

    public function testMatchEmptyQueryReturnsEmpty(): void
    {
        $result = $this->matcher->match('', ['hello', 'world']);
        $this->assertSame([], $result);
    }

    public function testMatchEmptyCandidatesReturnsEmpty(): void
    {
        $result = $this->matcher->match('hello', []);
        $this->assertSame([], $result);
    }

    public function testMatchPartialWordMatch(): void
    {
        $candidates = ['documentation', 'do', 'dog', 'good', 'bodacious'];
        $result = $this->matcher->match('doc', $candidates);

        // Should match 'documentation', 'do', 'dog' - these have 'd' then 'o' then 'c' in order
        // 'good' only has 'o' matching, so scores very low but may appear due to the algorithm
        // 'bodacious' has 'o', 'd', 'c' in order (b-o-d-a-c-...) as subsequence - correct behavior
        $matched = array_column($result, 0);
        $this->assertContains('documentation', $matched);
        $this->assertContains('do', $matched);
        $this->assertContains('dog', $matched);
        // High-scoring matches should be at the top
        $this->assertSame('documentation', $matched[0]);
    }

    public function testScoreIsPositiveForPartialMatch(): void
    {
        $score = $this->matcher->score('world', 'hello world');
        $this->assertGreaterThan(0, $score);
    }

    public function testFullMatchScoresHigherThanPartial(): void
    {
        $fullScore = $this->matcher->score('hello', 'hello');
        $partialScore = $this->matcher->score('hell', 'hello');
        $this->assertGreaterThan($partialScore, $fullScore);
    }

    public function testNoMatchBetweenUnrelatedStrings(): void
    {
        // 'xyz' vs 'hello' should have no meaningful match
        $score = $this->matcher->score('xyz', 'hello');
        $this->assertLessThanOrEqual(0, $score);
    }

    public function testMatchWithCommonSubsequence(): void
    {
        $candidates = ['ppp', 'PPP', 'ape', 'apart'];
        $result = $this->matcher->match('app', $candidates);

        // 'ape' has a-p-e, 'apart' has a-p-a-r-t
        // Both should match but 'ape' has consecutive 'ap' match
        $matched = array_column($result, 0);
        $this->assertContains('ape', $matched);
        $this->assertContains('apart', $matched);
    }

    public function testFuzzyMatcherScoringIsDeterministic(): void
    {
        $score1 = $this->matcher->score('test', 'testing');
        $score2 = $this->matcher->score('test', 'testing');
        $this->assertSame($score1, $score2);
    }
}
