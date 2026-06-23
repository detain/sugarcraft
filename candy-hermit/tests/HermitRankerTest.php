<?php

declare(strict_types=1);

namespace SugarCraft\Hermit\Tests;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Hermit\{FilteredItem, Hermit, Item};
use PHPUnit\Framework\TestCase;

/**
 * The candy-fuzzy pluggable ranker: setRanker() makes a non-empty filter rank
 * items by descending fuzzy score (true subsequence) and highlight the scored
 * runes, instead of the default contiguous-substring/anchor filter.
 */
final class HermitRankerTest extends TestCase
{
    /** @return list<Item> */
    private function fruits(): array
    {
        return [
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'banana'),
            new FilteredItem(3, 'cherry'),
        ];
    }

    /** @param array<string,int> $scores candidate => score (<= 0 means no match) */
    private function scoreMatcher(array $scores): FuzzyMatcher
    {
        return new class($scores) implements FuzzyMatcher {
            /** @param array<string,int> $scores */
            public function __construct(private array $scores)
            {
            }

            public function match(string $query, string $candidate): ?MatchResult
            {
                $score = $this->scores[$candidate] ?? 0;

                return $score > 0 ? new MatchResult($query, $candidate, $score, [0]) : null;
            }

            public function matchAll(string $query, iterable $candidates): array
            {
                $results = [];
                foreach ($candidates as $candidate) {
                    $m = $this->match($query, (string) $candidate);
                    if ($m !== null) {
                        $results[] = $m;
                    }
                }
                \usort($results, static fn(MatchResult $a, MatchResult $b): int => $b->score <=> $a->score);

                return $results;
            }
        };
    }

    /** A matcher that always matches with a fixed set of character indices. */
    private function indicesMatcher(array $indices): FuzzyMatcher
    {
        return new class($indices) implements FuzzyMatcher {
            /** @param list<int> $indices */
            public function __construct(private array $indices)
            {
            }

            public function match(string $query, string $candidate): ?MatchResult
            {
                return new MatchResult($query, $candidate, 10, $this->indices);
            }

            public function matchAll(string $query, iterable $candidates): array
            {
                $results = [];
                foreach ($candidates as $candidate) {
                    $results[] = $this->match($query, (string) $candidate);
                }

                return $results;
            }
        };
    }

    /** @return list<string> */
    private function values(Hermit $h): array
    {
        return array_map(static fn(Item $i): string => $i->value(), $h->items());
    }

    public function testRankerOrdersByDescendingScore(): void
    {
        $h = Hermit::new($this->fruits())
            ->setRanker($this->scoreMatcher(['apple' => 10, 'banana' => 30, 'cherry' => 20]))
            ->show()
            ->type('x');

        self::assertSame(['banana', 'cherry', 'apple'], $this->values($h));
    }

    public function testRankerExcludesZeroScores(): void
    {
        $h = Hermit::new($this->fruits())
            ->setRanker($this->scoreMatcher(['apple' => 10, 'banana' => 0, 'cherry' => 20]))
            ->show()
            ->type('x');

        self::assertSame(['cherry', 'apple'], $this->values($h));
    }

    public function testRankerTieBreaksOnOriginalOrder(): void
    {
        $h = Hermit::new($this->fruits())
            ->setRanker($this->scoreMatcher(['apple' => 10, 'banana' => 10, 'cherry' => 10]))
            ->show()
            ->type('x');

        self::assertSame(['apple', 'banana', 'cherry'], $this->values($h));
    }

    public function testRankerStillRespectsTheFilterFn(): void
    {
        $h = Hermit::new($this->fruits())
            ->setFilterFn(static fn(Item $i): bool => $i->value() !== 'banana')
            ->setRanker($this->scoreMatcher(['apple' => 10, 'banana' => 30, 'cherry' => 20]))
            ->show()
            ->type('x');

        // banana scores highest but is excluded by the predicate.
        self::assertSame(['cherry', 'apple'], $this->values($h));
    }

    public function testEmptyFilterWithRankerReturnsAllItemsUnranked(): void
    {
        $h = Hermit::new($this->fruits())
            ->setRanker($this->scoreMatcher([]))   // would match nothing if consulted
            ->show();                              // filterText is ''

        self::assertSame(['apple', 'banana', 'cherry'], $this->values($h));
    }

    public function testRealMatcherFindsASubsequenceTheSubstringFilterMisses(): void
    {
        $items = [new FilteredItem(1, 'terminal'), new FilteredItem(2, 'train')];

        // 'tml' is a subsequence of t-e-r-m-i-n-a-l but not a contiguous substring;
        // it outranks 'train', which only aligns the leading 't'.
        $fuzzy = Hermit::new($items)->setRanker(new SmithWatermanMatcher())->show()->type('tml');
        self::assertContains('terminal', $this->values($fuzzy));
        self::assertSame('terminal', $this->values($fuzzy)[0], 'the full subsequence ranks first');

        // The default (no ranker) substring filter finds nothing for 'tml'.
        $plain = Hermit::new($items)->show()->type('tml');
        self::assertSame([], $this->values($plain), 'substring filtering cannot match a non-contiguous query');
    }

    public function testClearingTheRankerRestoresSubstringFiltering(): void
    {
        $base = Hermit::new($this->fruits())->setRanker(new SmithWatermanMatcher());

        $restored = $base->setRanker(null)->show()->type('an');  // substring 'an' (anchored)

        // 'banana' contains 'an' near the front; apple/cherry do not.
        self::assertSame(['banana'], $this->values($restored));
    }

    public function testFuzzyHighlightWrapsTheScoredRunes(): void
    {
        $h = Hermit::new([new FilteredItem(1, 'terminal')])
            ->setRanker($this->indicesMatcher([0, 1]))      // highlight 'te'
            ->setMatchStyle("\e[1m")
            ->setItemFormatter(static fn(string $v, bool $sel): string => $v)
            ->setWindowWidth(40)
            ->setWindowHeight(5)
            ->setOffset(0, 0)
            ->type('te');

        $bg = implode("\n", array_fill(0, 5, str_repeat(' ', 40)));
        $out = $h->View($bg);

        self::assertStringContainsString("\e[1mte" . Ansi::reset() . 'rminal', $out);
    }

    public function testFuzzyHighlightFallsBackWhenTheRankerReportsNoMatch(): void
    {
        // A ranker whose match() returns null for the displayed string: the item
        // is shown (it was selected for the list) but no highlight is applied.
        $noMatch = new class implements FuzzyMatcher {
            public function match(string $query, string $candidate): ?MatchResult
            {
                // Match the raw value (so it is listed) but not the formatted "* value".
                return str_starts_with($candidate, '*') ? null : new MatchResult($query, $candidate, 5, [0]);
            }

            public function matchAll(string $query, iterable $candidates): array
            {
                return [];
            }
        };

        $h = Hermit::new([new FilteredItem(1, 'apple')])
            ->setRanker($noMatch)
            ->setMatchStyle("\e[1m")
            ->setItemFormatter(static fn(string $v, bool $sel): string => '* ' . $v)
            ->setWindowWidth(40)
            ->setWindowHeight(5)
            ->setOffset(0, 0)
            ->type('a');

        $out = $h->View($bg = implode("\n", array_fill(0, 5, str_repeat(' ', 40))));

        self::assertStringNotContainsString("\e[1m", $out, 'no highlight when the formatted string does not match');
        self::assertStringContainsString('apple', $out, 'the item is still listed');
    }
}
