<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\Matcher\FuzzyMatcherFactory;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\SahilmScoring;
use SugarCraft\Fuzzy\ScoringProfile;
use PHPUnit\Framework\TestCase;

#[CoversClass(FuzzyMatcherFactory::class)]
#[CoversClass(SmithWatermanMatcher::class)]
#[CoversClass(SahilmMatcher::class)]
final class FuzzyMatcherFactoryTest extends TestCase
{
    #[Test]
    public function testCreateSmithWaterman(): void
    {
        $this->assertInstanceOf(SmithWatermanMatcher::class, FuzzyMatcherFactory::named('smith-waterman'));
    }

    #[Test]
    public function testCreateSahilm(): void
    {
        $this->assertInstanceOf(SahilmMatcher::class, FuzzyMatcherFactory::named('sahilm'));
    }

    #[Test]
    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown matcher: bogus');
        FuzzyMatcherFactory::named('bogus');
    }

    #[Test]
    public function testSmithWatermanWithoutProfileIsBitEquivalentDefault(): void
    {
        $factory = FuzzyMatcherFactory::named('smith-waterman');
        $this->assertEquals(
            (new SmithWatermanMatcher())->match('foo', 'foobar'),
            $factory->match('foo', 'foobar'),
        );
    }

    #[Test]
    public function testSmithWatermanProfileIsApplied(): void
    {
        /** @var SmithWatermanMatcher $matcher */
        $matcher = FuzzyMatcherFactory::named('smith-waterman', ScoringProfile::strict());

        $this->assertInstanceOf(SmithWatermanMatcher::class, $matcher);
        $this->assertEquals(ScoringProfile::strict(), $matcher->profile());
        // Strict scores higher than default for the same match.
        $this->assertGreaterThan(
            (new SmithWatermanMatcher())->score('abc', 'abc'),
            $matcher->score('abc', 'abc'),
        );
    }

    /**
     * Regression: a ScoringProfile passed with 'sahilm' used to be dropped
     * silently, handing the caller an un-tuned matcher.
     */
    #[Test]
    public function testScoringProfileWithSahilmThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("ScoringProfile applies to 'smith-waterman' only");
        FuzzyMatcherFactory::named('sahilm', ScoringProfile::strict());
    }

    #[Test]
    public function testSahilmScoringWithSmithWatermanThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("SahilmScoring applies to 'sahilm' only");
        FuzzyMatcherFactory::named('smith-waterman', SahilmScoring::canonical());
    }

    #[Test]
    public function testSahilmScoringIsApplied(): void
    {
        $scoring = SahilmScoring::canonical()->withFirstCharBonus(100);
        /** @var SahilmMatcher $matcher */
        $matcher = FuzzyMatcherFactory::named('sahilm', $scoring);

        $this->assertInstanceOf(SahilmMatcher::class, $matcher);
        $this->assertSame($scoring, $matcher->scoring());
        $this->assertEquals(
            (new SahilmMatcher(false, $scoring))->match('foo', 'foobar'),
            $matcher->match('foo', 'foobar'),
        );
    }

    #[Test]
    public function testSahilmWithoutScoringIsCanonical(): void
    {
        $matcher = FuzzyMatcherFactory::named('sahilm');
        $this->assertEquals((new SahilmMatcher())->match('foo', 'foobar'), $matcher->match('foo', 'foobar'));
    }

    #[Test]
    public function testDeprecatedCreateForwardsToNamed(): void
    {
        $this->assertEquals(
            FuzzyMatcherFactory::named('smith-waterman', ScoringProfile::strict()),
            FuzzyMatcherFactory::create('smith-waterman', ScoringProfile::strict()),
        );
        $this->expectException(\InvalidArgumentException::class);
        FuzzyMatcherFactory::create('sahilm', ScoringProfile::strict());
    }
}
