<?php

declare(strict_types=1);

namespace SugarCraft\Fuzzy\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\MatchResultSorter;
use PHPUnit\Framework\TestCase;

#[CoversClass(MatchResultSorter::class)]
final class MatchResultSorterTest extends TestCase
{
    private function r(string $haystack, int $score): MatchResult
    {
        return new MatchResult('q', $haystack, $score, [0]);
    }

    #[Test]
    public function testSortByScoreDescending(): void
    {
        $sorted = MatchResultSorter::sort([
            $this->r('low', 1),
            $this->r('high', 10),
            $this->r('mid', 5),
        ]);

        $this->assertSame(['high', 'mid', 'low'], array_map(static fn(MatchResult $r) => $r->haystack, $sorted));
    }

    #[Test]
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

    #[Test]
    public function testSortEmptyReturnsEmpty(): void
    {
        $this->assertSame([], MatchResultSorter::sort([]));
    }

    #[Test]
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

    #[Test]
    public function testSortAndSliceNullLimitReturnsAll(): void
    {
        $results = [$this->r('a', 3), $this->r('b', 9)];
        $this->assertCount(2, MatchResultSorter::sortAndSlice($results, null));
    }

    #[Test]
    public function testSortAndSliceZeroLimitReturnsEmpty(): void
    {
        $results = [$this->r('a', 3), $this->r('b', 9)];
        $this->assertSame([], MatchResultSorter::sortAndSlice($results, 0));
    }

    #[Test]
    public function testSortAndSliceNegativeLimitIsIgnored(): void
    {
        // Guarded by `$limit >= 0` — a negative limit leaves the list intact.
        $results = [$this->r('a', 3), $this->r('b', 9)];
        $this->assertCount(2, MatchResultSorter::sortAndSlice($results, -1));
    }

    #[Test]
    public function testNumericStringHaystackTiebreakComparesNumerically(): void
    {
        // The haystack tiebreak uses <=>, which for two numeric strings orders
        // them NUMERICALLY — "10" sorts after "9", not before it as plain byte
        // order would give. Pinned as total-order semantics (docblock law).
        $sorted = MatchResultSorter::sort([
            $this->r('10', 5),
            $this->r('9', 5),
        ]);

        $this->assertSame(['9', '10'], array_map(static fn(MatchResult $r) => $r->haystack, $sorted));

        // Mixed numeric/non-numeric falls back to string comparison: "2a" is
        // not numeric, so "10" vs "2a" orders by bytes — "1" < "2".
        $mixed = MatchResultSorter::sort([
            $this->r('2a', 5),
            $this->r('10', 5),
        ]);

        $this->assertSame(['10', '2a'], array_map(static fn(MatchResult $r) => $r->haystack, $mixed));
    }

    #[Test]
    public function testFullTiePreservesInputOrderViaStableSort(): void
    {
        // Score AND haystack equal → comparator returns 0; PHP >= 8.0's stable
        // usort keeps the pair in arrival order. The library requires ^8.3, so
        // this is a guarantee, not a coincidence; needles discriminate the two
        // rows for the assertion (they are not compared by the sort itself).
        $first = new MatchResult('arrived-first', 'same', 5, [0]);
        $second = new MatchResult('arrived-second', 'same', 5, [1]);

        $sorted = MatchResultSorter::sort([$first, $second]);
        $this->assertSame(['arrived-first', 'arrived-second'], array_map(static fn(MatchResult $r) => $r->needle, $sorted));

        // Reversed arrival reverses the output — order follows input, never
        // any hidden tiebreaker.
        $reversed = MatchResultSorter::sort([$second, $first]);
        $this->assertSame(['arrived-second', 'arrived-first'], array_map(static fn(MatchResult $r) => $r->needle, $reversed));
    }

    #[Test]
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
