<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\MatchResult;
use PHPUnit\Framework\TestCase;

final class SahilmMatcherTest extends TestCase
{
    private SahilmMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SahilmMatcher();
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
        // Should be sorted by score descending
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

    public function testFirstCharBonus(): void
    {
        $result = $this->matcher->match('a', 'apple');

        $this->assertNotNull($result);
        $this->assertSame([0], $result->indices());
    }

    public function testSeparatorBonus(): void
    {
        // 'bar' after separator should score well
        $result = $this->matcher->match('bar', 'foo_bar');

        $this->assertNotNull($result);
        $this->assertSame([4, 5, 6], $result->indices());
    }

    public function testCamelCaseBonus(): void
    {
        // 'fb' in fooBar should match with camelCase bonus
        $result = $this->matcher->match('fb', 'fooBar');

        $this->assertNotNull($result);
    }

    public function testConsecutiveMatchBonus(): void
    {
        $result = $this->matcher->match('foo', 'foo');

        $this->assertNotNull($result);
        $this->assertSame([0, 1, 2], $result->indices());
    }

    public function testPartialMatch(): void
    {
        $result = $this->matcher->match('app', 'apple');

        $this->assertNotNull($result);
        $this->assertSame([0, 1, 2], $result->indices());
    }

    public function testAllCharsMustMatch(): void
    {
        $result = $this->matcher->match('appl', 'app');

        $this->assertNull($result);
    }

    public function testCaseInsensitiveByDefault(): void
    {
        $result = $this->matcher->match('HELLO', 'hello');

        $this->assertNotNull($result);
        $this->assertSame('hello', $result->haystack);
    }

    public function testCaseSensitiveWhenEnabled(): void
    {
        $matcher = new SahilmMatcher(true);
        $result = $matcher->match('HELLO', 'hello');

        $this->assertNull($result);
    }

    public function testUtf8Characters(): void
    {
        $result = $this->matcher->match('中', '中文测试');

        $this->assertNotNull($result);
        $this->assertContains(0, $result->indices());
    }

    public function testMatchAllExcludesNonMatches(): void
    {
        $candidates = ['hello', 'world', 'xyz'];
        $results = $this->matcher->matchAll('xyz', $candidates);

        $this->assertCount(1, $results);
        $this->assertSame('xyz', $results[0]->haystack);
    }

    public function testSeparatorBonusRaisesScore(): void
    {
        // Separator bonus (+10) fires when a query char matches after '_'.
        $withSep = $this->matcher->match('bar', 'foo_bar');
        $withoutSep = $this->matcher->match('bar', 'foobar');

        $this->assertNotNull($withSep);
        $this->assertNotNull($withoutSep);
        $this->assertGreaterThan($withoutSep->score, $withSep->score);
    }

    public function testFirstCharBonusRaisesScore(): void
    {
        // First-char bonus (+15) fires when the first candidate char is matched.
        $firstChar = $this->matcher->match('a', 'apple');
        $notFirst = $this->matcher->match('p', 'apple');

        $this->assertNotNull($firstChar);
        $this->assertNotNull($notFirst);
        $this->assertGreaterThan($notFirst->score, $firstChar->score);
    }

    public function testConsecutiveBonusRaisesScore(): void
    {
        // Consecutive bonus (+5) fires when adjacent query chars match adjacently.
        $consecutive = $this->matcher->match('ab', 'abxy');
        $scattered = $this->matcher->match('ab', 'axby');

        $this->assertNotNull($consecutive);
        $this->assertNotNull($scattered);
        $this->assertGreaterThan($scattered->score, $consecutive->score);
    }

    public function testCaseSensitiveMatchStillScores(): void
    {
        // SahilmMatcher(true) activates the case-sensitive MATCH path.
        $cs = new SahilmMatcher(true);
        $result = $cs->match('Hello', 'Hello');

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->score);
        $this->assertSame([0, 1, 2, 3, 4], $result->indices());
    }

    public function testNonAsciiLowerCaseBonusFires(): void
    {
        // After Step 7 (Unicode-aware case classification), lowercase chars earn
        // the LOWER_CASE_BONUS even when they contain non-ASCII bytes.  The old
        // ASCII-only ord-range check would have returned false for chars like 't'
        // in some encodings, so this test also serves as a regression guard.
        $lowerCase = $this->matcher->match('t', 'test');   // 't' is lowercase → +1
        $upperCase = $this->matcher->match('t', 'TEST');  // 'T' is uppercase → +0

        $this->assertNotNull($lowerCase);
        $this->assertNotNull($upperCase);
        $this->assertGreaterThan($upperCase->score, $lowerCase->score);
    }

    public function testGreedyFirstOccurrenceNoBacktrack(): void
    {
        // Greedy matching: advances on first occurrence of each query char,
        // never backtracks. 'a' at 0, 'b' at 2, 'c' at 6 — scattered early
        // alignment preferred over later contiguous run.
        $result = $this->matcher->match('abc', 'axbxabc');

        $this->assertNotNull($result);
        $this->assertSame([0, 2, 6], $result->indices());
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
