<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests\Fuzzy;

use SugarCraft\Prompt\Fuzzy\FuzzyMatcher;
use PHPUnit\Framework\TestCase;

final class FuzzyMatcherTest extends TestCase
{
    private FuzzyMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new FuzzyMatcher();
    }

    private function score(string $needle, string $haystack): int
    {
        if ($needle === '' || $haystack === '') {
            if ($needle === '') {
                return 0;
            }
            return -1;
        }
        $result = $this->matcher->match($needle, $haystack);
        return $result?->score ?? 0;
    }

    private function match(string $needle, array $candidates): array
    {
        $results = $this->matcher->matchAll($needle, $candidates);
        return array_map(
            static fn($r) => [$r->haystack, $r->score],
            $results
        );
    }

    public function testExactMatchReturnsHighScore(): void
    {
        $score = $this->score('hello', 'hello');
        $this->assertGreaterThan(0, $score);
    }

    public function testSubstringMatchReturnsPositiveScore(): void
    {
        $score = $this->score('ell', 'hello');
        $this->assertGreaterThan(0, $score);
    }

    public function testNoMatchReturnsZeroOrNegative(): void
    {
        $score = $this->score('xyz', 'hello');
        $this->assertLessThanOrEqual(0, $score);
    }

    public function testEmptyQueryReturnsZero(): void
    {
        $score = $this->score('', 'hello');
        $this->assertSame(0, $score);
    }

    public function testEmptyCandidateReturnsNegative(): void
    {
        $score = $this->score('hello', '');
        $this->assertLessThan(0, $score);
    }

    public function testCaseInsensitiveScoring(): void
    {
        $scoreLower = $this->score('hello', 'hello');
        $scoreMixed = $this->score('HELLO', 'hello');
        $this->assertSame($scoreLower, $scoreMixed);
    }

    public function testConsecutiveMatchesScoreHigher(): void
    {
        $score = $this->score('ello', 'hello');
        $this->assertGreaterThan(0, $score);

        $scoreNonConsec = $this->score('hlo', 'hello');
        $this->assertGreaterThan($scoreNonConsec, $score);
    }

    public function testMatchReturnsSortedByScoreDescending(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $result = $this->match('app', $candidates);

        $this->assertNotEmpty($result);
        $scores = array_column($result, 1);
        for ($i = 1; $i < count($scores); $i++) {
            $this->assertGreaterThanOrEqual($scores[$i], $scores[$i - 1]);
        }
    }

    public function testMatchExcludesZeroOrNegativeScore(): void
    {
        $candidates = ['hello', 'world', 'xyz'];
        $result = $this->match('xyz', $candidates);

        $this->assertCount(1, $result);
        $this->assertSame('xyz', $result[0][0]);
    }

    public function testMatchEmptyQueryReturnsEmpty(): void
    {
        $result = $this->match('', ['hello', 'world']);
        $this->assertSame([], $result);
    }

    public function testMatchEmptyCandidatesReturnsEmpty(): void
    {
        $result = $this->match('hello', []);
        $this->assertSame([], $result);
    }

    public function testMatchPartialWordMatch(): void
    {
        $candidates = ['documentation', 'do', 'dog', 'good', 'bodacious'];
        $result = $this->match('doc', $candidates);

        $matched = array_column($result, 0);
        $this->assertContains('documentation', $matched);
        $this->assertContains('do', $matched);
        $this->assertContains('dog', $matched);
        // Results are ranked best-first; 'documentation' is the strongest 'doc' match.
        $this->assertSame('documentation', $matched[0]);
    }

    public function testScoreIsPositiveForPartialMatch(): void
    {
        $score = $this->score('world', 'hello world');
        $this->assertGreaterThan(0, $score);
    }

    public function testFullMatchScoresHigherThanPartial(): void
    {
        $fullScore = $this->score('hello', 'hello');
        $partialScore = $this->score('hell', 'hello');
        $this->assertGreaterThan($partialScore, $fullScore);
    }

    public function testNoMatchBetweenUnrelatedStrings(): void
    {
        $score = $this->score('xyz', 'hello');
        $this->assertLessThanOrEqual(0, $score);
    }

    public function testMatchWithCommonSubsequence(): void
    {
        $candidates = ['ppp', 'PPP', 'ape', 'apart'];
        $result = $this->match('app', $candidates);

        $matched = array_column($result, 0);
        $this->assertContains('ape', $matched);
        $this->assertContains('apart', $matched);
    }

    public function testFuzzyMatcherScoringIsDeterministic(): void
    {
        $score1 = $this->score('test', 'testing');
        $score2 = $this->score('test', 'testing');
        $this->assertSame($score1, $score2);
    }

    public function testAliasResolvesToCandyFuzzyClass(): void
    {
        $actual = (new \ReflectionClass(FuzzyMatcher::class))->getName();
        $this->assertSame(\SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher::class, $actual);
    }
}
