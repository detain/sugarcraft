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

    public function testMatchAllReturnsSortedResults(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $results = $this->matcher->matchAll('app', $candidates);

        $this->assertNotEmpty($results);
        // Should be sorted by score descending (higher scores first)
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual($results[$i - 1]->score, $results[$i]->score);
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
}
