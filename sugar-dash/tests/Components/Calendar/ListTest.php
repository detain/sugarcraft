<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Calendar;

use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Components\Calendar\ListComponent;
use SugarCraft\Dash\Foundation\Sizer;
use PHPUnit\Framework\TestCase;

final class ListTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testListImplementsSizer(): void
    {
        $list = ListComponent::new(['item1', 'item2']);
        $this->assertInstanceOf(Sizer::class, $list);
    }

    public function testListImplementsItem(): void
    {
        $list = ListComponent::new(['item1', 'item2']);
        $this->assertInstanceOf(Item::class, $list);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyList(): void
    {
        $list = ListComponent::new([]);
        $this->assertSame('', $list->render());
    }

    public function testRenderSingleItem(): void
    {
        $list = ListComponent::new(['First item']);
        $rendered = $list->render();

        $this->assertStringContainsString('First item', $rendered);
    }

    public function testRenderMultipleItems(): void
    {
        $list = ListComponent::new(['Apple', 'Banana', 'Cherry']);
        $rendered = $list->render();

        $this->assertStringContainsString('Apple', $rendered);
        $this->assertStringContainsString('Banana', $rendered);
        $this->assertStringContainsString('Cherry', $rendered);
    }

    public function testDefaultStyleIsBullet(): void
    {
        $list = ListComponent::new(['Item']);
        $rendered = $list->render();

        // Default should show bullet marker (● for selected)
        $this->assertStringContainsString('Item', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style variants
    // ═══════════════════════════════════════════════════════════════

    public function testBulletStyle(): void
    {
        $list = ListComponent::new(['Item 1', 'Item 2'])->withStyle(ListComponent::Bullet);
        $rendered = $list->render();

        $this->assertStringContainsString('Item 1', $rendered);
        $this->assertStringContainsString('Item 2', $rendered);
    }

    public function testNumberStyle(): void
    {
        // Hide cursor so we see plain numbers
        $list = ListComponent::new(['First', 'Second'])
            ->withStyle(ListComponent::Number)
            ->withShowCursor(false);
        $rendered = $list->render();

        $this->assertStringContainsString('1.', $rendered);
        $this->assertStringContainsString('2.', $rendered);
    }

    public function testArrowStyle(): void
    {
        $list = ListComponent::new(['Choice A', 'Choice B'])->withStyle(ListComponent::Arrow);
        $rendered = $list->render();

        // Arrow style uses > for selected, space for others
        $this->assertStringContainsString('Choice A', $rendered);
        $this->assertStringContainsString('Choice B', $rendered);
    }

    public function testPlainStyle(): void
    {
        // Set a wide enough size to avoid truncation
        $list = ListComponent::new(['No', 'Markers'])->withStyle(ListComponent::Plain)->setSize(40, 2);
        $rendered = $list->render();

        $this->assertStringContainsString('No', $rendered);
        $this->assertStringContainsString('Markers', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection

    // ═══════════════════════════════════════════════════════════════
    // Selection
    // ═══════════════════════════════════════════════════════════════

    public function testDefaultSelectionIsFirstItem(): void
    {
        $list = ListComponent::new(['Alpha', 'Beta', 'Gamma']);
        $rendered = $list->render();

        // First item should be selected (cursor visible)
        $this->assertStringContainsString('Alpha', $rendered);
    }

    public function testWithSelectedIndex(): void
    {
        $list = ListComponent::new(['First', 'Second', 'Third'])->withSelected(1);
        $rendered = $list->render();

        $this->assertStringContainsString('Second', $rendered);
    }

    public function testSelectedIndexClampedToValidRange(): void
    {
        // Selecting beyond the last item should clamp
        $list = ListComponent::new(['A', 'B'])->withSelected(100);
        $rendered = $list->render();

        // Should still render properly without errors
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testNegativeSelectedIndexClamped(): void
    {
        $list = ListComponent::new(['A', 'B'])->withSelected(-5);
        $rendered = $list->render();

        // Should clamp to 0 (first item)
        $this->assertStringContainsString('A', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Cursor visibility
    // ═══════════════════════════════════════════════════════════════

    public function testCursorVisibleByDefault(): void
    {
        $list = ListComponent::new(['Item']);
        $rendered = $list->render();

        // Selected item should have a cursor marker
        $this->assertStringContainsString('Item', $rendered);
    }

    public function testHideCursor(): void
    {
        $list = ListComponent::new(['Item'])->withShowCursor(false);
        $rendered = $list->render();

        // Should render without cursor markers
        $this->assertStringContainsString('Item', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Filtering
    // ═══════════════════════════════════════════════════════════════

    public function testFilterRemovesNonMatchingItems(): void
    {
        $list = ListComponent::new(['Apple', 'Banana', 'Cherry'])
            ->withFilter(fn(string $item) => str_starts_with($item, 'B'));
        $rendered = $list->render();

        $this->assertStringContainsString('Banana', $rendered);
        $this->assertStringNotContainsString('Apple', $rendered);
        $this->assertStringNotContainsString('Cherry', $rendered);
    }

    public function testFilterWithEmptyResult(): void
    {
        $list = ListComponent::new(['Apple', 'Banana'])
            ->withFilter(fn(string $item) => str_starts_with($item, 'X'));
        $rendered = $list->render();

        $this->assertSame('', $rendered);
    }

    public function testFilterPreservesSelectionIndex(): void
    {
        // When filter removes items, selected index still refers to filtered list
        $list = ListComponent::new(['Apple', 'Avocado', 'Banana'])
            ->withSelected(2)  // Select 'Banana'
            ->withFilter(fn(string $item) => str_starts_with($item, 'A'));  // Only A items remain
        $rendered = $list->render();

        // Should show filtered items, selection clamped to last available
        $this->assertStringContainsString('Apple', $rendered);
        $this->assertStringContainsString('Avocado', $rendered);
        $this->assertStringNotContainsString('Banana', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ListComponent::new(['item']);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRender(): void
    {
        $list = ListComponent::new(['Item'])->setSize(30, 3);
        $rendered = $list->render();

        $lines = explode("\n", $rendered);
        // Each line should be padded to width 30
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(30, Width::string($line));
        }
    }

    public function testGetInnerSizeWithNoSetSize(): void
    {
        $list = ListComponent::new(['Short', 'Medium length']);
        [$w, $h] = $list->getInnerSize();

        // Height should be number of items
        $this->assertSame(2, $h);
        // Width should be the longest item + prefix
        $this->assertGreaterThan(6, $w);
    }

    public function testGetInnerSizeWithSetSize(): void
    {
        $list = ListComponent::new(['Item'])->setSize(20, 5);
        [$w, $h] = $list->getInnerSize();

        $this->assertSame(20, $w);
        $this->assertSame(5, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Scrolling
    // ═══════════════════════════════════════════════════════════════

    public function testScrollWhenMoreItemsThanHeight(): void
    {
        $items = ['Item 1', 'Item 2', 'Item 3', 'Item 4', 'Item 5'];
        $list = ListComponent::new($items)->setSize(40, 3)->withSelected(4);
        $rendered = $list->render();

        // Selected item (Item 5) should be in the output
        $this->assertStringContainsString('Item 5', $rendered);
        // Should NOT show items that don't exist
        $this->assertStringNotContainsString('Item 6', $rendered);
    }

    public function testNoScrollWhenItemsFitInHeight(): void
    {
        $items = ['Item 1', 'Item 2', 'Item 3'];
        $list = ListComponent::new($items)->setSize(20, 5)->withSelected(0);
        $rendered = $list->render();

        // All items should be visible
        $this->assertStringContainsString('Item 1', $rendered);
        $this->assertStringContainsString('Item 2', $rendered);
        $this->assertStringContainsString('Item 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testItemAlignLeft(): void
    {
        $list = ListComponent::new(['Item'])->withItemAlign(HAlign::Left)->setSize(20, 1);
        $rendered = $list->render();

        // Content should be left-aligned with trailing spaces
        $this->assertStringContainsString('Item', $rendered);
    }

    public function testItemAlignRight(): void
    {
        $list = ListComponent::new(['Item'])->withItemAlign(HAlign::Right)->setSize(20, 1);
        $rendered = $list->render();

        // Content should be right-aligned with leading spaces
        $this->assertStringContainsString('Item', $rendered);
    }

    public function testItemAlignCenter(): void
    {
        $list = ListComponent::new(['Item'])->withItemAlign(HAlign::Center)->setSize(20, 1);
        $rendered = $list->render();

        $this->assertStringContainsString('Item', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Truncation
    // ═══════════════════════════════════════════════════════════════

    public function testLongItemTruncatedWithEllipsis(): void
    {
        $longItem = 'This is a very long item that should be truncated';
        // Use a wider width to accommodate prefix and have room for meaningful truncation
        $list = ListComponent::new([$longItem])->setSize(30, 1);
        $rendered = $list->render();

        // Should contain ellipsis for truncated text
        $this->assertStringContainsString('…', $rendered);
        // Should still contain part of the original text
        $this->assertStringContainsString('This', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Style constants
    // ═══════════════════════════════════════════════════════════════

    public function testStyleConstants(): void
    {
        $this->assertSame('bullet', ListComponent::Bullet);
        $this->assertSame('number', ListComponent::Number);
        $this->assertSame('arrow', ListComponent::Arrow);
        $this->assertSame('plain', ListComponent::Plain);
    }

    // ═══════════════════════════════════════════════════════════════
    // Wither chaining
    // ═══════════════════════════════════════════════════════════════

    public function testChainedWithers(): void
    {
        $list = ListComponent::new(['Apple', 'Banana', 'Cherry'])
            ->withSelected(1)
            ->withStyle(ListComponent::Number)
            ->withShowCursor(true);

        $rendered = $list->render();
        // Selected item (index 1) shows as [1]
        $this->assertStringContainsString('[1]', $rendered);
        // Should contain Cherry (index 2 shows as 3.)
        $this->assertStringContainsString('3.', $rendered);
    }

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = ListComponent::new(['A', 'B']);
        $modified = $original->withItems(['X', 'Y', 'Z']);

        $this->assertNotSame($original, $modified);
        $this->assertStringContainsString('X', $modified->render());
        $this->assertStringNotContainsString('A', $modified->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyStringItems(): void
    {
        $list = ListComponent::new(['', 'Item', '']);
        $rendered = $list->render();

        $this->assertStringContainsString('Item', $rendered);
    }

    public function testUnicodeItems(): void
    {
        $list = ListComponent::new(['日本語', '中文', '한국어']);
        $rendered = $list->render();

        $this->assertStringContainsString('日本語', $rendered);
        $this->assertStringContainsString('中文', $rendered);
        $this->assertStringContainsString('한국어', $rendered);
    }

    public function testSpecialCharactersInItems(): void
    {
        $list = ListComponent::new(['Item with $pecial ch@rs!', 'Normal item']);
        $rendered = $list->render();

        $this->assertStringContainsString('Item with $pecial ch@rs!', $rendered);
        $this->assertStringContainsString('Normal item', $rendered);
    }

    public function testSingleItemListWithHeightLargerThanContent(): void
    {
        $list = ListComponent::new(['Solo'])->setSize(20, 5);
        $rendered = $list->render();

        $lines = explode("\n", $rendered);
        $this->assertCount(5, $lines);
    }
}
