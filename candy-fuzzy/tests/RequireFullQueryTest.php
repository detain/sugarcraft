<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\ScoringProfile;

/**
 * Full-query coverage mode: a type-to-filter picker wants every typed char to
 * land, which plain local alignment does not promise (`bxz` "matches" `abcdef`
 * on its `b` alone). Each consumer used to hand-roll the guard. Filtering on the
 * unconstrained best alignment is not enough either: it drops spread-out
 * in-order matches (`gst` in `git status`), so the mode re-aligns those.
 */
#[CoversClass(SmithWatermanMatcher::class)]
final class RequireFullQueryTest extends TestCase
{
    #[Test]
    public function testDefaultModeReportsPartialLocalAlignment(): void
    {
        $result = (new SmithWatermanMatcher())->match('bxz', 'abcdef');

        $this->assertNotNull($result);
        $this->assertSame(3, $result->score);
        $this->assertSame([1], $result->matchedIndices);
    }

    #[Test]
    public function testFullQueryModeDropsPartialAlignment(): void
    {
        $strict = SmithWatermanMatcher::new()->withRequireFullQuery();

        $this->assertNull($strict->match('bxz', 'abcdef'));
        $this->assertSame([], $strict->matchAll('bxz', ['abcdef']));
        $this->assertSame(0, $strict->score('bxz', 'abcdef'));
        $this->assertSame([], iterator_to_array($strict->matchAllGenerator('bxz', ['abcdef'])));
    }

    #[Test]
    public function testFullQueryModeKeepsFullCoverageScoresUnchanged(): void
    {
        $default = new SmithWatermanMatcher();
        $strict = $default->withRequireFullQuery();
        $candidates = ['apple', 'applet', 'apricot', 'banana', 'xapx', 'pa'];

        $kept = $strict->matchAll('app', $candidates);
        $this->assertSame(['apple', 'applet'], array_map(static fn($r) => $r->haystack, $kept));

        foreach ($kept as $result) {
            $this->assertCount(3, $result->matchedIndices);
            $this->assertEquals($default->match('app', $result->haystack), $result);
            $this->assertSame($result->score, $strict->score('app', $result->haystack));
        }

        // Every candidate dropped in strict mode is a partial alignment in default mode.
        $partial = 0;
        foreach ($default->matchAll('app', $candidates) as $result) {
            if (count($result->matchedIndices) < 3) {
                $partial++;
                $this->assertNull($strict->match('app', $result->haystack));
            }
        }
        $this->assertGreaterThan(0, $partial);
    }

    #[Test]
    public function testFullQueryModeCountsCodePointsNotBytes(): void
    {
        $strict = SmithWatermanMatcher::new(requireFullQuery: true);

        $this->assertSame([1, 2], $strict->match('文测', '中文测试')?->matchedIndices);
        $this->assertNull($strict->match('文x', '中文测试'));
    }

    #[Test]
    public function testQueryLongerThanCandidateCannotFullyMatch(): void
    {
        $strict = SmithWatermanMatcher::new()->withRequireFullQuery();

        $this->assertNotNull((new SmithWatermanMatcher())->match('hello', 'hi'));
        $this->assertNull($strict->match('hello', 'hi'));
    }

    #[Test]
    public function testOverCapQueryNeverFullyMatches(): void
    {
        $strict = new SmithWatermanMatcher(maxQueryLength: 3, requireFullQuery: true);

        $this->assertNotNull($strict->match('abc', 'abcdef'));
        // 'abcd' is cut to 'abc' for alignment — never reported as fully covered.
        $this->assertNull($strict->match('abcd', 'abcdef'));
    }

    #[Test]
    public function testWithRequireFullQueryIsImmutableAndKeepsOptions(): void
    {
        $profile = ScoringProfile::strict();
        $base = new SmithWatermanMatcher($profile, 7, 9);
        $strict = $base->withRequireFullQuery();

        $this->assertNotSame($base, $strict);
        $this->assertFalse($base->requiresFullQuery());
        $this->assertTrue($strict->requiresFullQuery());
        $this->assertSame($profile, $strict->profile());
        $this->assertSame(7, $strict->maxQueryLength());
        $this->assertSame(9, $strict->maxCandidateLength());
        $this->assertFalse($strict->withRequireFullQuery(false)->requiresFullQuery());
    }

    #[Test]
    public function testSpreadOutInitialsStillMatch(): void
    {
        $plain = new SmithWatermanMatcher();
        $strict = $plain->withRequireFullQuery();

        // Plain mode's best local run is a fragment: the adjacency bonus makes
        // "st" (and "ab") outscore the spread-out path that covers the query.
        $this->assertSame([4, 5], $plain->match('gst', 'git status')?->matchedIndices);
        $this->assertSame([0, 1], $plain->match('abc', 'abxxxc')?->matchedIndices);

        $gst = $strict->match('gst', 'git status');
        $this->assertNotNull($gst);
        $this->assertSame([0, 4, 5], $gst->matchedIndices);
        $this->assertSame(11, $gst->score);
        $this->assertSame(11, $strict->score('gst', 'git status'));

        $abc = $strict->match('abc', 'abxxxc');
        $this->assertNotNull($abc);
        $this->assertSame([0, 1, 5], $abc->matchedIndices);
        $this->assertSame(11, $abc->score);

        $this->assertSame([0, 4], $strict->match('GS', 'Git Status')?->matchedIndices);

        // 'gist' (13) outranks the two 11s; 'tsg' has every char but out of order.
        $ranked = $strict->matchAll('gst', ['git status', 'gist', 'tsg', 'git stash']);
        $this->assertSame(['gist', 'git stash', 'git status'], array_map(static fn($r) => $r->haystack, $ranked));
        $this->assertEquals($ranked, iterator_to_array($strict->matchAllGenerator('gst', ['git status', 'gist', 'tsg', 'git stash'])));
    }

    #[Test]
    public function testOutOfOrderCharsNeverMatch(): void
    {
        $strict = SmithWatermanMatcher::new(requireFullQuery: true);

        // Every query char occurs in the candidate, but not in order.
        $this->assertNotNull((new SmithWatermanMatcher())->match('cba', 'abc'));
        $this->assertNull($strict->match('cba', 'abc'));
        $this->assertSame(0, $strict->score('cba', 'abc'));
        // Repeated query chars need distinct candidate chars.
        $this->assertNull($strict->match('aa', 'a'));
        $this->assertSame([0, 2], $strict->match('aa', 'aba')?->matchedIndices);
    }

    #[Test]
    public function testGapHeavyInOrderMatchIsFlooredAtOneAndRanksLast(): void
    {
        $strict = SmithWatermanMatcher::new()->withRequireFullQuery();
        $far = 'a' . str_repeat('x', 30) . 'b';

        // 3 + 30 x gapExtend(-1) + 3 is negative, yet both typed chars land.
        $result = $strict->match('ab', $far);
        $this->assertNotNull($result);
        $this->assertSame(1, $result->score);
        $this->assertSame([0, 31], $result->matchedIndices);
        $this->assertSame(1, $strict->score('ab', $far));

        $ranked = $strict->matchAll('ab', [$far, 'xab']);
        $this->assertSame(['xab', $far], array_map(static fn($r) => $r->haystack, $ranked));
        $this->assertSame([], $strict->matchAll('ab', [$far], minScore: 2));
    }

    #[Test]
    public function testDegenerateProfileFloorSurvivesTheCeilingPrune(): void
    {
        // matchScore 0 makes every plain alignment score 0 (no match at all);
        // full-query mode still lists the in-order match at the floor, and the
        // matchAll ceiling prune must not disagree with match().
        $strict = new SmithWatermanMatcher(new ScoringProfile(matchScore: 0, adjacentBonus: 0), requireFullQuery: true);

        $this->assertNull((new SmithWatermanMatcher(new ScoringProfile(matchScore: 0, adjacentBonus: 0)))->match('ab', 'ab'));
        $this->assertSame(1, $strict->match('ab', 'ab')?->score);
        $this->assertSame([0, 1], $strict->match('ab', 'ab')?->matchedIndices);
        $this->assertCount(1, $strict->matchAll('ab', ['ab', 'ba']));
    }

    /**
     * Exhaustive check: for small strings the result must be the best of ALL
     * in-order placements of the query, scored with align()'s step rules —
     * unless plain mode's best already covers the query, in which case the
     * result must be plain mode's, unchanged.
     */
    #[Test]
    public function testCoveringAlignmentMatchesBruteForce(): void
    {
        mt_srand(20261003);
        $alphabet = ['a', 'b', 'c', ' '];

        $profiles = [
            ScoringProfile::canonical(),
            ScoringProfile::strict(),
            ScoringProfile::lenient(),
            // Odd public shapes: a positive skip, and a positive gapOpen with
            // a negative adjacency bonus.
            new ScoringProfile(gapExtend: 1),
            new ScoringProfile(gapOpen: 2, gapExtend: -3, adjacentBonus: -2),
        ];

        foreach ($profiles as $profile) {
            $plain = new SmithWatermanMatcher($profile);
            $strict = $plain->withRequireFullQuery();

            // Candidates up to 22 chars: long enough for a covering path to
            // dip to/below 0 mid-path and compete with another path at the
            // same cell (8-char candidates never reached that).
            for ($t = 0; $t < 900; $t++) {
                $q = $this->randomString($alphabet, 3, mt_rand(1, 5));
                $c = $this->randomString($alphabet, 4, mt_rand(1, $t % 2 === 0 ? 8 : 22));
                $label = "{$q}|{$c}";

                $best = self::bruteBest(str_split($q), str_split($c), $profile);
                $result = $strict->match($q, $c);

                if ($best === null) {
                    $this->assertNull($result, $label);
                    continue;
                }

                $this->assertNotNull($result, $label);
                $this->assertCount(strlen($q), $result->matchedIndices, $label);
                $this->assertSame($result->score, $strict->score($q, $c), $label);

                $unconstrained = $plain->match($q, $c);
                if ($unconstrained !== null && count($unconstrained->matchedIndices) === strlen($q)) {
                    $this->assertEquals($unconstrained, $result, $label);
                    continue;
                }

                $this->assertSame(max(1, $best), $result->score, $label);
                $this->assertSame($best, self::placementScore(str_split($q), str_split($c), $result->matchedIndices, $profile), $label);
            }
        }
    }

    /**
     * Regression: the covering DP used to borrow align()'s value-dependent gap
     * test (`=== 0 ? gapOpen : gapExtend`). With no zero clamp a cell can go
     * negative, so a predecessor at exactly 0 (next skip -5) beat one at -1
     * (next skip -1) and the DP settled on a lower-scoring covering path.
     */
    #[Test]
    public function testCoveringPathThroughAZeroTotalIsNotChargedGapOpen(): void
    {
        $canonical = SmithWatermanMatcher::new(requireFullQuery: true);

        // [2, 7, 10, 11, 14]: 3, four skips -> -1, +3 -> 2, two skips -> 0,
        // 'b' +3 -> 3, adjacent 'a' +8 -> 11, two skips -> 9, adjacent 'a' -> 17.
        // The old DP returned 11 at [0, 2, 10, 11, 14] (which really scores 15).
        $result = $canonical->match('aabaa', 'axacxxxaxcbacaacxbx');
        $this->assertNotNull($result);
        $this->assertSame(17, $result->score);
        $this->assertSame([2, 7, 10, 11, 14], $result->matchedIndices);
        $this->assertSame(17, $canonical->score('aabaa', 'axacxxxaxcbacaacxbx'));
        $this->assertSame(
            17,
            self::placementScore(str_split('aabaa'), str_split('axacxxxaxcbacaacxbx'), [2, 7, 10, 11, 14], ScoringProfile::canonical()),
        );

        $this->assertSame(14, $canonical->match('caaba', 'xcxccxxbxacaxbac')?->score);

        $strict = SmithWatermanMatcher::new(ScoringProfile::strict(), requireFullQuery: true);
        $this->assertSame(16, $strict->match('accbb', 'aaxxxcacxbbcabacxxxcc')?->score);
    }

    /**
     * A covering path ends on its last matched query char. Under a positive
     * gapExtend the DP used to keep skipping past it, crediting trailing
     * candidate chars that are neither matched nor between matches.
     */
    #[Test]
    public function testCoveringPathEndsOnItsLastMatch(): void
    {
        $profile = new ScoringProfile(gapExtend: 1);
        $strict = SmithWatermanMatcher::new($profile, requireFullQuery: true);

        // c@3 (3), one skip (+1), a@5 (3), adjacent c@6 (8) = 15; the old DP
        // also credited the six trailing chars and reported 21.
        $result = $strict->match('cac', 'aaxcxacxcabba');
        $this->assertNotNull($result);
        $this->assertSame(15, $result->score);
        $this->assertSame([3, 5, 6], $result->matchedIndices);
        $this->assertSame(15, $strict->score('cac', 'aaxcxacxcabba'));
    }

    /** @param list<string> $alphabet */
    private function randomString(array $alphabet, int $size, int $length): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[mt_rand(0, $size - 1)];
        }

        return $out;
    }

    /**
     * Score of one in-order placement under align()'s step rules for an
     * unclamped path: a diagonal earns matchScore (+ adjacentBonus when the
     * previous query/candidate chars agree); each candidate char skipped
     * between two matches costs gapExtend — even when the running total
     * happens to be 0, which mid-path is not align()'s restart state.
     *
     * @param list<string> $q
     * @param list<string> $c
     * @param list<int>    $positions
     */
    private static function placementScore(array $q, array $c, array $positions, ScoringProfile $profile): int
    {
        $score = 0;
        foreach ($positions as $k => $pos) {
            if ($k > 0) {
                for ($skip = $positions[$k - 1] + 1; $skip < $pos; $skip++) {
                    $score += $profile->gapExtend;
                }
            }
            $step = $profile->matchScore;
            $qPrev = $k > 0 ? $q[$k - 1] : false;
            $cPrev = $pos > 0 ? $c[$pos - 1] : true;
            if ($step > 0 && $qPrev === $cPrev) {
                $step += $profile->adjacentBonus;
            }
            $score += $step;
        }

        return $score;
    }

    /**
     * @param list<string> $q
     * @param list<string> $c
     * @param list<int>    $positions
     */
    private static function bruteBest(array $q, array $c, ScoringProfile $profile, int $from = 0, array $positions = []): ?int
    {
        $k = count($positions);
        if ($k === count($q)) {
            return self::placementScore($q, $c, $positions, $profile);
        }

        $best = null;
        for ($j = $from, $n = count($c); $j < $n; $j++) {
            if ($c[$j] !== $q[$k]) {
                continue;
            }
            $score = self::bruteBest($q, $c, $profile, $j + 1, [...$positions, $j]);
            if ($score !== null && ($best === null || $score > $best)) {
                $best = $score;
            }
        }

        return $best;
    }

    #[Test]
    public function testPreScreenRespectsProfilesWhereMismatchScores(): void
    {
        // mismatchPenalty > 0 makes a pair with no shared char score; the
        // linear pre-screen must not prune it.
        $m = new SmithWatermanMatcher(new ScoringProfile(mismatchPenalty: 2));

        $this->assertSame(2, $m->match('x', 'y')?->score);
        $this->assertSame(2, $m->score('x', 'y'));
    }
}
