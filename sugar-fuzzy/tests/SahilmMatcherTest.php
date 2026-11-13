<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\MatchResult;
use PHPUnit\Framework\TestCase;

#[CoversClass(SahilmMatcher::class)]
final class SahilmMatcherTest extends TestCase
{
    private SahilmMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SahilmMatcher();
    }

    #[Test]
    public function testMatchReturnsMatchResult(): void
    {
        $result = $this->matcher->match('hello', 'hello');

        $this->assertInstanceOf(MatchResult::class, $result);
    }

    #[Test]
    public function testMatchWithExactMatch(): void
    {
        $result = $this->matcher->match('hello', 'hello');

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->score);
    }

    #[Test]
    public function testMatchNoMatchReturnsNull(): void
    {
        $result = $this->matcher->match('xyz', 'hello');

        $this->assertNull($result);
    }

    #[Test]
    public function testMatchEmptyQueryReturnsNull(): void
    {
        $result = $this->matcher->match('', 'hello');

        $this->assertNull($result);
    }

    #[Test]
    public function testMatchEmptyCandidateReturnsNull(): void
    {
        $result = $this->matcher->match('hello', '');

        $this->assertNull($result);
    }

    #[Test]
    public function testMatchAllReturnsSortedResults(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $results = $this->matcher->matchAll('app', $candidates);

        $this->assertNotEmpty($results);
        // Should be sorted by score descending
        $resultCount = count($results);
        for ($i = 1; $i < $resultCount; $i++) {
            $this->assertGreaterThanOrEqual($results[$i]->score, $results[$i - 1]->score);
        }
    }

    #[Test]
    public function testMatchAllEmptyQueryReturnsEmpty(): void
    {
        $results = $this->matcher->matchAll('', ['hello', 'world']);

        $this->assertSame([], $results);
    }

    #[Test]
    public function testMatchAllEmptyCandidatesReturnsEmpty(): void
    {
        $results = $this->matcher->matchAll('hello', []);

        $this->assertSame([], $results);
    }

    #[Test]
    public function testFirstCharBonus(): void
    {
        $result = $this->matcher->match('a', 'apple');

        $this->assertNotNull($result);
        $this->assertSame([0], $result->indices());
    }

    #[Test]
    public function testSeparatorBonus(): void
    {
        // 'bar' after separator should score well
        $result = $this->matcher->match('bar', 'foo_bar');

        $this->assertNotNull($result);
        $this->assertSame([4, 5, 6], $result->indices());
    }

    #[Test]
    public function testCamelCaseBonus(): void
    {
        // 'fb' in fooBar should match with camelCase bonus
        $result = $this->matcher->match('fb', 'fooBar');

        $this->assertNotNull($result);
    }

    #[Test]
    public function testConsecutiveMatchBonus(): void
    {
        $result = $this->matcher->match('foo', 'foo');

        $this->assertNotNull($result);
        $this->assertSame([0, 1, 2], $result->indices());
    }

    #[Test]
    public function testPartialMatch(): void
    {
        $result = $this->matcher->match('app', 'apple');

        $this->assertNotNull($result);
        $this->assertSame([0, 1, 2], $result->indices());
    }

    #[Test]
    public function testAllCharsMustMatch(): void
    {
        $result = $this->matcher->match('appl', 'app');

        $this->assertNull($result);
    }

    #[Test]
    public function testCaseInsensitiveByDefault(): void
    {
        $result = $this->matcher->match('HELLO', 'hello');

        $this->assertNotNull($result);
        $this->assertSame('hello', $result->haystack);
    }

    #[Test]
    public function testCaseSensitiveWhenEnabled(): void
    {
        $matcher = new SahilmMatcher(true);
        $result = $matcher->match('HELLO', 'hello');

        $this->assertNull($result);
    }

    #[Test]
    public function testUtf8Characters(): void
    {
        $result = $this->matcher->match('中', '中文测试');

        $this->assertNotNull($result);
        $this->assertContains(0, $result->indices());
    }

    #[Test]
    public function testMatchAllExcludesNonMatches(): void
    {
        $candidates = ['hello', 'world', 'xyz'];
        $results = $this->matcher->matchAll('xyz', $candidates);

        $this->assertCount(1, $results);
        $this->assertSame('xyz', $results[0]->haystack);
    }

    #[Test]
    public function testSeparatorBonusRaisesScore(): void
    {
        // Separator bonus (+10) fires when a query char matches after '_'.
        $withSep = $this->matcher->match('bar', 'foo_bar');
        $withoutSep = $this->matcher->match('bar', 'foobar');

        $this->assertNotNull($withSep);
        $this->assertNotNull($withoutSep);
        $this->assertGreaterThan($withoutSep->score, $withSep->score);
    }

    #[Test]
    public function testFirstCharBonusRaisesScore(): void
    {
        // First-char bonus (+15) fires when the first candidate char is matched.
        $firstChar = $this->matcher->match('a', 'apple');
        $notFirst = $this->matcher->match('p', 'apple');

        $this->assertNotNull($firstChar);
        $this->assertNotNull($notFirst);
        $this->assertGreaterThan($notFirst->score, $firstChar->score);
    }

    #[Test]
    public function testConsecutiveBonusRaisesScore(): void
    {
        // Consecutive bonus (+5) fires when adjacent query chars match adjacently.
        $consecutive = $this->matcher->match('ab', 'abxy');
        $scattered = $this->matcher->match('ab', 'axby');

        $this->assertNotNull($consecutive);
        $this->assertNotNull($scattered);
        $this->assertGreaterThan($scattered->score, $consecutive->score);
    }

    #[Test]
    public function testCaseSensitiveMatchStillScores(): void
    {
        // SahilmMatcher(true) activates the case-sensitive MATCH path.
        $cs = new SahilmMatcher(true);
        $result = $cs->match('Hello', 'Hello');

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->score);
        $this->assertSame([0, 1, 2, 3, 4], $result->indices());
    }

    #[Test]
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

    #[Test]
    public function testCamelCaseBonusRaisesScore(): void
    {
        // #97 cure: the old form's entire score content was two assertGreaterThan(0, …)
        // re-reads of match()'s own null guard (non-null only when score > 0) — the test
        // could not fail. Exact live pins now: 'b' in 'fooBar' matches the UPPERCASE 'B'
        // at index 3 for base matchScore 1 only (no camel fire — the matched char is not
        // lowercase-after-uppercase; no lowercase bonus — 'B' is uppercase); 'b' in
        // 'foobar' matches lowercase 'b' and earns lowerCaseBonus (+1). This pair pins
        // the scoring table (matchScore + lowerCaseBonus), NOT a camel raise — asserting
        // a 1-vs-2 "raise" would be wrong (the delta is the lowercase bonus). The
        // canonical camelCase pin is its own test (sugar-fuzzy-004).
        $fooBar = $this->matcher->match('b', 'fooBar');
        $foobar = $this->matcher->match('b', 'foobar');

        $this->assertNotNull($fooBar);
        $this->assertNotNull($foobar);
        $this->assertSame(1, $fooBar->score);
        $this->assertSame(2, $foobar->score);
    }

    #[Test]
    public function testNonAsciiCamelBonusFires(): void
    {
        // After Step 7 (Unicode-aware case classification), the round-trip check
        // properly identifies case for accented characters.  'Ét' contains 'É'
        // (uppercase) followed by 't' (lowercase), so 't' matching at index 1
        // follows an uppercase char and earns the camelCase bonus.
        // #98 cure: the old assertGreaterThanOrEqual($plain, $accented) passed even in
        // the bonus-absent world (with camelBonus 0 both score 2, and 2 >= 2 is true)
        // — the documented Unicode case-classification fix was completely unprotected.
        // Now a STRICT comparison plus exact live pins (12 / 2): corrupting camelBonus
        // to 0 collapses the accented row to 2 and trips BOTH the > assert and the
        // 12-pin, so the test goes red. (Note: FUZZY-26's camel-direction decision
        // does not move these literals — this pair fires under the current condition.)
        $accented = $this->matcher->match('t', 'Ét');
        $plain = $this->matcher->match('t', 'et');

        $this->assertNotNull($accented);
        $this->assertNotNull($plain);
        // 't' in 'Ét' follows uppercase 'É' → camelCase bonus fires (+10)
        // 't' in 'et' follows lowercase 'e' → no camelCase bonus
        $this->assertSame(12, $accented->score);
        $this->assertSame(2, $plain->score);
        $this->assertGreaterThan($plain->score, $accented->score);
    }

    #[Test]
    public function testGreedyFirstOccurrenceNoBacktrack(): void
    {
        // Greedy matching: advances on first occurrence of each query char,
        // never backtracks. 'a' at 0, 'b' at 2, 'c' at 6 — scattered early
        // alignment preferred over later contiguous run.
        $result = $this->matcher->match('abc', 'axbxabc');

        $this->assertNotNull($result);
        $this->assertSame([0, 2, 6], $result->indices());
    }

    #[Test]
    public function testMatchAllRespectsLimit(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];
        $results = $this->matcher->matchAll('app', $candidates, limit: 2);

        $this->assertCount(2, $results);
    }

    #[Test]
    public function testMatchAllRespectsMinScore(): void
    {
        // #96 cure: the old form swept ['hello','hey','h','xyz'] at minScore 10 — that
        // set scores [hello 24, hey 24] for EVERY threshold 1..24 (h is dropped by the
        // whole-query guard, xyz never matches), so the loop pruned nothing and was
        // vacuous on []. These thresholds DIVIDE the live set: THE-app (7) crosses at
        // 8, the 24-scorers fall away at 25 — an ignored threshold leaks THE-app into
        // the minScore-8 row and the minScore-25 row, an over-strong threshold drops
        // the minScore-7 row's survivor. Both bug classes now go red.
        $candidates = ['hello', 'hey', 'THE-app', 'h', 'xyz'];

        $this->assertSame(
            ['hello|24|[0,1]', 'hey|24|[0,1]', 'THE-app|7|[1,2]'],
            self::rows($this->matcher->matchAll('he', $candidates, minScore: 7)),
        );
        $this->assertSame(
            ['hello|24|[0,1]', 'hey|24|[0,1]'],
            self::rows($this->matcher->matchAll('he', $candidates, minScore: 8)),
        );
        $this->assertSame([], self::rows($this->matcher->matchAll('he', $candidates, minScore: 25)));
    }

    /**
     * Exact-list rendering mirroring GeneratorBytePinTest's row format
     * "haystack|score|[indices]".
     *
     * @param array<MatchResult> $results
     * @return list<string>
     */
    private static function rows(array $results): array
    {
        return array_map(
            static fn (MatchResult $r): string => $r->haystack . '|' . $r->score . '|[' . implode(',', $r->indices()) . ']',
            $results,
        );
    }

    #[Test]
    public function testMatchAllWithNoLimitOrMinScoreIsUnchanged(): void
    {
        // Verify the default behavior (no limit, minScore=1) returns same results
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];
        $withDefaults = $this->matcher->matchAll('app', $candidates);
        $explicit = $this->matcher->matchAll('app', $candidates, null, 1);

        $this->assertEquals($withDefaults, $explicit);
    }

    #[Test]
    public function testMatchAllGeneratorReturnsGenerator(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $result = $this->matcher->matchAllGenerator('app', $candidates);

        $this->assertInstanceOf(\Generator::class, $result);
    }

    #[Test]
    public function testMatchAllGeneratorYieldsCorrectResults(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $results = [];
        foreach ($this->matcher->matchAllGenerator('app', $candidates) as $result) {
            $results[] = $result;
        }

        $this->assertNotEmpty($results);
        // Should be sorted by score descending
        $resultCount = count($results);
        for ($i = 1; $i < $resultCount; $i++) {
            $this->assertGreaterThanOrEqual($results[$i]->score, $results[$i - 1]->score);
        }
    }

    #[Test]
    public function testMatchAllGeneratorWithLimit(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $results = [];
        foreach ($this->matcher->matchAllGenerator('app', $candidates, limit: 2) as $result) {
            $results[] = $result;
        }

        $this->assertCount(2, $results);
    }

    #[Test]
    public function testMatchAllGeneratorWithMinScore(): void
    {
        $candidates = ['hello', 'hey', 'h', 'xyz'];

        $results = [];
        foreach ($this->matcher->matchAllGenerator('he', $candidates, minScore: 10) as $result) {
            $results[] = $result;
        }

        foreach ($results as $result) {
            $this->assertGreaterThanOrEqual(10, $result->score);
        }
    }

    #[Test]
    public function testMatchAllGeneratorEmptyQueryYieldsNothing(): void
    {
        $results = [];
        foreach ($this->matcher->matchAllGenerator('', ['hello', 'world']) as $result) {
            $results[] = $result;
        }

        $this->assertSame([], $results);
    }

    #[Test]
    public function testMatchAllGeneratorEmptyCandidatesYieldsNothing(): void
    {
        $results = [];
        foreach ($this->matcher->matchAllGenerator('hello', []) as $result) {
            $results[] = $result;
        }

        $this->assertSame([], $results);
    }
}
