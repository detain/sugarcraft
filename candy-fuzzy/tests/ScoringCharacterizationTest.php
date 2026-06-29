<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\Matcher\SahilmMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests pinning exact score and index output.
 *
 * These encode the CURRENT behavior (not ideal behavior) so that
 * performance refactors in Steps 2 & 3 can be verified byte-equivalent.
 *
 * Mirrors charmbracelet/fuzzy output contract.
 */
final class ScoringCharacterizationTest extends TestCase
{
    private SmithWatermanMatcher $sw;
    private SahilmMatcher $sm;
    private SahilmMatcher $smCS;

    protected function setUp(): void
    {
        $this->sw = new SmithWatermanMatcher();
        $this->sm = new SahilmMatcher();
        $this->smCS = new SahilmMatcher(true);
    }

    /**
     * @return array<string, array{query: string, candidate: string, expectedScore: int|null, expectedIndices: list<int>|null}>
     */
    public static function smithWatermanCases(): array
    {
        return [
            'ASCII exact' => ['query' => 'foo', 'candidate' => 'foobar', 'expectedScore' => 19, 'expectedIndices' => [0, 1, 2]],
            'substring' => ['query' => 'ell', 'candidate' => 'hello', 'expectedScore' => 19, 'expectedIndices' => [1, 2, 3]],
            'scattered' => ['query' => 'abc', 'candidate' => 'axbxabc', 'expectedScore' => 19, 'expectedIndices' => [4, 5, 6]],
            'UTF-8 single char' => ['query' => '中', 'candidate' => '中文测试', 'expectedScore' => 3, 'expectedIndices' => [0]],
            'UTF-8 substring' => ['query' => '文测', 'candidate' => '中文测试', 'expectedScore' => 11, 'expectedIndices' => [1, 2]],
            'separator' => ['query' => 'bar', 'candidate' => 'foo_bar', 'expectedScore' => 19, 'expectedIndices' => [4, 5, 6]],
            'camelCase' => ['query' => 'fb', 'candidate' => 'fooBar', 'expectedScore' => 4, 'expectedIndices' => [0, 3]],
            'no-match' => ['query' => 'xyz', 'candidate' => 'abc', 'expectedScore' => null, 'expectedIndices' => null],
            'query longer than candidate' => ['query' => 'hello', 'candidate' => 'hi', 'expectedScore' => null, 'expectedIndices' => null],
        ];
    }

    /**
     * @return array<string, array{query: string, candidate: string, expectedScore: int|null, expectedIndices: list<int>|null}>
     */
    public static function sahilmMatcherCases(): array
    {
        return [
            'ASCII exact' => ['query' => 'foo', 'candidate' => 'foobar', 'expectedScore' => 31, 'expectedIndices' => [0, 1, 2]],
            'substring' => ['query' => 'ell', 'candidate' => 'hello', 'expectedScore' => 16, 'expectedIndices' => [1, 2, 3]],
            'scattered' => ['query' => 'abc', 'candidate' => 'axbxabc', 'expectedScore' => 21, 'expectedIndices' => [0, 2, 6]],
            'UTF-8 single char' => ['query' => '中', 'candidate' => '中文测试', 'expectedScore' => 16, 'expectedIndices' => [0]],
            'UTF-8 substring' => ['query' => '文测', 'candidate' => '中文测试', 'expectedScore' => 7, 'expectedIndices' => [1, 2]],
            'separator' => ['query' => 'bar', 'candidate' => 'foo_bar', 'expectedScore' => 26, 'expectedIndices' => [4, 5, 6]],
            'camelCase' => ['query' => 'fb', 'candidate' => 'fooBar', 'expectedScore' => 18, 'expectedIndices' => [0, 3]],
            'no-match' => ['query' => 'xyz', 'candidate' => 'abc', 'expectedScore' => null, 'expectedIndices' => null],
            'query longer than candidate' => ['query' => 'hello', 'candidate' => 'hi', 'expectedScore' => null, 'expectedIndices' => null],
        ];
    }

    /**
     * @dataProvider smithWatermanCases
     */
    public function testSmithWatermanPinnedOutput(string $query, string $candidate, ?int $expectedScore, ?array $expectedIndices): void
    {
        $result = $this->sw->match($query, $candidate);

        if ($expectedScore === null) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
            $this->assertSame($expectedScore, $result->score);
            $this->assertSame($expectedIndices, $result->indices());
        }
    }

    /**
     * @dataProvider sahilmMatcherCases
     */
    public function testSahilmMatcherPinnedOutput(string $query, string $candidate, ?int $expectedScore, ?array $expectedIndices): void
    {
        $result = $this->sm->match($query, $candidate);

        if ($expectedScore === null) {
            $this->assertNull($result);
        } else {
            $this->assertNotNull($result);
            $this->assertSame($expectedScore, $result->score);
            $this->assertSame($expectedIndices, $result->indices());
        }
    }

    public function testSahilmMatcherCaseSensitiveMatch(): void
    {
        $result = $this->smCS->match('Hello', 'Hello');

        $this->assertNotNull($result);
        $this->assertSame(54, $result->score);
        $this->assertSame([0, 1, 2, 3, 4], $result->indices());
    }

    public function testSahilmMatcherCaseSensitiveNoMatch(): void
    {
        $result = $this->smCS->match('hello', 'Hello');

        $this->assertNull($result);
    }
}
