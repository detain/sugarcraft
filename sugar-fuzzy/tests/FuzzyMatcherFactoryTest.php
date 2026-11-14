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
    public function testNamedKeepsScoringNamedArgument(): void
    {
        $this->assertEquals(
            FuzzyMatcherFactory::named('smith-waterman', ScoringProfile::strict()),
            FuzzyMatcherFactory::named(type: 'smith-waterman', scoring: ScoringProfile::strict()),
        );
        $this->expectException(\InvalidArgumentException::class);
        FuzzyMatcherFactory::named('sahilm', ScoringProfile::strict());
    }

    #[Test]
    public function testBannedFactorySpellingsAreGone(): void
    {
        $this->assertFalse(\method_exists(FuzzyMatcherFactory::class, 'create'));
        $this->assertFalse(\method_exists(ScoringProfile::class, 'default'));
    }

    #[Test]
    public function testNamedPassesThroughFilteringShape(): void
    {
        // FUZZY-13 acceptance probe: the factory shape and the direct
        // construction are the same matcher — byte-identical on the whole-query
        // contract (partial hit 'ap' inside 'leopard' is rejected by both).
        $viaFactory = FuzzyMatcherFactory::named('smith-waterman', requireFullQuery: true);
        $direct = new SmithWatermanMatcher(requireFullQuery: true);

        $this->assertEquals($direct, $viaFactory);
        $this->assertNull($viaFactory->match('apr', 'leopard'));
        $this->assertNotNull($direct->match('leopa', 'leopard'));
        // Plain named() keeps partial alignment (all defaults byte-identical).
        $this->assertNotNull(FuzzyMatcherFactory::named('smith-waterman')->match('apr', 'leopard'));
    }

    #[Test]
    public function testNamedPassesThroughCapsAndSahilmCase(): void
    {
        $capped = FuzzyMatcherFactory::named('smith-waterman', maxQueryLength: 2);

        // 'hello' truncated to 'he' exactly like the ctor route (over-length pin
        // precedent in SmithWatermanMatcherTest): score 11 on 'hello'.
        $this->assertSame(11, $capped->score('hello', 'hello'));

        $caseSensitive = FuzzyMatcherFactory::named('sahilm', caseSensitive: true);

        $this->assertInstanceOf(SahilmMatcher::class, $caseSensitive);
        $this->assertTrue($caseSensitive->caseSensitive());
        $this->assertNull($caseSensitive->match('F', 'foo'));
    }

    #[Test]
    public function testNamedRejectsOptionsMismatchedToTheNamedType(): void
    {
        // Fail-fast law (FUZZY-13): silently ignoring a knob the named matcher
        // does not have would build an un-tuned matcher and lie about it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("requireFullQuery/maxQueryLength/maxCandidateLength are 'smith-waterman' options");
        FuzzyMatcherFactory::named('sahilm', requireFullQuery: true);
    }

    #[Test]
    public function testNamedRejectsCaseSensitiveOnSmithWaterman(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("caseSensitive is a 'sahilm' option");
        FuzzyMatcherFactory::named('smith-waterman', caseSensitive: true);
    }

    #[Test]
    public function testForFilteringPresetEqualsTheStrictConstructor(): void
    {
        // FUZZY-13 leg b: the three spellings of the filtering shape agree.
        $this->assertEquals(
            new SmithWatermanMatcher(requireFullQuery: true),
            SmithWatermanMatcher::forFiltering(),
        );
        $this->assertNull(SmithWatermanMatcher::forFiltering()->match('apr', 'leopard'));
    }
}
