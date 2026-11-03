<?php

declare(strict_types=1);

namespace SugarCraft\QuickPick\Tests;

use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\QuickPick\{FilteredItem, Item, QuickPick};
use PHPUnit\Framework\TestCase;

/**
 * The anchor-bias rule and duplicate-value identity.
 *
 * M2: the "first occurrence must start in the first half" test is computed in
 * Unicode codepoints. The old byte math let a multibyte prefix (any CJK, any
 * accent) push a genuinely early match past the halfway line.
 * M3: with a ranker attached, repeated VALUES used to collapse onto the last
 * Item carrying that value; scoring a value once must fan back out to every
 * item that holds it.
 */
final class QuickPickAnchorTest extends TestCase
{
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

            public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array
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

            public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator
            {
                yield from $this->matchAll($query, $candidates, $limit, $minScore);
            }
        };
    }

    public function testAnchorIsMeasuredInCodepointsNotBytes(): void
    {
        // '日本abc': the needle starts at codepoint 2 of 5 — inside the first
        // half. The old BYTE math scored it at byte 6 of 9 and rejected it,
        // silently dropping CJK-prefixed entries the user had anchored.
        $h = QuickPick::new([new FilteredItem(1, '日本abc')])
            ->show()
            ->type('abc');

        $this->assertSame(1, $h->itemCount(), 'a multibyte prefix must not eat the anchor budget');
    }

    public function testAnchorStillRejectsSecondHalfNeedles(): void
    {
        // The rule itself is unchanged in spirit: 'ry' sits at codepoint 4 of
        // 'cherry' (6) — second half — and must stay filtered out.
        $h = QuickPick::new([new FilteredItem(1, 'cherry')])->show()->type('ry');
        $this->assertSame(0, $h->itemCount());

        // ...and setRanker() is the documented escape hatch.
        $ranked = $h->setRanker($this->scoreMatcher(['cherry' => 10]))->itemCount();
        $this->assertSame(1, $ranked);
    }

    public function testAnchorFoldsAsciiCaseAcrossCodepoints(): void
    {
        $h = QuickPick::new([new FilteredItem(1, 'Apple')])->show()->type('APPLE');
        $this->assertSame(1, $h->itemCount());
    }

    public function testAccentsFoldCaseButNotIdentity(): void
    {
        // Honest boundary: case folding is Unicode-aware, transliteration is
        // not — 'ecl' cannot reach 'Éclair.txt' because 'é' is not 'e',
        // wherever it sits. Anchoring 'clair' still works in codepoints.
        $items = [new FilteredItem(1, 'Éclair.txt')];
        $this->assertSame(0, QuickPick::new($items)->show()->type('ecl')->itemCount());
        $this->assertSame(1, QuickPick::new($items)->show()->type('clair')->itemCount());
        $this->assertSame(1, QuickPick::new($items)->show()->type('éclair')->itemCount());
    }

    public function testDuplicateValuesKeepItemIdentityUnderTheRanker(): void
    {
        $h = QuickPick::new([
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'apple'),
            new FilteredItem(3, 'banana'),
        ])->show();

        $h = $h->setRanker($this->scoreMatcher(['apple' => 10, 'banana' => 5]));
        $h = $h->type('a');

        // Both apples survive as distinct rows — the value-keyed lookup used
        // to alias them onto the LAST apple, losing number() identity.
        $this->assertSame(3, $h->itemCount());
        $numbers = \array_map(static fn(Item $i): int => $i->number(), $h->items());
        $this->assertSame([1, 2, 3], $numbers);

        // And the cursor walks real objects.
        $this->assertSame(1, $h->selected()?->number());
        $this->assertSame(2, $h->cursorDown()->selected()?->number());
        $this->assertSame(3, $h->cursorDown()->cursorDown()->selected()?->number());
    }

    public function testDuplicateValuesSurviveTheSubstringPath(): void
    {
        $h = QuickPick::new([
            new FilteredItem(1, 'apple'),
            new FilteredItem(2, 'apple'),
        ])->show()->type('a');

        $this->assertSame(2, $h->itemCount());
        $this->assertSame([1, 2], \array_map(static fn(Item $i): int => $i->number(), $h->items()));
    }
}
