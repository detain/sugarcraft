<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\MatchResult;
use PHPUnit\Framework\TestCase;

final class SahilmMatcherTest extends TestCase
{
    private SahilmMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new SahilmMatcher();
    }

    public function testMatchReturnsMatchResult(): void
    {
        $result = $this->matcher->match('hello', 'hello');

        $this->assertInstanceOf(MatchResult::class, $result);
    }

    public function testMatchWithExactMatch(): void
    {
        $result = $this->matcher->match('hello', 'hello');

        $this->assertNotNull($result);
        $this->assertGreaterThan(0, $result->score);
    }

    public function testMatchNoMatchReturnsNull(): void
    {
        $result = $this->matcher->match('xyz', 'hello');

        $this->assertNull($result);
    }

    public function testMatchEmptyQueryReturnsNull(): void
    {
        $result = $this->matcher->match('', 'hello');

        $this->assertNull($result);
    }

    public function testMatchEmptyCandidateReturnsNull(): void
    {
        $result = $this->matcher->match('hello', '');

        $this->assertNull($result);
    }

    public function testMatchAllReturnsSortedResults(): void
    {
        $candidates = ['apple', 'applet', 'application', 'apply', 'apricot'];

        $results = $this->matcher->matchAll('app', $candidates);

        $this->assertNotEmpty($results);
        // Should be sorted by score descending
        for ($i = 1; $i < count($results); $i++) {
            $this->assertGreaterThanOrEqual($results[$i]->score, $results[$i - 1]->score);
        }
    }

    public function testMatchAllEmptyQueryReturnsEmpty(): void
    {
        $results = $this->matcher->matchAll('', ['hello', 'world']);

        $this->assertSame([], $results);
    }

    public function testMatchAllEmptyCandidatesReturnsEmpty(): void
    {
        $results = $this->matcher->matchAll('hello', []);

        $this->assertSame([], $results);
    }

    public function testFirstCharBonus(): void
    {
        $result = $this->matcher->match('a', 'apple');

        $this->assertNotNull($result);
        $this->assertSame([0], $result->indices());
    }

    public function testSeparatorBonus(): void
    {
        // 'bar' after separator should score well
        $result = $this->matcher->match('bar', 'foo_bar');

        $this->assertNotNull($result);
        $this->assertSame([4, 5, 6], $result->indices());
    }

    public function testCamelCaseBonus(): void
    {
        // 'fb' in fooBar should match with camelCase bonus
        $result = $this->matcher->match('fb', 'fooBar');

        $this->assertNotNull($result);
    }

    public function testConsecutiveMatchBonus(): void
    {
        $result = $this->matcher->match('foo', 'foo');

        $this->assertNotNull($result);
        $this->assertSame([0, 1, 2], $result->indices());
    }

    public function testPartialMatch(): void
    {
        $result = $this->matcher->match('app', 'apple');

        $this->assertNotNull($result);
        $this->assertSame([0, 1, 2], $result->indices());
    }

    public function testAllCharsMustMatch(): void
    {
        $result = $this->matcher->match('appl', 'app');

        $this->assertNull($result);
    }

    public function testCaseInsensitiveByDefault(): void
    {
        $result = $this->matcher->match('HELLO', 'hello');

        $this->assertNotNull($result);
        $this->assertSame('hello', $result->haystack);
    }

    public function testCaseSensitiveWhenEnabled(): void
    {
        $matcher = new SahilmMatcher(true);
        $result = $matcher->match('HELLO', 'hello');

        $this->assertNull($result);
    }

    public function testUtf8Characters(): void
    {
        $result = $this->matcher->match('中', '中文测试');

        $this->assertNotNull($result);
        $this->assertContains(0, $result->indices());
    }

    public function testMatchAllExcludesNonMatches(): void
    {
        $candidates = ['hello', 'world', 'xyz'];
        $results = $this->matcher->matchAll('xyz', $candidates);

        $this->assertCount(1, $results);
        $this->assertSame('xyz', $results[0]->haystack);
    }
}
