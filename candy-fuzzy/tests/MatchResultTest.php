<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\MatchResult;
use PHPUnit\Framework\TestCase;

final class MatchResultTest extends TestCase
{
    public function testConstruction(): void
    {
        $result = new MatchResult('foo', 'foobar', 42, [0, 1, 2]);

        $this->assertSame('foo', $result->needle);
        $this->assertSame('foobar', $result->haystack);
        $this->assertSame(42, $result->score);
        $this->assertSame([0, 1, 2], $result->matchedIndices);
    }

    public function testIsEmptyWithEmptyIndices(): void
    {
        $result = new MatchResult('xyz', 'foobar', 0, []);

        $this->assertTrue($result->isEmpty());
    }

    public function testIsEmptyWithNonEmptyIndices(): void
    {
        $result = new MatchResult('foo', 'foobar', 10, [0, 1, 2]);

        $this->assertFalse($result->isEmpty());
    }

    public function testIsMatchedWithPositiveScore(): void
    {
        $result = new MatchResult('foo', 'foobar', 10, [0, 1, 2]);

        $this->assertTrue($result->isMatched());
    }

    public function testIsMatchedWithZeroScore(): void
    {
        $result = new MatchResult('foo', 'foobar', 0, [0, 1, 2]);

        $this->assertFalse($result->isMatched());
    }

    public function testIndices(): void
    {
        $indices = [0, 1, 2, 5, 6];
        $result = new MatchResult('foo', 'foobar', 10, $indices);

        $this->assertSame($indices, $result->indices());
    }

    public function testIndicesReturnsNewArray(): void
    {
        $result = new MatchResult('foo', 'foobar', 10, [0, 1, 2]);

        $indices = $result->indices();
        $indices[] = 99;

        // Original should be unchanged
        $this->assertSame([0, 1, 2], $result->indices());
    }
}
