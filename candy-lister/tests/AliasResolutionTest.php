<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Lister\FuzzyMatch;
use SugarCraft\Lister\ScoringProfile;

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
        $default = ScoringProfile::default();
        $this->assertInstanceOf(\SugarCraft\Fuzzy\ScoringProfile::class, $default);
        $this->assertSame(3, $default->matchScore);
        $this->assertSame(-3, $default->mismatchPenalty);
        $this->assertSame(-5, $default->gapOpen);
        $this->assertSame(-1, $default->gapExtend);
        $this->assertSame(5, $default->adjacentBonus);

        $strict = ScoringProfile::strict();
        $this->assertSame(4, $strict->matchScore);
        $this->assertSame(6, $strict->adjacentBonus);

        $lenient = ScoringProfile::lenient();
        $this->assertSame(2, $lenient->matchScore);
        $this->assertSame(3, $lenient->adjacentBonus);
    }

    public function testAliasedProfileFlowsIntoDelegateMatcher(): void
    {
        // The aliased profile must be accepted by the SSOT-backed FuzzyMatch and
        // actually change scoring (proves it reaches SmithWatermanMatcher).
        $matcher = new FuzzyMatch(ScoringProfile::strict());
        $this->assertSame(24, $matcher->score('abc', 'abc'));
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
