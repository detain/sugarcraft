<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools\Edit;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Tools\Edit\EditMatcher;
use SugarCraft\Code\Tools\Edit\ExactMatcher;
use SugarCraft\Code\Tools\Edit\MatchStage;

final class ExactMatcherTest extends TestCase
{
    public function testNameIsTheExactStage(): void
    {
        self::assertSame(EditMatcher::STAGE_EXACT, (new ExactMatcher())->name());
        self::assertInstanceOf(MatchStage::class, new ExactMatcher());
    }

    public function testFindsEveryNonOverlappingOccurrence(): void
    {
        $found = (new ExactMatcher())->find('abcabcabc', 'abc', 'X');

        self::assertSame([0, 3, 6], array_map(static fn ($c) => $c->offset, $found));
        self::assertSame('X', $found[0]->newString);
        self::assertSame(3, $found[0]->length);
        self::assertNull($found[0]->detail);
    }

    public function testOverlappingOccurrencesAreNotDoubleCounted(): void
    {
        self::assertCount(2, (new ExactMatcher())->find('aaaa', 'aa', 'b'));
    }

    public function testEmptyNeedleMatchesNothing(): void
    {
        self::assertSame([], (new ExactMatcher())->find('abc', '', 'x'));
    }

    public function testMissAndCaseSensitivity(): void
    {
        self::assertSame([], (new ExactMatcher())->find('Hello', 'hello', 'x'));
    }
}
