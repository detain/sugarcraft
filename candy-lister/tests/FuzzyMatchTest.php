<?php

declare(strict_types=1);

namespace SugarCraft\Lister\Tests;

use SugarCraft\Lister\FuzzyMatch;
use SugarCraft\Lister\StringItem;
use PHPUnit\Framework\TestCase;

final class FuzzyMatchTest extends TestCase
{
    private FuzzyMatch $matcher;

    protected function setUp(): void
    {
        $this->matcher = new FuzzyMatch();
    }

    public function testScoreEmptyQueryReturnsZero(): void
    {
        $this->assertSame(0, $this->matcher->score('', 'hello'));
    }

    public function testScoreEmptyCandidateReturnsZero(): void
    {
        // Empty candidate is a non-match; return 0 (match() drops non-positive scores).
        $this->assertSame(0, $this->matcher->score('hello', ''));
    }

    public function testScoreExactMatchIsPositive(): void
    {
        $score = $this->matcher->score('abc', 'abc');
        $this->assertGreaterThan(0, $score);
    }

    public function testScoreNoMatchIsLowOrZero(): void
    {
        $score = $this->matcher->score('xyz', 'abc');
        $this->assertLessThanOrEqual(0, $score);
    }

    public function testScoreConsecutiveMatchesHigherThanScattered(): void
    {
        $scattered = $this->matcher->score('abc', 'axbxc');
        $consecutive = $this->matcher->score('abc', 'abc');
        $this->assertGreaterThan($scattered, $consecutive);
    }

    public function testScoreCaseInsensitive(): void
    {
        $lower = $this->matcher->score('abc', 'abc');
        $mixed = $this->matcher->score('abc', 'ABC');
        $this->assertSame($lower, $mixed);
    }

    public function testMatchEmptyQueryReturnsEmpty(): void
    {
        $items = [new StringItem('apple'), new StringItem('banana')];
        $result = $this->matcher->match('', $items);
        $this->assertSame([], $result);
    }

    public function testMatchEmptyItemsReturnsEmpty(): void
    {
        $result = $this->matcher->match('app', []);
        $this->assertSame([], $result);
    }

    public function testMatchReturnsScoredCandidates(): void
    {
        $items = [
            new StringItem('apple'),
            new StringItem('apricot'),
            new StringItem('banana'),
            new StringItem('cherry'),
        ];
        $result = $this->matcher->match('ap', $items);

        // apple and apricot get score 11 each (consecutive 'a','p')
        // banana gets score 3 (scattered 'a' and 'p')
        $this->assertCount(3, $result);
        // Sorted by score descending
        $this->assertGreaterThanOrEqual($result[1][1], $result[0][1]);
        // Verify scores are positive
        foreach ($result as [$item, $score]) {
            $this->assertInstanceOf(\Stringable::class, $item);
            $this->assertGreaterThan(0, $score);
        }
    }

    public function testMatchNonMatchingReturnsEmpty(): void
    {
        // 'zzz' has no matches in these items
        $items = [new StringItem('banana'), new StringItem('cherry')];
        $result = $this->matcher->match('zzz', $items);
        $this->assertSame([], $result);
    }

    public function testMatchResultStructure(): void
    {
        $items = [new StringItem('test')];
        $result = $this->matcher->match('tes', $items);

        $this->assertCount(1, $result);
        [$item, $score] = $result[0];
        $this->assertSame('test', (string) $item);
        $this->assertIsInt($score);
    }
}
