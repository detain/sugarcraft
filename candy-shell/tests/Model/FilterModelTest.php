<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Model;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Shell\Model\FilterModel;
use PHPUnit\Framework\TestCase;

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
}
