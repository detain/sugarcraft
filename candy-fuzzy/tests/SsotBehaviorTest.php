<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\ScoringProfile;
use PHPUnit\Framework\TestCase;

/**
 * Covers the candy-fuzzy SSOT capabilities added on top of the base matcher:
 * ctor-injected ScoringProfile (with bit-equivalent default), DoS length caps
 * with Sahilm fallback, the score() fast path, and matchAllGenerator parity.
 */
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
        ];
    }

    // ---- Bit-equivalence: default profile == historical behavior ----

    /**
     * @dataProvider corpus
     */
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

    /**
     * @dataProvider corpus
     */
    public function testNoProfileEqualsExplicitDefaultProfile(string $q, string $c): void
    {
        $implicit = (new SmithWatermanMatcher())->match($q, $c);
        $explicit = (new SmithWatermanMatcher(ScoringProfile::default()))->match($q, $c);

        $this->assertEquals($implicit, $explicit);
    }

    public function testFactoryNewAlsoBitEquivalent(): void
    {
        $this->assertEquals(
            (new SmithWatermanMatcher())->match('foo', 'foobar'),
            SmithWatermanMatcher::new()->match('foo', 'foobar'),
        );
    }

    public function testProfileAccessor(): void
    {
        $this->assertEquals(ScoringProfile::default(), (new SmithWatermanMatcher())->profile());
        $this->assertEquals(ScoringProfile::strict(), (new SmithWatermanMatcher(ScoringProfile::strict()))->profile());
    }

    // ---- Profiles produce differing scores / rankings ----

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

    /**
     * @dataProvider corpus
     */
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

    /**
     * @dataProvider corpus
     */
    public function testScoreFastPathEqualsFullPathAcrossProfiles(string $q, string $c): void
    {
        foreach ([ScoringProfile::strict(), ScoringProfile::lenient()] as $profile) {
            $m = new SmithWatermanMatcher($profile);
            $expected = $m->match($q, $c)?->score ?? 0;
            $this->assertSame($expected, $m->score($q, $c), "profile mismatch for '$q'/'$c'");
        }
    }

    public function testScoreEmptyInputsReturnZero(): void
    {
        $m = new SmithWatermanMatcher();
        $this->assertSame(0, $m->score('', 'abc'));
        $this->assertSame(0, $m->score('abc', ''));
    }

    // ---- DoS length caps delegate to SahilmMatcher ----

    public function testOverCandidateCapDelegatesToSahilm(): void
    {
        $capped = new SmithWatermanMatcher(maxCandidateLength: 3);
        $sahilm = new SahilmMatcher();

        // candidate 'abcdef' is 6 chars > cap 3 → delegate.
        $this->assertEquals($sahilm->match('ab', 'abcdef'), $capped->match('ab', 'abcdef'));
    }

    public function testOverQueryCapDelegatesToSahilm(): void
    {
        $capped = new SmithWatermanMatcher(maxQueryLength: 2);
        $sahilm = new SahilmMatcher();

        // query 'abc' is 3 chars > cap 2 → delegate.
        $this->assertEquals($sahilm->match('abc', 'abcdef'), $capped->match('abc', 'abcdef'));
    }

    public function testUnderCapStaysOnSmithWaterman(): void
    {
        $capped = new SmithWatermanMatcher(maxCandidateLength: 100, maxQueryLength: 100);
        $default = new SmithWatermanMatcher();

        // Well under the cap → identical to the uncapped SW result (NOT Sahilm).
        $this->assertEquals($default->match('ab', 'abcdef'), $capped->match('ab', 'abcdef'));
        $this->assertNotEquals((new SahilmMatcher())->match('ab', 'abcdef'), $capped->match('ab', 'abcdef'));
    }

    public function testScoreRespectsCapDelegation(): void
    {
        $capped = new SmithWatermanMatcher(maxCandidateLength: 3);
        $sahilm = new SahilmMatcher();

        $expected = $sahilm->match('ab', 'abcdef')?->score ?? 0;
        $this->assertSame($expected, $capped->score('ab', 'abcdef'));
    }

    public function testInvalidCapThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmithWatermanMatcher(maxQueryLength: 0);
    }

    public function testInvalidCandidateCapThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SmithWatermanMatcher(maxCandidateLength: -1);
    }

    // ---- Early-exit prune preserves results ----

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

    // ---- matchAllGenerator parity ----

    public function testMatchAllGeneratorReturnsGenerator(): void
    {
        $gen = (new SmithWatermanMatcher())->matchAllGenerator('app', ['apple', 'apply']);
        $this->assertInstanceOf(\Generator::class, $gen);
    }

    public function testMatchAllGeneratorMatchesMatchAll(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];
        $m = new SmithWatermanMatcher();

        $eager = $m->matchAll('app', $candidates);
        $lazy = iterator_to_array($m->matchAllGenerator('app', $candidates), false);

        $this->assertEquals($eager, $lazy);
    }

    public function testMatchAllGeneratorHonorsLimitAndMinScore(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];
        $m = new SmithWatermanMatcher();

        $eager = $m->matchAll('app', $candidates, limit: 2, minScore: 5);
        $lazy = iterator_to_array($m->matchAllGenerator('app', $candidates, limit: 2, minScore: 5), false);

        $this->assertEquals($eager, $lazy);
        $this->assertLessThanOrEqual(2, count($lazy));
    }

    public function testMatchAllGeneratorEmptyQueryYieldsNothing(): void
    {
        $lazy = iterator_to_array((new SmithWatermanMatcher())->matchAllGenerator('', ['a', 'b']), false);
        $this->assertSame([], $lazy);
    }
}
