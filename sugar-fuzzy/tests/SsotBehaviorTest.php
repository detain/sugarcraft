<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\ScoringProfile;
use PHPUnit\Framework\TestCase;

#[CoversClass(SmithWatermanMatcher::class)]
#[CoversClass(SahilmMatcher::class)]
#[CoversClass(ScoringProfile::class)]
final class SsotBehaviorTest extends TestCase
{
    /**
     * Pinned CURRENT SmithWaterman output — identical to ScoringCharacterizationTest.
     * The default profile MUST reproduce these exactly.
     *
     * @return array<string, array{string, string, int|null, list<int>|null}>
     */
    public static function corpus(): array
    {
        return [
            'ASCII exact' => ['foo', 'foobar', 19, [0, 1, 2]],
            'substring' => ['ell', 'hello', 19, [1, 2, 3]],
            'scattered' => ['abc', 'axbxabc', 19, [4, 5, 6]],
            'UTF-8 single' => ['中', '中文测试', 3, [0]],
            'UTF-8 substring' => ['文测', '中文测试', 11, [1, 2]],
            'separator' => ['bar', 'foo_bar', 19, [4, 5, 6]],
            'camelCase' => ['fb', 'fooBar', 4, [0, 3]],
            'no-match' => ['xyz', 'abc', null, null],
            'query longer' => ['hello', 'hi', 3, [0]],
            // MAJOR-1: indices stay in ORIGINAL code-point space across U+0130 expansion.
            'İstanbul expansion' => ['ist', 'İstanbul', 11, [1, 2]],
            'İstanbul tail' => ['bul', 'İstanbul', 19, [5, 6, 7]],
        ];
    }

    // ---- Bit-equivalence: default profile == historical behavior ----

    #[Test]
    #[DataProvider('corpus')]
    public function testDefaultProfileReproducesPinnedOutput(string $q, string $c, ?int $score, ?array $indices): void
    {
        $result = (new SmithWatermanMatcher())->match($q, $c);

        if ($score === null) {
            $this->assertNull($result);
            return;
        }

        $this->assertNotNull($result);
        $this->assertSame($score, $result->score);
        $this->assertSame($indices, $result->indices());
    }

    #[Test]
    #[DataProvider('corpus')]
    public function testNoProfileEqualsExplicitDefaultProfile(string $q, string $c): void
    {
        $implicit = (new SmithWatermanMatcher())->match($q, $c);
        $explicit = (new SmithWatermanMatcher(ScoringProfile::canonical()))->match($q, $c);

        $this->assertEquals($implicit, $explicit);
    }

    #[Test]
    public function testFactoryNewAlsoBitEquivalent(): void
    {
        $this->assertEquals(
            (new SmithWatermanMatcher())->match('foo', 'foobar'),
            SmithWatermanMatcher::new()->match('foo', 'foobar'),
        );
    }

    #[Test]
    public function testProfileAccessor(): void
    {
        $this->assertEquals(ScoringProfile::canonical(), (new SmithWatermanMatcher())->profile());
        $this->assertEquals(ScoringProfile::strict(), (new SmithWatermanMatcher(ScoringProfile::strict()))->profile());
    }

    // ---- Profiles produce differing scores / rankings ----

    #[Test]
    public function testProfilesScoreSameMatchDifferently(): void
    {
        $default = new SmithWatermanMatcher();
        $strict = new SmithWatermanMatcher(ScoringProfile::strict());
        $lenient = new SmithWatermanMatcher(ScoringProfile::lenient());

        $d = $default->score('abc', 'abc');
        $s = $strict->score('abc', 'abc');
        $l = $lenient->score('abc', 'abc');

        // strict rewards more, lenient rewards less — magnitudes strictly ordered.
        $this->assertGreaterThan($d, $s);
        $this->assertGreaterThan($l, $d);
        $this->assertNotSame($d, $s);
        $this->assertNotSame($d, $l);
    }

    #[Test]
    public function testProfileThresholdChangesResultSet(): void
    {
        // A scattered match ('a_b_c') scores much lower than a contiguous one
        // ('abc'). At a fixed minScore the lenient profile drops the scattered
        // candidate that the default profile keeps — same inputs, different
        // ranked result set purely from the profile.
        $candidates = ['abc', 'a_b_c'];

        $default = (new SmithWatermanMatcher())->matchAll('abc', $candidates, minScore: 6);
        $lenient = (new SmithWatermanMatcher(ScoringProfile::lenient()))->matchAll('abc', $candidates, minScore: 6);

        $this->assertCount(2, $default);
        $this->assertCount(1, $lenient);
        $this->assertSame('abc', $lenient[0]->haystack);
    }

    // ---- score() fast path == full path ----

    #[Test]
    #[DataProvider('corpus')]
    public function testScoreFastPathEqualsFullPathDefault(string $q, string $c, ?int $score): void
    {
        $m = new SmithWatermanMatcher();
        $expected = $m->match($q, $c)?->score ?? 0;

        $this->assertSame($expected, $m->score($q, $c));
        // And equals the pinned value when a match exists.
        if ($score !== null) {
            $this->assertSame($score, $m->score($q, $c));
        }
    }

    #[Test]
    #[DataProvider('corpus')]
    public function testScoreFastPathEqualsFullPathAcrossProfiles(string $q, string $c): void
    {
        foreach ([ScoringProfile::strict(), ScoringProfile::lenient()] as $profile) {
            $m = new SmithWatermanMatcher($profile);
            $expected = $m->match($q, $c)?->score ?? 0;
            $this->assertSame($expected, $m->score($q, $c), "profile mismatch for '$q'/'$c'");
        }
    }

    #[Test]
    public function testScoreEmptyInputsReturnZero(): void
    {
        $m = new SmithWatermanMatcher();
        $this->assertSame(0, $m->score('', 'abc'));
        $this->assertSame(0, $m->score('abc', ''));
    }

    // ---- DoS length caps truncate within the Smith-Waterman contract ----

    #[Test]
    public function testOverCandidateCapSearchesOnlyThePrefix(): void
    {
        $capped = new SmithWatermanMatcher(maxCandidateLength: 3);
        $prefix = (new SmithWatermanMatcher())->match('ab', 'abc');

        // candidate 'abcdef' is 6 chars > cap 3 → aligned as its prefix 'abc',
        // same score scale, indices valid in the ORIGINAL haystack.
        $this->assertEquals(
            new MatchResult('ab', 'abcdef', $prefix->score, $prefix->matchedIndices),
            $capped->match('ab', 'abcdef'),
        );
    }

    #[Test]
    public function testOverQueryCapAlignsOnlyTheQueryPrefix(): void
    {
        $capped = new SmithWatermanMatcher(maxQueryLength: 2);
        $prefix = (new SmithWatermanMatcher())->match('ab', 'abcdef');

        // query 'abc' is 3 chars > cap 2 → 'ab' aligns; needle stays the caller's.
        $this->assertEquals(
            new MatchResult('abc', 'abcdef', $prefix->score, $prefix->matchedIndices),
            $capped->match('abc', 'abcdef'),
        );
    }

    #[Test]
    public function testUnderCapStaysOnSmithWaterman(): void
    {
        $capped = new SmithWatermanMatcher(maxCandidateLength: 100, maxQueryLength: 100);
        $default = new SmithWatermanMatcher();

        // Well under the cap → identical to the uncapped SW result (NOT Sahilm).
        $this->assertEquals($default->match('ab', 'abcdef'), $capped->match('ab', 'abcdef'));
        $this->assertNotEquals((new SahilmMatcher())->match('ab', 'abcdef'), $capped->match('ab', 'abcdef'));
    }

    #[Test]
    public function testScoreRespectsCapTruncation(): void
    {
        $capped = new SmithWatermanMatcher(maxCandidateLength: 3);

        $this->assertSame($capped->match('ab', 'abcdef')?->score, $capped->score('ab', 'abcdef'));
        $this->assertSame((new SmithWatermanMatcher())->score('ab', 'abc'), $capped->score('ab', 'abcdef'));
    }

    /**
     * Regression: at the default 1000-char cap the over-cap path used to swap
     * in SahilmMatcher (full in-order subsequence), so a LOCAL alignment that
     * scored just under the cap became null one character later.
     */
    #[Test]
    public function testCapBoundaryDoesNotFlipLocalAlignmentToNull(): void
    {
        $m = new SmithWatermanMatcher();
        $cap = SmithWatermanMatcher::DEFAULT_MAX_CANDIDATE_LENGTH;

        // 'q' never occurs: only the local 'b' alignment can match.
        foreach ([$cap - 10, $cap - 1, $cap, $cap + 1, $cap + 10, $cap * 3] as $tail) {
            $candidate = 'b' . str_repeat('x', $tail);
            $result = $m->match('bq', $candidate);
            $this->assertNotNull($result, "match flipped to null at length " . ($tail + 1));
            $this->assertSame(3, $result->score);
            $this->assertSame([0], $result->matchedIndices);
            $this->assertSame(3, $m->score('bq', $candidate));
        }

        // The report's probe: a match that sits inside the searched prefix.
        $this->assertSame(3, $m->match('bq', str_repeat('x', $cap - 10) . 'b')?->score);
    }

    #[Test]
    public function testOverCapScoresShareTheSubCapScale(): void
    {
        $m = new SmithWatermanMatcher();
        $short = $m->match('foo', 'foobar');
        $long = $m->match('foo', 'foobar' . str_repeat('z', 2000));

        $this->assertNotNull($long);
        $this->assertSame($short->score, $long->score);
        $this->assertSame($short->matchedIndices, $long->matchedIndices);

        // Mixed sub-cap / over-cap candidates rank on one scale.
        $ranked = $m->matchAll('foo', ['xfxoxo', 'foobar' . str_repeat('z', 2000)]);
        $this->assertSame('foobar' . str_repeat('z', 2000), $ranked[0]->haystack);
    }

    #[Test]
    public function testContentPastTheCandidateCapIsNotSearched(): void
    {
        $capped = new SmithWatermanMatcher(maxCandidateLength: 5);

        // The documented trade-off: 'z' exists only beyond the 5-char prefix.
        $this->assertNull($capped->match('z', 'aaaaaz'));
        $this->assertSame(0, $capped->score('z', 'aaaaaz'));
    }

    #[Test]
    public function testInvalidCapThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmithWatermanMatcher(maxQueryLength: 0);
    }

    #[Test]
    public function testInvalidCandidateCapThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmithWatermanMatcher(maxCandidateLength: -1);
    }

    // ---- Early-exit prune preserves results ----

    #[Test]
    public function testEarlyExitPrunePreservesResults(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot', 'banana', 'cherry'];
        $m = new SmithWatermanMatcher();

        // A high minScore triggers the length-based prune for short/weak candidates.
        // Pruning must not change the surviving set vs a full compute + post-filter.
        foreach ([1, 5, 10, 15, 19, 25] as $minScore) {
            $pruned = $m->matchAll('app', $candidates, minScore: $minScore);
            foreach ($pruned as $r) {
                $this->assertGreaterThanOrEqual($minScore, $r->score);
            }
        }
    }

    // ---- Ceiling prune soundness + traceback honesty ----

    #[Test]
    public function testNegativeAdjacentBonusPruneGuardFindsRealBestMatch(): void
    {
        // audit finding 4 (LOW): with matchScore 3 / adjacentBonus -5 the naive
        // per-step ceiling collapses to max(0, 3 + -5) = 0 and a minScore >= 1
        // prune would drop EVERY candidate — yet the real best match scores 3.
        // The ceiling now only prunes when provably sound, so 'a' surfaces.
        $m = new SmithWatermanMatcher(ScoringProfile::new(matchScore: 3, adjacentBonus: -5));

        $pruned = $m->matchAll('a', ['a', 'z'], minScore: 1);
        $this->assertCount(1, $pruned);
        $this->assertSame('a', $pruned[0]->haystack);
        $this->assertSame(3, $pruned[0]->score);

        // Sound profiles keep the fast path: default ceiling 8/step still prunes.
        $this->assertSame([], (new SmithWatermanMatcher())->matchAll('app', ['apple'], minScore: 10_000));
    }

    #[Test]
    public function testTracebackEmitsOnlyGenuineCharMatches(): void
    {
        // audit finding 5 (LOW): under a mismatchPenalty >= 0 profile a mismatch
        // diagonal can win traceback cells. Decision = fix the emit site (clamp
        // to real matches): the score still counts the mismatch step, but a
        // highlighted char must be one the query asked for. 'X' is excluded.
        $m = new SmithWatermanMatcher(ScoringProfile::new(matchScore: 3, mismatchPenalty: 1));
        $result = $m->match('abc', 'abXc');

        $this->assertNotNull($result);
        $this->assertSame(17, $result->score);
        $this->assertSame([0, 1], $result->indices());
    }

    // ---- matchAllGenerator parity ----

    #[Test]
    public function testMatchAllGeneratorReturnsGenerator(): void
    {
        $gen = (new SmithWatermanMatcher())->matchAllGenerator('app', ['apple', 'apply']);
        $this->assertInstanceOf(\Generator::class, $gen);
    }

    #[Test]
    public function testMatchAllGeneratorMatchesMatchAll(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];
        $m = new SmithWatermanMatcher();

        $eager = $m->matchAll('app', $candidates);
        $lazy = iterator_to_array($m->matchAllGenerator('app', $candidates), false);

        $this->assertEquals($eager, $lazy);
    }

    #[Test]
    public function testMatchAllGeneratorHonorsLimitAndMinScore(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];
        $m = new SmithWatermanMatcher();

        $eager = $m->matchAll('app', $candidates, limit: 2, minScore: 5);
        $lazy = iterator_to_array($m->matchAllGenerator('app', $candidates, limit: 2, minScore: 5), false);

        $this->assertEquals($eager, $lazy);
        $this->assertLessThanOrEqual(2, count($lazy));
    }

    #[Test]
    public function testMatchAllGeneratorEmptyQueryYieldsNothing(): void
    {
        $lazy = iterator_to_array((new SmithWatermanMatcher())->matchAllGenerator('', ['a', 'b']), false);
        $this->assertSame([], $lazy);
    }
}
