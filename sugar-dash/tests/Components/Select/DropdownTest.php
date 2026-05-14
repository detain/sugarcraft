<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Select;

use SugarCraft\Dash\Components\Select\Dropdown;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
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

        // Should only show trigger, not items
        $this->assertStringContainsString('Menu', $rendered);
        $this->assertStringNotContainsString('Item 1', $rendered);
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

        [$w, $h] = $dropdown->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeExpandedIncludesItems(): void
    {
        $dropdown = Dropdown::new('Menu', [
            ['label' => 'Item 1'],
            ['label' => 'Item 2'],
        ])->withExpanded(true);

        [$w, $h] = $dropdown->getInnerSize();

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
}
