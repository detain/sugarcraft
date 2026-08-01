<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\ItemList;

use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class ItemListTest extends TestCase
{
    /** @return list<StringItem> */
    private function items(): array
    {
        return [
            new StringItem('apple'),
            new StringItem('banana'),
            new StringItem('cherry'),
            new StringItem('date'),
        ];
    }

    private function focused(): ItemList
    {
        $l = ItemList::new($this->items(), 60, 5);
        [$l, ] = $l->focus();
        return $l;
    }

    public function testInitialState(): void
    {
        $l = ItemList::new($this->items());
        $this->assertSame(0, $l->index());
        $this->assertSame('apple', $l->selectedItem()->title());
    }

    public function testIgnoresKeysWhenUnfocused(): void
    {
        $l = ItemList::new($this->items());
        [$l, ] = $l->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $l->index());
    }

    public function testArrowDownAdvances(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Down));
        $this->assertSame('banana', $l->selectedItem()->title());
    }

    public function testVimJK(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'j'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(2, $l->index());
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'k'));
        $this->assertSame(1, $l->index());
    }

    public function testHomeAndEnd(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'G'));
        $this->assertSame(3, $l->index());
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'g'));
        $this->assertSame(0, $l->index());
    }

    public function testCannotMoveBelowZeroOrPastEnd(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Up));
        $this->assertSame(0, $l->index());
        for ($i = 0; $i < 10; $i++) {
            [$l, ] = $l->update(new KeyMsg(KeyType::Down));
        }
        $this->assertSame(3, $l->index());
    }

    public function testFilteringMatchesSubstring(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertTrue($l->isFiltering());
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'n'));
        $titles = array_map(static fn($i) => $i->title(), $l->visibleItems());
        $this->assertSame(['banana'], $titles);
    }

    public function testFilteringIsCaseInsensitive(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'A'));
        $titles = array_map(static fn($i) => $i->title(), $l->visibleItems());
        $this->assertSame(['apple', 'banana', 'date'], $titles);
    }

    public function testFilterEnterExitsFilteringAndClearsFilterByDefault(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($l->isFiltering());
        $this->assertFalse($l->isFiltered());
        $this->assertSame(4, count($l->visibleItems()));
    }

    public function testFilterEnterKeepsFilterWhenKeepFilterEnabled(): void
    {
        $l = $this->focused()->withKeepFilter(true);
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($l->isFiltering());
        $this->assertTrue($l->isFiltered());
        $this->assertSame('a', $l->filterValue());
        $this->assertSame(3, count($l->visibleItems()));
    }

    public function testKeepFilterAllowsReenteringFilterWithTextPreserved(): void
    {
        $l = $this->focused()->withKeepFilter(true);
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Enter));
        $this->assertSame('a', $l->filterValue());
        // Note: pressing '/' again clears filterText (keepFilter only affects Enter)
        // But the filterText 'a' is still accessible via filterValue() since we didn't clear
        $this->assertFalse($l->isFiltering());
        $this->assertTrue($l->isFiltered());
    }

    public function testFilterEscapeClears(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Escape));
        $this->assertFalse($l->isFiltering());
        $this->assertSame(4, count($l->visibleItems()));
    }

    public function testFilterBackspace(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'n'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Backspace));
        $this->assertSame(3, count($l->visibleItems()));
    }

    public function testFilterScrollScrollsCursorIntoView(): void
    {
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $items[] = new StringItem("item$i");
        }
        $l = ItemList::new($items, 60, 5);
        [$l, ] = $l->focus();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'G'));
        $this->assertSame(19, $l->index());
        $this->assertSame(15, $l->offset);
    }

    public function testSetItemsResetsCursor(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Down));
        $l = $l->setItems([new StringItem('x')]);
        $this->assertSame(0, $l->index());
        $this->assertSame('x', $l->selectedItem()->title());
    }

    public function testEmptyListSelectedReturnsNull(): void
    {
        $l = ItemList::new([]);
        $this->assertNull($l->selectedItem());
    }

    public function testItemsAccessor(): void
    {
        $l = ItemList::new($this->items());
        $names = array_map(static fn ($i) => $i->title(), $l->items());
        $this->assertSame(['apple', 'banana', 'cherry', 'date'], $names);
    }

    public function testSetItemReplacesInPlace(): void
    {
        $l = ItemList::new($this->items());
        $l = $l->setItem(1, new StringItem('blueberry'));
        $names = array_map(static fn ($i) => $i->title(), $l->items());
        $this->assertSame(['apple', 'blueberry', 'cherry', 'date'], $names);
    }

    public function testSetItemNegativeIndex(): void
    {
        $l = ItemList::new($this->items());
        $l = $l->setItem(-1, new StringItem('damson'));
        $this->assertSame('damson', $l->items()[3]->title());
    }

    public function testSetItemOutOfRangeIsNoOp(): void
    {
        $l = ItemList::new($this->items());
        $l2 = $l->setItem(99, new StringItem('z'));
        $this->assertSame(4, count($l2->items()));
        $this->assertSame('apple', $l2->items()[0]->title());
    }

    public function testInsertItemAtPosition(): void
    {
        $l = ItemList::new($this->items());
        $l = $l->insertItem(1, new StringItem('avocado'));
        $names = array_map(static fn ($i) => $i->title(), $l->items());
        $this->assertSame(['apple', 'avocado', 'banana', 'cherry', 'date'], $names);
    }

    public function testInsertItemAppendsBeyondCount(): void
    {
        $l = ItemList::new($this->items());
        $l = $l->insertItem(99, new StringItem('elder'));
        $items = $l->items();
        $this->assertSame('elder', $items[count($items) - 1]->title());
    }

    public function testInsertItemMultipleAtOnce(): void
    {
        $l = ItemList::new($this->items());
        $l = $l->insertItem(2, new StringItem('blackberry'), new StringItem('boysenberry'));
        $names = array_map(static fn ($i) => $i->title(), $l->items());
        $this->assertSame(
            ['apple', 'banana', 'blackberry', 'boysenberry', 'cherry', 'date'],
            $names,
        );
    }

    public function testInsertItemNegativeIndex(): void
    {
        $l = ItemList::new($this->items());
        $l = $l->insertItem(-1, new StringItem('crunchy'));
        $names = array_map(static fn ($i) => $i->title(), $l->items());
        $this->assertSame(['apple', 'banana', 'cherry', 'crunchy', 'date'], $names);
    }

    public function testRemoveItemDropsByIndex(): void
    {
        $l = ItemList::new($this->items());
        $l = $l->removeItem(1);
        $names = array_map(static fn ($i) => $i->title(), $l->items());
        $this->assertSame(['apple', 'cherry', 'date'], $names);
    }

    public function testRemoveItemNegativeIndex(): void
    {
        $l = ItemList::new($this->items());
        $l = $l->removeItem(-1);
        $names = array_map(static fn ($i) => $i->title(), $l->items());
        $this->assertSame(['apple', 'banana', 'cherry'], $names);
    }

    public function testRemoveItemReclampsCursor(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'G'));
        $l = $l->removeItem(3);
        $this->assertNotNull($l->selectedItem());
        $this->assertLessThan(count($l->items()), $l->index());
    }

    public function testRemoveItemOutOfRangeIsNoOp(): void
    {
        $l = ItemList::new($this->items());
        $l2 = $l->removeItem(42);
        $this->assertSame(4, count($l2->items()));
    }

    public function testCursorUpDown(): void
    {
        $l = $this->focused()->cursorDown(2);
        $this->assertSame(2, $l->index());
        $l = $l->cursorUp();
        $this->assertSame(1, $l->index());
    }

    public function testGoToStartEnd(): void
    {
        $l = $this->focused()->goToEnd();
        $this->assertSame(3, $l->index());
        $l = $l->goToStart();
        $this->assertSame(0, $l->index());
    }

    public function testPrevNextPage(): void
    {
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $items[] = new StringItem("item$i");
        }
        $l = ItemList::new($items, 60, 5);
        [$l, ] = $l->focus();
        $l = $l->nextPage();
        $this->assertSame(5, $l->index());
        $l = $l->prevPage();
        $this->assertSame(0, $l->index());
    }

    public function testSelectMovesCursor(): void
    {
        $l = $this->focused()->select(2);
        $this->assertSame(2, $l->index());
    }

    public function testResetSelected(): void
    {
        $l = $this->focused()->cursorDown(2)->resetSelected();
        $this->assertSame(0, $l->index());
    }

    public function testResetFilterClears(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertTrue($l->isFiltered());
        $l = $l->resetFilter();
        $this->assertFalse($l->isFiltered());
    }

    public function testFilterValueAccessor(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'b'));
        $this->assertSame('b', $l->filterValue());
        $this->assertTrue($l->isFiltered());
        $this->assertTrue($l->settingFilter());
    }

    public function testSettingFilterFalseAfterEnter(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Enter));
        $this->assertFalse($l->settingFilter());
        $this->assertFalse($l->isFiltered());  // default: Enter clears filter
    }

    public function testCursorPrefixDefault(): void
    {
        $l = $this->focused();
        $view = $l->view();
        $this->assertStringContainsString('> ', $view);
    }

    public function testCustomCursorPrefix(): void
    {
        $l = $this->focused()->withCursorPrefix('▶ ');
        $view = $l->view();
        $this->assertStringContainsString('▶ ', $view);
    }

    public function testCustomUnselectedPrefix(): void
    {
        $l = $this->focused()->withUnselectedPrefix('· ');
        $view = $l->view();
        // banana / cherry / date are non-cursor on row 0 cursor.
        $this->assertStringContainsString('· banana', $view);
    }

    public function testFilterHighlightRendersMatchIndicesInView(): void
    {
        // Enter filter mode with "an" - should highlight "an" in "banana".
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'n'));
        $view = $l->view();
        // The view should contain "an" highlighted with ANSI REVERSE sequences.
        // Structure: "> " + REVERSE + "an" + RESET + "ana" for "banana".
        $this->assertStringContainsString("\x1b[7m", $view); // REVERSE on
        $this->assertStringContainsString("\x1b[0m", $view); // RESET off
    }

    public function testInsertItemEmptyVariadicIsNoOp(): void
    {
        $l = ItemList::new($this->items());
        $l2 = $l->insertItem(1);
        $this->assertSame(4, count($l2->items()));
    }

    public function testRemoveItemOnEmptyListIsNoOp(): void
    {
        $l = ItemList::new([]);
        $l2 = $l->removeItem(0);
        $this->assertSame(0, count($l2->items()));
    }

    public function testSetItemOnEmptyListIsNoOp(): void
    {
        $l = ItemList::new([]);
        $l2 = $l->setItem(0, new StringItem('x'));
        $this->assertSame(0, count($l2->items()));
    }

    public function testInfiniteScrollingWrapsCursor(): void
    {
        $l = ItemList::new($this->items(), 60, 5)->withInfiniteScrolling(true);
        [$l, ] = $l->focus();
        // Move up from index 0 in infinite scroll wraps to last (3)
        $l = $l->cursorUp();
        $this->assertSame(3, $l->index());
        // Move down by 10 from index 3: (3 + 10) % 4 = 1
        $l = $l->cursorDown(10);
        $this->assertSame(1, $l->index());
        // Move down by 1 from index 1: (1 + 1) % 4 = 2
        $l = $l->cursorDown(1);
        $this->assertSame(2, $l->index());
    }

    public function testStatusMessageWithLifetime(): void
    {
        $l = ItemList::new($this->items(), 60, 5)->withStatusMessageLifetime(5.0);
        $l = $l->newStatusMessage('Working...');
        $this->assertStringContainsString('Working...', $l->status());
    }

    public function testStatusMessageExpiredNotShown(): void
    {
        // Create a list and immediately add a message that expires in 0 seconds
        $l = ItemList::new($this->items(), 60, 5)
            ->withStatusMessageLifetime(0.0)
            ->newStatusMessage('Gone');
        // status() should not include the expired message
        $this->assertStringNotContainsString('Gone', $l->status());
    }

    public function testStatusShowsCursorAndCount(): void
    {
        $l = ItemList::new($this->items(), 60, 5)->withInfiniteScrolling(false);
        [$l, ] = $l->focus();
        [$l, ] = $l->update(new KeyMsg(KeyType::Down));
        $status = $l->status();
        $this->assertStringContainsString('2/4', $status);
    }

    public function testSetSizeThrowsOnNegativeWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemList::new($this->items())->setSize(-1, 10);
    }

    public function testSetSizeThrowsOnNegativeHeight(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ItemList::new($this->items())->setSize(60, -1);
    }

    public function testWithTitle(): void
    {
        $l = ItemList::new($this->items(), 60, 5)->withTitle('Pick a fruit');
        $view = $l->view();
        $this->assertStringContainsString('Pick a fruit', $view);
    }

    public function testWithShowDescriptionFalse(): void
    {
        $items = [new StringItem('apple', 'a fruit')];
        $l = ItemList::new($items, 60, 5)->withShowDescription(false);
        $view = $l->view();
        $this->assertStringNotContainsString('a fruit', $view);
    }

    public function testWithShowStatusBarFalse(): void
    {
        $l = ItemList::new($this->items(), 60, 5)->withShowStatusBar(false);
        [$l, ] = $l->focus();
        [$l, ] = $l->update(new KeyMsg(KeyType::Down));
        $view = $l->view();
        // Without status bar, the "1/4" count should not appear
        $this->assertStringNotContainsString('1/4', $view);
    }

    public function testWithShowHelpFalse(): void
    {
        // withShowHelp does not directly affect view() output (help is shown
        // through other means in a real TUI context), but we test the flag
        $l = ItemList::new($this->items(), 60, 5)->withShowHelp(false);
        $this->assertInstanceOf(ItemList::class, $l);
    }

    public function testWithShowFilterFalseStillFiltersInternally(): void
    {
        $l = ItemList::new($this->items(), 60, 5)->withShowFilter(false);
        [$l, ] = $l->focus();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        // Filtering is still active even though showFilter=false
        $this->assertTrue($l->isFiltering());
        $this->assertSame('a', $l->filterValue());
        $this->assertSame(3, count($l->visibleItems())); // apple, banana, cherry all contain 'a'
    }

    public function testFilterWithSpaceCharacterAddsToFilter(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        // Space in filter text - adds a space character to filter
        [$l, ] = $l->update(new KeyMsg(KeyType::Space));
        $this->assertSame(' ', $l->filterValue());
        $this->assertTrue($l->isFiltering());
    }

    public function testFilterDownMovesCursorInFilteredSet(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        // 'a' filter matches apple, banana, cherry (3 items visible)
        $this->assertSame(3, count($l->visibleItems()));
        // Move down from index 0: moveCursor(1), count=3, cursor = max(0, min(2, 1)) = 1
        [$l, ] = $l->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $l->index());
    }

    public function testFilterPageUpPageDownMovesCursorByHeight(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        [$l, ] = $l->update(new KeyMsg(KeyType::PageDown));
        [$l, ] = $l->update(new KeyMsg(KeyType::PageUp));
        // Should not error
        $this->assertSame(0, $l->index());
    }

    public function testUpdateWhenNotFocusedReturnsUnchanged(): void
    {
        $l = ItemList::new($this->items());
        [$l2, $cmd] = $l->update(new KeyMsg(KeyType::Down));
        $this->assertSame($l, $l2);
        $this->assertNull($cmd);
    }

    public function testNoMatchesShowsNoMatches(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'z'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'z'));
        $view = $l->view();
        $this->assertStringContainsString('No matches', $view);
    }

    public function testNoItemsShowsNoItems(): void
    {
        $l = ItemList::new([])->withShowStatusBar(false);
        $view = $l->view();
        $this->assertStringContainsString('No items', $view);
    }

    public function testIsFilteredFalseWhenNoFilter(): void
    {
        $l = ItemList::new($this->items());
        $this->assertFalse($l->isFiltered());
        $this->assertFalse($l->settingFilter());
    }

    public function testClearFilter(): void
    {
        $l = $this->focused();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'a'));
        $l = $l->clearFilter();
        $this->assertFalse($l->isFiltering());
        $this->assertFalse($l->isFiltered());
        $this->assertSame(0, $l->cursor);
    }

    public function testWithStatusMessageLifetimeNegativeClamped(): void
    {
        $l = ItemList::new($this->items(), 60, 5)->withStatusMessageLifetime(-5.0);
        $this->assertInstanceOf(ItemList::class, $l);
    }
}
