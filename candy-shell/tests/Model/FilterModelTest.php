<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Model;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Fuzzy\FuzzyMatcher;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Shell\Model\FilterModel;
use PHPUnit\Framework\TestCase;

/**
 * Delegating stub that counts matchAll() invocations so tests can observe
 * whether FilterModel's fuzzy-result cache short-circuits recomputation.
 */
final class CountingMatcher implements FuzzyMatcher
{
    public int $matchAllCalls = 0;
    private SmithWatermanMatcher $inner;

    public function __construct()
    {
        $this->inner = new SmithWatermanMatcher();
    }

    public function match(string $query, string $candidate): ?MatchResult
    {
        return $this->inner->match($query, $candidate);
    }

    public function matchAll(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): array
    {
        $this->matchAllCalls++;
        return $this->inner->matchAll($query, $candidates, $limit, $minScore);
    }

    public function matchAllGenerator(string $query, iterable $candidates, ?int $limit = null, int $minScore = 1): \Generator
    {
        return $this->inner->matchAllGenerator($query, $candidates, $limit, $minScore);
    }
}

final class FilterModelTest extends TestCase
{
    private function model(): FilterModel
    {
        return FilterModel::fromOptions(['apple', 'banana', 'cherry', 'date']);
    }

    public function testStartsInFilterMode(): void
    {
        $m = $this->model();
        $this->assertTrue($m->list->isFiltering());
    }

    public function testTypingFiltersAndEnterSubmits(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        // Filter "an" matches 'banana'.
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        $this->assertSame('banana', $m->selected());
    }

    public function testEnterWithNoMatchesIsNoOp(): void
    {
        $m = $this->model();
        foreach (str_split('xyz') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }
        [$m, $cmd] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($m->isSubmitted());
        $this->assertNull($cmd);
    }

    public function testArrowMovesCursorWhilePreservingFilterText(): void
    {
        $m = $this->model();
        // Filter "a" matches all four.
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        $this->assertSame('a', $m->list->filterText);
        // Cursor should now sit at index 1; Enter submits 'banana'.
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('banana', $m->selected());
    }

    public function testEscAborts(): void
    {
        $m = $this->model();
        [$m, ] = $m->update(new KeyMsg(KeyType::Escape));
        $this->assertTrue($m->isAborted());
    }

    public function testFuzzyModeEnabledViaFlag(): void
    {
        $m = FilterModel::fromOptions(['apple', 'banana', 'cherry'], fuzzy: true);
        $this->assertTrue($m->list->isFiltering());
    }

    public function testFuzzyMatchesScoredBySmithWaterman(): void
    {
        $m = FilterModel::fromOptions(['apple', 'banana', 'cherry', 'date'], fuzzy: true);

        // Type "bna" which fuzzy-matches "banana" better than others
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'b'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));

        // Should have fuzzy results
        $this->assertNotEmpty($m->fuzzyResults);

        // "banana" should be in fuzzy results (it matches "bna")
        $visible = $m->fuzzyVisibleItems();
        $this->assertContains('banana', array_map(static fn($i) => $i->title(), $visible));
    }

    public function testFuzzyHighlightIndicesAvailable(): void
    {
        $m = FilterModel::fromOptions(['banana', 'apple', 'cherry'], fuzzy: true);

        // Type "b" to filter
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'b'));

        $indices = $m->highlightIndices();
        $this->assertIsArray($indices);
    }

    public function testFuzzyDisabledFallsBackToSubstring(): void
    {
        $m = FilterModel::fromOptions(['banana', 'apple', 'cherry'], fuzzy: false);

        // Type "ban" - should match banana via substring
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'b'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'n'));

        $this->assertEmpty($m->fuzzyResults);
        $this->assertSame('ban', $m->list->filterText);
        $visibleItems = $m->list->visibleItems();
        $this->assertCount(1, $visibleItems);
        $this->assertSame('banana', $visibleItems[0]->title());

        // Press Enter to submit
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));
        $this->assertTrue($m->isSubmitted());
        // Substring matching still works via ItemList
        $this->assertSame('banana', $m->selected());
    }

    public function testFuzzyEmptyQueryReturnsAllItems(): void
    {
        $m = FilterModel::fromOptions(['apple', 'banana', 'cherry'], fuzzy: true);

        $visible = $m->fuzzyVisibleItems();
        $this->assertCount(3, $visible);
    }

    public function testFuzzyWithPreFilledValue(): void
    {
        $m = FilterModel::fromOptions(
            ['apple', 'banana', 'cherry'],
            fuzzy: true,
            value: 'ana'
        );

        // Should have fuzzy results for "ana" pre-filled
        $this->assertNotEmpty($m->fuzzyResults);
    }

    public function testFuzzyResultsCachedForCursorOnlyKeys(): void
    {
        $matcher = new CountingMatcher();
        $m = FilterModel::fromOptions(
            ['apple', 'banana', 'cherry', 'date'],
            fuzzy: true,
            matcher: $matcher,
        );

        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'n'));
        $this->assertSame(2, $matcher->matchAllCalls);

        // Cursor movement leaves the filter text unchanged — the cache must
        // answer these updates without re-running the matcher.
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));
        [$m, ] = $m->update(new KeyMsg(KeyType::Up));
        $this->assertSame(2, $matcher->matchAllCalls);

        // A text change invalidates the cache and recomputes.
        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame(3, $matcher->matchAllCalls);
    }

    public function testFuzzyCacheStillYieldsResultsAfterCursorMove(): void
    {
        $matcher = new CountingMatcher();
        $m = FilterModel::fromOptions(['banana', 'cabana'], fuzzy: true, matcher: $matcher);

        [$m, ] = $m->update(new KeyMsg(KeyType::Char, 'b'));
        [$m, ] = $m->update(new KeyMsg(KeyType::Down));

        $this->assertNotEmpty($m->fuzzyResults);
        $this->assertSame(1, $matcher->matchAllCalls);
    }

    public function testFuzzyViewEmphasisesMatchedCharacters(): void
    {
        $m = FilterModel::fromOptions(['banana', 'cherry'], fuzzy: true);
        foreach (str_split('bna') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }

        $view = $m->view();
        // Matched characters are wrapped in bold-on / normal-intensity SGR.
        $this->assertStringContainsString("\x1b[1m", $view);
        $this->assertStringContainsString("\x1b[22m", $view);
        // The cursor row keeps REVERSE video, opening before the first
        // matched (bold) character of "banana".
        $this->assertStringContainsString("\x1b[7m\x1b[1mb", $view);
        // Stripped of SGR, the matched item is intact.
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $view);
        $this->assertStringContainsString('banana', $plain);
        $this->assertStringNotContainsString('cherry', $plain);
    }

    /**
     * Regression: selectedAll() used to ksort() the readonly $checked
     * property by reference, which fatals on PHP 8.1+ readonly props.
     */
    public function testMultiSelectedAllSortsWithoutMutatingReadonlyState(): void
    {
        $m = FilterModel::fromOptions(['apple', 'banana'], limit: 2);
        [$m, ] = $m->update(new KeyMsg(KeyType::Tab));
        [$m, ] = $m->update(new KeyMsg(KeyType::Enter));

        $this->assertTrue($m->isSubmitted());
        $this->assertSame(['apple'], $m->selectedAll());
    }

    public function testNonFuzzyViewHasNoBoldEmphasis(): void
    {
        $m = FilterModel::fromOptions(['banana', 'cherry'], fuzzy: false);
        foreach (str_split('ban') as $c) {
            [$m, ] = $m->update(new KeyMsg(KeyType::Char, $c));
        }

        $this->assertStringNotContainsString("\x1b[1m", $m->view());
    }
}
