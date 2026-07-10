<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Lister\FuzzyMatch;
use SugarCraft\Lister\ScoringProfile;
use SugarCraft\Lister\StringItem;

/**
 * Bit-equivalence characterization for the candy-fuzzy-delegating FuzzyMatch.
 *
 * The scores/rankings pinned here were captured from candy-lister's original
 * hand-rolled Smith-Waterman implementation (pre-delegation). They MUST survive
 * the delegation to `SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher` byte-for-byte.
 * If delegation regresses (e.g. wrong profile mapping, wrong case-folding), these
 * exact integers diverge and the suite fails.
 */
final class FuzzyMatchCharacterizationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function defaultScores(): iterable
    {
        yield 'empty query' => ['', 'hello', 0];
        yield 'empty candidate' => ['hello', '', 0];
        yield 'no match' => ['xyz', 'abc', 0];
        yield 'exact abc' => ['abc', 'abc', 19];
        yield 'case-insensitive ABC' => ['abc', 'ABC', 19];
        yield 'scattered axbxc' => ['abc', 'axbxc', 7];
        yield 'interleaved AaBbCc' => ['abc', 'AaBbCc', 18];
        yield 'prefix ap/apple' => ['ap', 'apple', 11];
        yield 'ap/Application' => ['ap', 'Application', 11];
        yield 'accented café' => ['café', 'Café Latté', 27];
        yield 'greek case fold' => ['ΑΒ', 'ΑΒΓ', 11];
        yield 'apple/apple' => ['apple', 'apple', 35];
        yield 'apple/Application' => ['apple', 'Application', 27];
        yield 'aa/banana' => ['aa', 'banana', 5];
        yield 'aa/AaBbCc' => ['aa', 'AaBbCc', 11];
    }

    /**
     * @dataProvider defaultScores
     */
    public function testDefaultProfileScoresArePinned(string $query, string $candidate, int $expected): void
    {
        $this->assertSame($expected, (new FuzzyMatch())->score($query, $candidate));
    }

    /**
     * @return iterable<string, array{ScoringProfile, string, string, int}>
     */
    public static function tunedScores(): iterable
    {
        yield 'strict abc/abc' => [ScoringProfile::strict(), 'abc', 'abc', 24];
        yield 'strict café' => [ScoringProfile::strict(), 'café', 'Café Latté', 34];
        yield 'strict greek' => [ScoringProfile::strict(), 'ΑΒ', 'ΑΒΓ', 14];
        yield 'lenient abc/abc' => [ScoringProfile::lenient(), 'abc', 'abc', 12];
        yield 'lenient café' => [ScoringProfile::lenient(), 'café', 'Café Latté', 17];
        yield 'lenient apple' => [ScoringProfile::lenient(), 'apple', 'apple', 22];
        yield 'lenient greek' => [ScoringProfile::lenient(), 'ΑΒ', 'ΑΒΓ', 7];
    }

    /**
     * @dataProvider tunedScores
     */
    public function testTunedProfileScoresArePinned(ScoringProfile $profile, string $query, string $candidate, int $expected): void
    {
        $this->assertSame($expected, (new FuzzyMatch($profile))->score($query, $candidate));
    }

    public function testWithProfileSwapsScoring(): void
    {
        // withProfile(strict) must rebuild the delegate matcher: strict abc/abc = 24 (vs default 19).
        $this->assertSame(24, (new FuzzyMatch())->withProfile(ScoringProfile::strict())->score('abc', 'abc'));
    }

    public function testMatchRankingIsPinned(): void
    {
        $items = array_map(
            static fn (string $s): StringItem => new StringItem($s),
            ['apple', 'apricot', 'banana', 'cherry', 'ABC', 'abc', 'axbxc', 'test', 'Café Latté', 'ΑΒΓ', 'AaBbCc', 'Application', 'nanan'],
        );

        $flatten = static function (array $result): array {
            return array_map(static fn (array $pair): string => (string) $pair[0] . ':' . $pair[1], $result);
        };

        // Equal-score groups keep INPUT order (stable sort) — candy-lister's contract.
        $this->assertSame(
            ['apple:11', 'apricot:11', 'Application:11', 'banana:3', 'ABC:3', 'abc:3', 'axbxc:3', 'Café Latté:3', 'AaBbCc:3', 'nanan:3'],
            $flatten((new FuzzyMatch())->match('ap', $items)),
        );

        $this->assertSame(
            ['ABC:19', 'abc:19', 'AaBbCc:18', 'axbxc:7', 'apple:3', 'apricot:3', 'banana:3', 'cherry:3', 'Café Latté:3', 'Application:3', 'nanan:3'],
            $flatten((new FuzzyMatch())->match('abc', $items)),
        );

        $this->assertSame(
            ['apple:35', 'Application:27', 'apricot:11', 'banana:3', 'cherry:3', 'ABC:3', 'abc:3', 'axbxc:3', 'test:3', 'Café Latté:3', 'AaBbCc:3', 'nanan:3'],
            $flatten((new FuzzyMatch())->match('apple', $items)),
        );
    }
}
