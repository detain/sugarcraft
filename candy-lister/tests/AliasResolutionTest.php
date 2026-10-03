<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Lister\FuzzyMatch;
use SugarCraft\Lister\ScoringProfile;
use SugarCraft\Lister\StringItem;

/**
 * Guards the delegation wiring to the candy-fuzzy / candy-async SSOTs.
 *
 * `SugarCraft\Lister\ScoringProfile` is a class_alias re-export of
 * `SugarCraft\Fuzzy\ScoringProfile` (candy-fuzzy's superset). These assertions
 * fail loudly if the alias file is dropped, the target namespace drifts, or the
 * candy-async dependency (referenced by Model doc-comments) goes missing.
 */
final class AliasResolutionTest extends TestCase
{
    public function testScoringProfileIsAliasOfFuzzySsot(): void
    {
        $this->assertTrue(
            class_exists(ScoringProfile::class),
            'SugarCraft\Lister\ScoringProfile must autoload (class_alias shim).',
        );

        // The alias and the SSOT target are the SAME class, not merely instanceof.
        $this->assertSame(
            \SugarCraft\Fuzzy\ScoringProfile::class,
            (new \ReflectionClass(ScoringProfile::class))->getName(),
            'ScoringProfile must resolve to the candy-fuzzy SSOT class.',
        );
    }

    public function testAliasedProfileFactoriesPreserveValues(): void
    {
        $canonical = ScoringProfile::canonical();
        $this->assertInstanceOf(\SugarCraft\Fuzzy\ScoringProfile::class, $canonical);
        $this->assertSame(3, $canonical->matchScore);
        $this->assertSame(-3, $canonical->mismatchPenalty);
        $this->assertSame(-5, $canonical->gapOpen);
        $this->assertSame(-1, $canonical->gapExtend);
        $this->assertSame(5, $canonical->adjacentBonus);

        $strict = ScoringProfile::strict();
        $this->assertSame(4, $strict->matchScore);
        $this->assertSame(6, $strict->adjacentBonus);

        $lenient = ScoringProfile::lenient();
        $this->assertSame(2, $lenient->matchScore);
        $this->assertSame(3, $lenient->adjacentBonus);
    }

    public function testDeprecatedDefaultFactoryStillResolvesThroughAlias(): void
    {
        // External consumers still call SugarCraft\Lister\ScoringProfile::default();
        // the deprecated spelling must keep producing the canonical weights.
        $this->assertEquals(ScoringProfile::canonical(), ScoringProfile::default());
    }

    public function testLibrarySourceAvoidsDeprecatedScoringProfileDefault(): void
    {
        // candy-fuzzy deprecated ScoringProfile::default() in favour of
        // canonical(); this lib's own code must not call the deprecated spelling.
        $offenders = [];
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) \file_get_contents($file->getPathname());
            if (\preg_match('/ScoringProfile::default\s*\(/', $code) === 1) {
                $offenders[] = $file->getFilename();
            }
        }
        $this->assertSame([], $offenders, 'Use ScoringProfile::canonical() instead of the deprecated default().');
    }

    public function testOverCapCandidateScoresOnTheSameScale(): void
    {
        // candy-fuzzy aligns an over-cap candidate on its prefix rather than
        // handing it to a differently-scaled Sahilm fallback, so a very long
        // item ranks consistently against short ones in match().
        $matcher = new FuzzyMatch();
        $long = 'abc' . \str_repeat('x', 1200);
        $this->assertSame($matcher->score('abc', 'abc'), $matcher->score('abc', $long));

        $ranked = $matcher->match('abc', [new StringItem($long), new StringItem('abc')]);
        $this->assertSame([19, 19], \array_column($ranked, 1));
        // Equal scores keep input order — the long item stays first.
        $this->assertSame($long, (string) $ranked[0][0]);
    }

    public function testAliasedProfileFlowsIntoDelegateMatcher(): void
    {
        // The aliased profile must be accepted by the SSOT-backed FuzzyMatch and
        // actually change scoring (proves it reaches SmithWatermanMatcher).
        $matcher = new FuzzyMatch(ScoringProfile::strict());
        $this->assertSame(24, $matcher->score('abc', 'abc'));
    }

    public function testWithProfileRebuildsDelegateMatcher(): void
    {
        // withProfile() must rebuild the delegate SmithWatermanMatcher from the
        // aliased profile: strict abc/abc = 24 (vs the canonical profile's 19).
        // Pins the only FuzzyMatch public method not exercised elsewhere after
        // the duplicate scoring characterization was removed.
        $default = new FuzzyMatch();
        $this->assertSame(19, $default->score('abc', 'abc'));

        $strict = $default->withProfile(ScoringProfile::strict());
        $this->assertSame(24, $strict->score('abc', 'abc'));

        // withProfile is immutable — the original matcher keeps its profile.
        $this->assertSame(19, $default->score('abc', 'abc'));
    }

    public function testCandyAsyncCancellationExceptionResolves(): void
    {
        // Model doc-comments reference this class as the intended cancellation
        // signal; the candy-async dependency must make it loadable.
        $this->assertTrue(
            class_exists(\SugarCraft\Async\OperationCancelledException::class),
            'candy-async OperationCancelledException must be available as a dependency.',
        );
    }
}
