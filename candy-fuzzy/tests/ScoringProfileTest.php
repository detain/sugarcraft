<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\ScoringProfile;
use PHPUnit\Framework\TestCase;

final class ScoringProfileTest extends TestCase
{
    public function testDefaultValuesMatchHistoricalConstants(): void
    {
        // These MUST equal the pre-SSOT hard-coded SmithWatermanMatcher constants
        // (MATCH_SCORE=3, MISMATCH_PENALTY=-3, GAP_OPEN=-5, GAP_EXTEND=-1,
        // ADJACENT_BONUS=5) or matcher output would change — the bit-equivalence
        // guarantee for the whole ecosystem rides on this.
        $p = ScoringProfile::default();

        $this->assertSame(3, $p->matchScore);
        $this->assertSame(-3, $p->mismatchPenalty);
        $this->assertSame(-5, $p->gapOpen);
        $this->assertSame(-1, $p->gapExtend);
        $this->assertSame(5, $p->adjacentBonus);
    }

    public function testConstructorDefaultsEqualDefaultFactory(): void
    {
        $this->assertEquals(new ScoringProfile(), ScoringProfile::default());
    }

    public function testNewFactoryEqualsConstructor(): void
    {
        $this->assertEquals(
            new ScoringProfile(4, -4, -6, -2, 6),
            ScoringProfile::new(4, -4, -6, -2, 6),
        );
    }

    public function testStrictValues(): void
    {
        $p = ScoringProfile::strict();

        $this->assertSame(4, $p->matchScore);
        $this->assertSame(-4, $p->mismatchPenalty);
        $this->assertSame(-6, $p->gapOpen);
        $this->assertSame(-2, $p->gapExtend);
        $this->assertSame(6, $p->adjacentBonus);
    }

    public function testLenientValues(): void
    {
        $p = ScoringProfile::lenient();

        $this->assertSame(2, $p->matchScore);
        $this->assertSame(-2, $p->mismatchPenalty);
        $this->assertSame(-3, $p->gapOpen);
        $this->assertSame(-1, $p->gapExtend);
        $this->assertSame(3, $p->adjacentBonus);
    }

    public function testProfilesAreDistinct(): void
    {
        $this->assertNotEquals(ScoringProfile::default(), ScoringProfile::strict());
        $this->assertNotEquals(ScoringProfile::default(), ScoringProfile::lenient());
        $this->assertNotEquals(ScoringProfile::strict(), ScoringProfile::lenient());
    }

    public function testWithMatchScoreIsImmutable(): void
    {
        $base = ScoringProfile::default();
        $mutated = $base->withMatchScore(9);

        $this->assertSame(3, $base->matchScore, 'original must be unchanged');
        $this->assertSame(9, $mutated->matchScore);
        // Other fields carried over
        $this->assertSame($base->adjacentBonus, $mutated->adjacentBonus);
    }

    public function testAllWithersReturnNewInstances(): void
    {
        $base = ScoringProfile::default();

        $this->assertSame(-9, $base->withMismatchPenalty(-9)->mismatchPenalty);
        $this->assertSame(-9, $base->withGapOpen(-9)->gapOpen);
        $this->assertSame(-9, $base->withGapExtend(-9)->gapExtend);
        $this->assertSame(9, $base->withAdjacentBonus(9)->adjacentBonus);

        // base untouched
        $this->assertEquals(ScoringProfile::default(), $base);
    }
}
