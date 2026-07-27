<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\Matcher\FuzzyMatcherFactory;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
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
        $this->assertInstanceOf(SmithWatermanMatcher::class, FuzzyMatcherFactory::create('smith-waterman'));
    }

    #[Test]
    public function testCreateSahilm(): void
    {
        $this->assertInstanceOf(SahilmMatcher::class, FuzzyMatcherFactory::create('sahilm'));
    }

    #[Test]
    public function testUnknownTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown matcher: bogus');
        FuzzyMatcherFactory::create('bogus');
    }

    #[Test]
    public function testSmithWatermanWithoutProfileIsBitEquivalentDefault(): void
    {
        $factory = FuzzyMatcherFactory::create('smith-waterman');
        $this->assertEquals(
            (new SmithWatermanMatcher())->match('foo', 'foobar'),
            $factory->match('foo', 'foobar'),
        );
    }

    #[Test]
    public function testSmithWatermanProfileIsApplied(): void
    {
        /** @var SmithWatermanMatcher $matcher */
        $matcher = FuzzyMatcherFactory::create('smith-waterman', ScoringProfile::strict());

        $this->assertInstanceOf(SmithWatermanMatcher::class, $matcher);
        $this->assertEquals(ScoringProfile::strict(), $matcher->profile());
        // Strict scores higher than default for the same match.
        $this->assertGreaterThan(
            (new SmithWatermanMatcher())->score('abc', 'abc'),
            $matcher->score('abc', 'abc'),
        );
    }

    #[Test]
    public function testProfileIgnoredForSahilm(): void
    {
        // Sahilm has no profile; passing one must not error and yields a plain matcher.
        $matcher = FuzzyMatcherFactory::create('sahilm', ScoringProfile::strict());
        $this->assertInstanceOf(SahilmMatcher::class, $matcher);
        $this->assertEquals((new SahilmMatcher())->match('foo', 'foobar'), $matcher->match('foo', 'foobar'));
    }
}
