<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Fuzzy;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Guards the deprecated {@see FuzzyMatcher} backward-compatibility shim.
 *
 * `SugarCraft\Forms\Fuzzy\FuzzyMatcher` used to carry its own hand-rolled
 * Smith-Waterman DP core, a byte-for-byte duplicate of the candy-fuzzy SSOT.
 * It is now a thin delegating shim over {@see SmithWatermanMatcher}. These
 * tests prove:
 *  - the deprecated class still resolves and instantiates;
 *  - it genuinely delegates to the candy-fuzzy SSOT (not a re-diverged copy);
 *  - its scores stay bit-equivalent to the historical algorithm over an ASCII
 *    corpus captured BEFORE the conversion (see fixtures/legacy_ascii_scores.json);
 *  - its legacy `match(query, array)` ranking is identical to driving the SSOT
 *    directly.
 *
 * Revert-prove: break the delegation (swap the profile, drop the empty-candidate
 * branch, or re-inline a divergent DP core) and the corpus/identity assertions
 * fail.
 */
final class AliasResolutionTest extends TestCase
{
    public function testDeprecatedClassResolves(): void
    {
        $this->assertTrue(
            class_exists(FuzzyMatcher::class),
            'Deprecated SugarCraft\\Forms\\Fuzzy\\FuzzyMatcher must still exist for backward compatibility.'
        );
    }

    public function testDelegatesToCandyFuzzySsot(): void
    {
        $matcher = new FuzzyMatcher();

        $prop = (new \ReflectionClass($matcher))->getProperty('delegate');
        $delegate = $prop->getValue($matcher);

        $this->assertInstanceOf(
            SmithWatermanMatcher::class,
            $delegate,
            'FuzzyMatcher must delegate to the candy-fuzzy SmithWatermanMatcher SSOT.'
        );
    }

    /**
     * Bit-equivalence characterization: every fixture score, captured from the
     * pre-shim byte-based algorithm, must still be produced exactly.
     *
     * @dataProvider legacyScoreProvider
     */
    public function testScoreBitEquivalentToLegacyCorpus(string $query, string $candidate, int $expected): void
    {
        $this->assertSame(
            $expected,
            (new FuzzyMatcher())->score($query, $candidate),
            sprintf('score(%s, %s) diverged from the historical algorithm.', var_export($query, true), var_export($candidate, true))
        );
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function legacyScoreProvider(): iterable
    {
        $path = __DIR__ . '/fixtures/legacy_ascii_scores.json';
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        foreach ($data['scores'] as $i => [$query, $candidate, $score]) {
            yield sprintf('#%d %s~%s', $i, $query, $candidate) => [$query, $candidate, $score];
        }
    }

    public function testEmptyCandidateKeepsLegacyNegativeScore(): void
    {
        // The SSOT returns 0 for an empty candidate; the shim preserves the
        // legacy gap-only negative score (gapOpen + gapExtend * strlen(query)).
        $matcher = new FuzzyMatcher();

        $this->assertSame(-10, $matcher->score('hello', ''));
        $this->assertSame(-6, $matcher->score('a', ''));
        $this->assertSame(0, $matcher->score('', ''));
    }

    /**
     * Delegation identity: for every non-empty ASCII pair, the shim's score is
     * exactly what the SSOT produces on its own.
     *
     * @dataProvider legacyScoreProvider
     */
    public function testScoreIsIdenticalToSsotForNonEmptyCandidate(string $query, string $candidate, int $expected): void
    {
        if ($candidate === '') {
            $this->assertTrue(true); // empty-candidate boundary covered elsewhere

            return;
        }

        $this->assertSame(
            SmithWatermanMatcher::new()->score($query, $candidate),
            (new FuzzyMatcher())->score($query, $candidate),
            'Shim score must match the candy-fuzzy SSOT for non-empty candidates.'
        );
    }

    public function testMatchRankingIdenticalToDrivingSsotDirectly(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot', 'ape', 'apart', 'banana', 'abc'];
        $ssot = SmithWatermanMatcher::new();

        foreach (['app', 'ap', 'ate', 'xyz', 'a'] as $query) {
            // Reproduce the legacy match() contract by driving the SSOT directly.
            $expected = [];
            foreach ($candidates as $candidate) {
                $score = $ssot->score($query, $candidate);
                if ($score > 0) {
                    $expected[] = [$candidate, $score];
                }
            }
            usort($expected, static fn(array $a, array $b): int => $b[1] <=> $a[1]);

            $this->assertSame(
                $expected,
                (new FuzzyMatcher())->match($query, $candidates),
                "match('{$query}', ...) must equal driving the candy-fuzzy SSOT directly."
            );
        }
    }
}
