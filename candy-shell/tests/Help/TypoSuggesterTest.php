<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Help;

use PHPUnit\Framework\TestCase;
use SugarCraft\Shell\Help\TypoSuggester;

final class TypoSuggesterTest extends TestCase
{
    public function testExactMatchReturnsNull(): void
    {
        $suggester = new TypoSuggester(['status', 'style', 'filter']);
        $result = $suggester->suggest('status');
        $this->assertNull($result);
    }

    public function testDistanceOneSuggestsCommand(): void
    {
        $suggester = new TypoSuggester(['status', 'style', 'filter']);
        $this->assertSame('status', $suggester->suggest('sttatus'));
        $this->assertSame('status', $suggester->suggest('satatus'));
        $this->assertSame('style', $suggester->suggest('styl'));
    }

    public function testDistanceTwoSuggestsCommand(): void
    {
        $suggester = new TypoSuggester(['status', 'style', 'filter']);
        $this->assertSame('status', $suggester->suggest('staaatus'));
        $this->assertSame('filter', $suggester->suggest('fiilter'));
    }

    public function testDistanceGreaterThanTwoReturnsNull(): void
    {
        $suggester = new TypoSuggester(['status', 'style', 'filter']);
        $this->assertNull($suggester->suggest('ffffff'));
        $this->assertNull($suggester->suggest('xyz'));
    }

    public function testCaseInsensitive(): void
    {
        $suggester = new TypoSuggester(['Status', 'Style', 'Filter']);
        $this->assertSame('Status', $suggester->suggest('sttatus'));
        $this->assertNull($suggester->suggest('status'));
    }

    public function testEmptyCommandListReturnsNull(): void
    {
        $suggester = new TypoSuggester([]);
        $this->assertNull($suggester->suggest('status'));
    }

    public function testClosestMatchWins(): void
    {
        $suggester = new TypoSuggester(['status', 'style', 'filter']);
        $this->assertSame('status', $suggester->suggest('sttatus'));
    }
}
