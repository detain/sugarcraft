<?php

declare(strict_types=1);

namespace SugarCraft\TreeList\Tests;

use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\TreeList\FuzzyMatch;
use SugarCraft\TreeList\ScoringProfile;
use SugarCraft\TreeList\StringItem;
use PHPUnit\Framework\TestCase;

final class FuzzyMatchTest extends TestCase
{
    private FuzzyMatch $matcher;

    protected function setUp(): void
    {
        $this->matcher = new FuzzyMatch();
    }

    public function testScoreEmptyQueryReturnsZero(): void
    {
        $this->assertSame(0, $this->matcher->score('', 'hello'));
    }

    /** sugar-treelist-027: the house factory mirrors the constructor (defaults, knobs, refusal). */
    public function testNewFactoryMirrorsConstructor(): void
    {
        $f = FuzzyMatch::new();
        $this->assertSame($this->matcher->score('hel', 'hello'), $f->score('hel', 'hello'));

        $strict = FuzzyMatch::new(strictMode: true);
        $this->assertSame([], $strict->match('bxz', ['abcdef']), 'strict: lone b does not cover the query');
        $default = $f->match('bxz', ['abcdef']);
        $this->assertNotSame([], $default, 'default: scattered hit still matches');
        $this->assertSame('abcdef', $default[0][0]);

        $this->expectException(\InvalidArgumentException::class);
        FuzzyMatch::new(maxCandidateLength: 0);
    }

    public function testScoreEmptyCandidateReturnsZero(): void
    {
        // Empty candidate is a non-match; return 0 (match() drops non-positive scores).
        $this->assertSame(0, $this->matcher->score('hello', ''));
    }

    public function testScoreExactMatchIsPositive(): void
    {
        $score = $this->matcher->score('abc', 'abc');
        $this->assertGreaterThan(0, $score);
    }

    public function testScoreNoMatchIsLowOrZero(): void
    {
        $score = $this->matcher->score('xyz', 'abc');
        $this->assertLessThanOrEqual(0, $score);
    }

    public function testScoreConsecutiveMatchesHigherThanScattered(): void
    {
        $scattered = $this->matcher->score('abc', 'axbxc');
        $consecutive = $this->matcher->score('abc', 'abc');
        $this->assertGreaterThan($scattered, $consecutive);
    }

    public function testScoreCaseInsensitive(): void
    {
        $lower = $this->matcher->score('abc', 'abc');
        $mixed = $this->matcher->score('abc', 'ABC');
        $this->assertSame($lower, $mixed);
    }

    public function testMatchEmptyQueryReturnsEmpty(): void
    {
        $items = [new StringItem('apple'), new StringItem('banana')];
        $result = $this->matcher->match('', $items);
        $this->assertSame([], $result);
    }

    public function testMatchEmptyItemsReturnsEmpty(): void
    {
        $result = $this->matcher->match('app', []);
        $this->assertSame([], $result);
    }

    public function testMatchReturnsScoredCandidates(): void
    {
        $items = [
            new StringItem('apple'),
            new StringItem('apricot'),
            new StringItem('banana'),
            new StringItem('cherry'),
        ];
        $result = $this->matcher->match('ap', $items);

        // apple and apricot get score 11 each (consecutive 'a','p')
        // banana gets score 3 (scattered 'a' and 'p')
        $this->assertCount(3, $result);
        // Sorted by score descending
        $this->assertGreaterThanOrEqual($result[1][1], $result[0][1]);
        // Verify scores are positive
        foreach ($result as [$item, $score]) {
            $this->assertInstanceOf(\Stringable::class, $item);
            $this->assertGreaterThan(0, $score);
        }
    }

    public function testMatchNonMatchingReturnsEmpty(): void
    {
        // 'zzz' has no matches in these items
        $items = [new StringItem('banana'), new StringItem('cherry')];
        $result = $this->matcher->match('zzz', $items);
        $this->assertSame([], $result);
    }

    public function testMatchResultStructure(): void
    {
        $items = [new StringItem('test')];
        $result = $this->matcher->match('tes', $items);

        $this->assertCount(1, $result);
        [$item, $score] = $result[0];
        $this->assertSame('test', (string) $item);
        $this->assertIsInt($score);
    }

    /**
     * Audit #16: the documented deviation from SmithWatermanMatcher::matchAll()
     * (haystack-ascending tiebreak) is input-order stability for equal scores.
     * It had no pin — the three candidates below score identically (11), so a
     * silent switch to the SSOT tiebreak would reorder them unnoticed.
     */
    public function testMatchKeepsInputOrderForEqualScores(): void
    {
        $items = [new StringItem('abx'), new StringItem('aby'), new StringItem('abz')];
        $result = $this->matcher->match('ab', $items);

        $scores = \array_column($result, 1);
        $this->assertCount(3, $result);
        $this->assertSame($scores[0], $scores[1]);
        $this->assertSame($scores[1], $scores[2]);
        $this->assertSame(
            ['abx', 'aby', 'abz'],
            \array_map(static fn(array $pair): string => (string) $pair[0], $result),
            'equal scores must preserve input order (sugar-treelist contract)'
        );
    }

    // -- pass-through knobs (sugar-treelist-015/033/037) ---------------------

    public function testMatchMinScoreDropsIncidentalAlignments(): void
    {
        // Measured canonical scores: 'September' carries the real hit (19);
        // April/June/November only share a stray letter and align at 3.
        $items = [
            new StringItem('April'),
            new StringItem('September'),
            new StringItem('June'),
            new StringItem('November'),
        ];
        $this->assertCount(4, $this->matcher->match('sep', $items));

        $kept = $this->matcher->match('sep', $items, minScore: 8);
        $this->assertCount(1, $kept);
        $this->assertSame('September', (string) $kept[0][0]);
        $this->assertSame(19, $kept[0][1]);
    }

    public function testMatchMinScoreIsAThresholdNotARescale(): void
    {
        // Surviving scores must be the exact scorer output — the threshold
        // only drops, it never rewrites (results stay comparable to score()).
        $items = [new StringItem('September'), new StringItem('April')];
        $kept  = $this->matcher->match('sep', $items, minScore: 8);

        $this->assertSame(
            $this->matcher->score('sep', 'September'),
            $kept[0][1],
        );
    }

    public function testMatchThresholdNeverAdmitsAZeroScoreCandidate(): void
    {
        // 'q' cannot align into 'bbb' at all; a non-positive minScore relaxes
        // the caller's threshold but not the alignment floor.
        $items = [new StringItem('bbb')];
        $this->assertSame([], $this->matcher->match('q', $items, minScore: 0));
    }

    public function testMatchLimitTrimsAfterTheTieBreak(): void
    {
        $items = [
            new StringItem('abx'),
            new StringItem('aby'),
            new StringItem('abz'),
        ];
        $kept = $this->matcher->match('ab', $items, limit: 2);

        $this->assertSame(
            ['abx', 'aby'],
            \array_map(static fn(array $pair): string => (string) $pair[0], $kept),
            'limit must cut the input-order-tiebroken ranking, not an arbitrary prefix',
        );
        $this->assertSame([], $this->matcher->match('ab', $items, limit: 0));
    }

    public function testMatchNegativeLimitIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->matcher->match('ab', [new StringItem('abx')], limit: -1);
    }

    public function testMatchGeneratorYieldsTheRankedPairs(): void
    {
        $items = [
            new StringItem('abx'),
            new StringItem('September'),
            new StringItem('aby'),
        ];
        $fromGenerator = \iterator_to_array($this->matcher->matchGenerator('sep', $items));
        $this->assertSame($this->matcher->match('sep', $items), $fromGenerator);

        $capped = \iterator_to_array($this->matcher->matchGenerator('ab', $items, limit: 1));
        $this->assertSame($this->matcher->match('ab', $items, limit: 1), $capped);
    }

    public function testStrictModeRefusesQueriesThatDoNotCoverTheCandidate(): void
    {
        $items = [new StringItem('abcdef'), new StringItem('ebxzf')];

        // Plain local alignment: 'abcdef' rides on its lone 'b' for 3.
        $plain = $this->matcher->match('bxz', $items);
        $this->assertCount(2, $plain);

        $strict = $this->matcher->withStrictMode()->match('bxz', $items);
        $this->assertCount(1, $strict);
        $this->assertSame('ebxzf', (string) $strict[0][0]);
        $this->assertTrue($this->matcher->withStrictMode()->isStrictMode());
        $this->assertFalse($this->matcher->isStrictMode());
    }

    public function testCandidateCapLimitsTheSearchedPrefix(): void
    {
        $items = [new StringItem('abcdef')];

        $this->assertSame(19, $this->matcher->score('def', 'abcdef'));
        // Cap 4 searches 'abcd' only: 'def' survives on the stray 'd' (3).
        $capped = $this->matcher->withCandidateCap(4);
        $this->assertSame(3, $capped->score('def', 'abcdef'));
        $this->assertSame(4, $capped->candidateCap());
        $this->assertSame(
            SmithWatermanMatcher::DEFAULT_MAX_CANDIDATE_LENGTH,
            $this->matcher->candidateCap(),
        );
    }

    public function testCandidateCapBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->matcher->withCandidateCap(0);
    }

    public function testConstructorRefusesACapBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FuzzyMatch(null, 0);
    }

    public function testKnobsSurviveAProfileSwap(): void
    {
        $matcher = (new FuzzyMatch())
            ->withCandidateCap(4)
            ->withStrictMode()
            ->withProfile(ScoringProfile::strict());

        $this->assertSame(4, $matcher->candidateCap());
        $this->assertTrue($matcher->isStrictMode());
        // Cap still trims the search and strictness still refuses coverage
        // failures after the weights changed.
        $this->assertSame([], $matcher->match('def', [new StringItem('abcdef')]));
    }

    public function testDuplicateStringsAreScoredConsistentlyAndKeepInputOrder(): void
    {
        $items = [
            new StringItem('abx'),
            new StringItem('aby'),
            new StringItem('abx'),
            new StringItem('abz'),
            new StringItem('abx'),
        ];
        $result = $this->matcher->match('ab', $items);

        $this->assertSame(
            ['abx', 'aby', 'abx', 'abz', 'abx'],
            \array_map(static fn(array $pair): string => (string) $pair[0], $result),
            'duplicate values each appear once per occurrence, in input order',
        );
        foreach ($result as $index => [$item, $score]) {
            $this->assertSame($this->matcher->score('ab', (string) $item), $score, "score at position {$index}");
        }
    }

    public function testCustomProfileShapesTheReportedScores(): void
    {
        $matcher = new FuzzyMatch(new ScoringProfile(matchScore: 5, adjacentBonus: 0));

        // Measured: 5 + 5 adjacency-free pair = 10 where canonical gives 11.
        $this->assertSame(10, $matcher->score('ab', 'abx'));
        $result  = $matcher->match('ab', [new StringItem('abx')]);
        $this->assertSame(10, $result[0][1]);
    }
}
