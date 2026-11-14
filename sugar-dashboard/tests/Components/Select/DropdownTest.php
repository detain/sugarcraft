<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Select;

use SugarCraft\Dashboard\Components\Select\Dropdown;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class DropdownTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testDropdownImplementsSizer(): void
    {
        $dropdown = Dropdown::new('Menu', [['label' => 'Item']]);
        $this->assertInstanceOf(Sizer::class, $dropdown);
    }

    public function testDropdownImplementsItem(): void
    {
        $dropdown = Dropdown::new('Menu', [['label' => 'Item']]);
        $this->assertInstanceOf(Item::class, $dropdown);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $dropdown = Dropdown::new('Menu', [['label' => 'Item']]);
        $rendered = $dropdown->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $dropdown = Dropdown::new('My Menu', [['label' => 'Item']]);
        $rendered = $dropdown->render();

        $this->assertStringContainsString('My Menu', $rendered);
    }

    public function testRenderCollapsedShowsExpandIcon(): void
    {
        $dropdown = Dropdown::new('Menu', [['label' => 'Item']]);
        $rendered = $dropdown->render();

        $this->assertStringContainsString('▾', $rendered);
    }

    public function testRenderExpandedShowsCollapseIcon(): void
    {
        $dropdown = Dropdown::new('Menu', [['label' => 'Item']])->withExpanded(true);
        $rendered = $dropdown->render();

        $this->assertStringContainsString('▸', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Expanded state
    // ═══════════════════════════════════════════════════════════════

    public function testExpandedRendersItems(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'Item 1'],
            ['label' => 'Item 2'],
        ])->withExpanded(true);

        $rendered = $dropdown->render();

        $this->assertStringContainsString('Item 1', $rendered);
        $this->assertStringContainsString('Item 2', $rendered);
    }

    public function testCollapsedDoesNotRenderItems(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'Item 1'],
            ['label' => 'Item 2'],
        ])->withExpanded(false);

        $rendered = $dropdown->render();

        // Collapsed shows ONE row: the trigger. D-56 (finding 050) put the
        // current selection ON the trigger, so the selected label is
        // present by design — the un-selected item must be absent, and so
        // must the menu's selection marker.
        $this->assertStringContainsString('Menu', $rendered);
        $this->assertStringContainsString('Item 1', $rendered);
        $this->assertStringNotContainsString('Item 2', $rendered);
        $this->assertStringNotContainsString('▶', $rendered);
        $this->assertSame(1, count(explode("\n", $rendered)));
    }

    public function testEmptyItemsRendersWithoutError(): void
    {
        $dropdown = Dropdown::new('Menu', [])->withExpanded(true);
        $rendered = $dropdown->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Selection behavior
    // ═══════════════════════════════════════════════════════════════

    public function testSelectedItemShowsIndicator(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'Item 1'],
            ['label' => 'Item 2'],
        ])->withExpanded(true)->withSelectedIndex(1);

        $rendered = $dropdown->render();

        // Second item should have indicator
        $this->assertStringContainsString('▶', $rendered);
    }

    public function testSwitchingSelection(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withExpanded(true);

        $dropdown2 = $dropdown->withSelectedIndex(1);
        $rendered = $dropdown2->render();

        $this->assertStringContainsString('Second', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Icons support
    // ═══════════════════════════════════════════════════════════════

    public function testItemWithIcon(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'Settings', 'icon' => '⚙'],
        ])->withExpanded(true);

        $rendered = $dropdown->render();

        $this->assertStringContainsString('⚙', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testTriggerColorAddsAnsiCodes(): void
    {
        $dropdown = Dropdown::new('Menu', [['label' => 'Item']])
            ->withTriggerColor(Color::ansi(9));
        $rendered = $dropdown->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testSelectedItemColorAddsAnsiCodes(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'Item'],
        ])->withExpanded(true)
            ->withSelectedItemColor(Color::ansi(9));

        $rendered = $dropdown->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom icons
    // ═══════════════════════════════════════════════════════════════

    public function testCustomIcons(): void
    {
        $dropdown = Dropdown::new('Menu', [['label' => 'Item']])
            ->withIcons('[+]', '[-]');

        $rendered = $dropdown->render();

        $this->assertStringContainsString('[+]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithExpandedReturnsNewInstance(): void
    {
        $original = Dropdown::new('Menu', [['label' => 'Item']]);
        $updated = $original->withExpanded(true);

        $this->assertNotSame($original, $updated);
    }

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = Dropdown::new('Menu', [['label' => 'Item']]);
        $updated = $original->withSelectedIndex(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Dropdown::new('Menu', [['label' => 'Item']]);
        $updated = $original->withItems([['label' => 'New Item']]);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithExpanded(): void
    {
        $original = Dropdown::new('Menu', [['label' => 'Item']]);
        $original->withExpanded(true);

        $rendered = $original->render();
        $this->assertStringContainsString('▾', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Dropdown::new('Menu', [['label' => 'Item']]);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeCollapsedReturnsTriggerHeight(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'Item 1'],
            ['label' => 'Item 2'],
        ]);

        [$w, $h] = $dropdown->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeExpandedIncludesItems(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'Item 1'],
            ['label' => 'Item 2'],
        ])->withExpanded(true);

        [$w, $h] = $dropdown->innerSize();

        $this->assertSame(3, $h); // 1 trigger + 2 items
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testNegativeIndexClampedToZero(): void
    {
        $dropdown = Dropdown::new('Menu', [['label' => 'Item']])
            ->withSelectedIndex(-5);

        $this->assertNotSame('', $dropdown->render());
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(100);

        $this->assertNotSame('', $dropdown->render());
    }

    public function testUnicodeLabel(): void
    {
        $dropdown = Dropdown::new('菜單', [['label' => '項目']]);
        $rendered = $dropdown->render();

        $this->assertStringContainsString('菜單', $rendered);
    }

    public function testWithItemsClampsSelectedIndex(): void
    {
        $original = Dropdown::new('Menu', [
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(1);

        $updated = $original->withItems([['label' => 'Only']]);
        $this->assertNotSame('', $updated->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // D-56 (finding sugar-dashboard-050): selection on trigger,
    // allocation stored AND read
    // ═══════════════════════════════════════════════════════════════

    public function testClosedTriggerShowsTheSelectedOption(): void
    {
        $dropdown = Dropdown::new('Fruit', [
            ['label' => 'Apple'],
            ['label' => 'Pear'],
        ])->withSelectedIndex(1);

        $this->assertStringContainsString('Pear', $dropdown->render());
    }

    public function testClosedTriggerShowsNonePlaceholderWhenEmpty(): void
    {
        $dropdown = Dropdown::new('Fruit', []);

        $this->assertStringContainsString('— none —', $dropdown->render());
    }

    public function testSetSizeStoresAllocationReadByInnerSize(): void
    {
        $natural = Dropdown::new('Menu', [['label' => 'Item 1']]);
        $sized = $natural->setSize(9, 4);

        $this->assertSame(9, $sized->innerSize()[0]);
        $this->assertNotSame(9, $natural->innerSize()[0]);
    }

    public function testAllocationFitsEveryPaintedRowToTheWidth(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'An extremely long option label that overflows'],
            ['label' => 'Short'],
        ])
            ->withExpanded(true)
            ->withSelectedItemColor(null)
            ->withMenuColor(null)
            ->setSize(20, 0);

        foreach (explode("\n", $dropdown->render()) as $row) {
            $this->assertSame(20, \SugarCraft\Core\Util\Width::string($row), 'row escape: ' . $row);
        }
    }

    public function testOpenListIsWindowedToTheAllocatedHeight(): void
    {
        $items = [];
        for ($i = 1; $i <= 10; $i++) {
            $items[] = ['label' => 'Opt ' . sprintf('%02d', $i)];
        }

        $dropdown = Dropdown::new('Menu', $items)
            ->withExpanded(true)
            ->withSelectedIndex(9)
            ->setSize(0, 4);

        $rows = explode("\n", $dropdown->render());
        $this->assertCount(4, $rows);                          // trigger + 3 slots
        $this->assertStringContainsString('Opt 10', $rows[3]); // selection visible at the edge
        $this->assertStringNotContainsString('Opt 01', $dropdown->render());
    }

    public function testWithersCarryTheAllocation(): void
    {
        $sized = Dropdown::new('Menu', [['label' => 'Item']])->setSize(30, 5);

        $this->assertSame(30, $sized->withLabel('Other')->innerSize()[0]);
        $this->assertSame(30, $sized->withItems([['label' => 'X']])->innerSize()[0]);
        $this->assertSame(30, $sized->withExpanded(true)->innerSize()[0]);
        $this->assertSame(30, $sized->withSelectedIndex(0)->innerSize()[0]);
        $this->assertSame(30, $sized->withIcons('v', '^')->innerSize()[0]);
    }
}
