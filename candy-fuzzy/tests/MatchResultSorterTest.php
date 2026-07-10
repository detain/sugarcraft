<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\MatchResultSorter;
use PHPUnit\Framework\TestCase;

final class MatchResultSorterTest extends TestCase
{
    private function r(string $haystack, int $score): MatchResult
    {
        return new MatchResult('q', $haystack, $score, [0]);
    }

    public function testSortByScoreDescending(): void
    {
        $sorted = MatchResultSorter::sort([
            $this->r('low', 1),
            $this->r('high', 10),
            $this->r('mid', 5),
        ]);

        $this->assertSame(['high', 'mid', 'low'], array_map(static fn(MatchResult $r) => $r->haystack, $sorted));
    }

    public function testTiebreakByHaystackAscending(): void
    {
        // Equal scores → haystack ascending.
        $sorted = MatchResultSorter::sort([
            $this->r('banana', 5),
            $this->r('apple', 5),
            $this->r('cherry', 5),
        ]);

        $this->assertSame(['apple', 'banana', 'cherry'], array_map(static fn(MatchResult $r) => $r->haystack, $sorted));
    }

    public function testSortEmptyReturnsEmpty(): void
    {
        $this->assertSame([], MatchResultSorter::sort([]));
    }

    public function testSortAndSliceRespectsLimit(): void
    {
        $sliced = MatchResultSorter::sortAndSlice([
            $this->r('a', 3),
            $this->r('b', 9),
            $this->r('c', 6),
            $this->r('d', 1),
        ], 2);

        $this->assertCount(2, $sliced);
        $this->assertSame(['b', 'c'], array_map(static fn(MatchResult $r) => $r->haystack, $sliced));
    }

    public function testSortAndSliceNullLimitReturnsAll(): void
    {
        $results = [$this->r('a', 3), $this->r('b', 9)];
        $this->assertCount(2, MatchResultSorter::sortAndSlice($results, null));
    }

    public function testSortAndSliceZeroLimitReturnsEmpty(): void
    {
        $results = [$this->r('a', 3), $this->r('b', 9)];
        $this->assertSame([], MatchResultSorter::sortAndSlice($results, 0));
    }

    public function testSortAndSliceNegativeLimitIsIgnored(): void
    {
        // Guarded by `$limit >= 0` — a negative limit leaves the list intact.
        $results = [$this->r('a', 3), $this->r('b', 9)];
        $this->assertCount(2, MatchResultSorter::sortAndSlice($results, -1));
    }

    public function testCombinedScoreThenHaystackOrdering(): void
    {
        $sorted = MatchResultSorter::sort([
            $this->r('zebra', 5),
            $this->r('apple', 5),
            $this->r('top', 10),
            $this->r('bottom', 1),
        ]);

        $this->assertSame(
            ['top', 'apple', 'zebra', 'bottom'],
            array_map(static fn(MatchResult $r) => $r->haystack, $sorted),
        );
    }
}
