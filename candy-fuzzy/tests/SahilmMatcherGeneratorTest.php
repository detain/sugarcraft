<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\MatchResult;
use PHPUnit\Framework\TestCase;

#[CoversClass(SahilmMatcher::class)]
final class SahilmMatcherGeneratorTest extends TestCase
{
    private SahilmMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SahilmMatcher();
    }

    #[Test]
    public function testMatchAllGeneratorReturnsGenerator(): void
    {
        $gen = $this->matcher->matchAllGenerator('app', ['apple', 'apply']);

        $this->assertInstanceOf(\Generator::class, $gen);
    }

    #[Test]
    public function testMatchAllGeneratorEmptyQueryYieldsNothing(): void
    {
        $lazy = iterator_to_array($this->matcher->matchAllGenerator('', ['a', 'b']), false);

        $this->assertSame([], $lazy);
    }

    #[Test]
    public function testMatchAllGeneratorMatchesMatchAll(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $eager = $this->matcher->matchAll('app', $candidates);
        $lazy = iterator_to_array($this->matcher->matchAllGenerator('app', $candidates), false);

        $this->assertEquals($eager, $lazy);
    }

    #[Test]
    public function testMatchAllGeneratorRespectsLimit(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $eager = $this->matcher->matchAll('app', $candidates, limit: 2);
        $lazy = iterator_to_array($this->matcher->matchAllGenerator('app', $candidates, limit: 2), false);

        $this->assertCount(2, $lazy);
        $this->assertEquals($eager, $lazy);
    }

    #[Test]
    public function testMatchAllGeneratorRespectsMinScore(): void
    {
        $candidates = ['hello', 'hey', 'h', 'xyz'];

        $eager = $this->matcher->matchAll('he', $candidates, minScore: 10);
        $lazy = iterator_to_array($this->matcher->matchAllGenerator('he', $candidates, minScore: 10), false);

        $this->assertEquals($eager, $lazy);
        foreach ($lazy as $result) {
            $this->assertGreaterThanOrEqual(10, $result->score);
        }
    }

    #[Test]
    public function testMatchAllGeneratorEmptyCandidatesYieldsNothing(): void
    {
        $lazy = iterator_to_array($this->matcher->matchAllGenerator('app', []), false);

        $this->assertSame([], $lazy);
    }

    #[Test]
    public function testMatchAllGeneratorYieldsResultsSortedByScoreDesc(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $results = iterator_to_array($this->matcher->matchAllGenerator('app', $candidates), false);

        $this->assertNotEmpty($results);
        $resultCount = count($results);
        for ($i = 1; $i < $resultCount; $i++) {
            $this->assertGreaterThanOrEqual($results[$i]->score, $results[$i - 1]->score);
        }
    }
}
