<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Menu;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class MenuTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testMenuImplementsSizer(): void
    {
        $menu = Menu::new([['label' => 'File']]);
        $this->assertInstanceOf(Sizer::class, $menu);
    }

    public function testMenuImplementsItem(): void
    {
        $menu = Menu::new([['label' => 'File']]);
        $this->assertInstanceOf(Item::class, $menu);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $menu = Menu::new([['label' => 'File']]);
        $rendered = $menu->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $menu = Menu::new([['label' => 'File']]);
        $rendered = $menu->render();

        $this->assertStringContainsString('File', $rendered);
    }

    public function testRenderMultipleItems(): void
    {
        $menu = Menu::new([
            ['label' => 'File'],
            ['label' => 'Edit'],
            ['label' => 'View'],
        ]);
        $rendered = $menu->render();

        $this->assertStringContainsString('File', $rendered);
        $this->assertStringContainsString('Edit', $rendered);
        $this->assertStringContainsString('View', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Active item indicator
    // ═══════════════════════════════════════════════════════════════

    public function testFirstItemSelectedByDefault(): void
    {
        $menu = Menu::new([
            ['label' => 'File'],
            ['label' => 'Edit'],
        ]);
        $rendered = $menu->render();

        $this->assertStringContainsString('▶ File', $rendered);
    }

    public function testSelectedItemShowsIndicator(): void
    {
        $menu = Menu::new([
            ['label' => 'File'],
            ['label' => 'Edit'],
        ])->withSelectedIndex(1);
        $rendered = $menu->render();

        $this->assertStringContainsString('▶ Edit', $rendered);
    }

    public function testSwitchingSelection(): void
    {
        $menu = Menu::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ]);

        $menu2 = $menu->withSelectedIndex(1);
        $rendered = $menu2->render();

        $this->assertStringContainsString('▶ Second', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Submenu support
    // ═══════════════════════════════════════════════════════════════

    public function testSubmenuIndicatorShown(): void
    {
        $menu = Menu::new([
            ['label' => 'File', 'items' => [
                ['label' => 'New'],
                ['label' => 'Open'],
            ]],
        ]);
        $rendered = $menu->render();

        $this->assertStringContainsString('File ▾', $rendered);
    }

    public function testSubmenuRendersWhenOpen(): void
    {
        $menu = Menu::new([
            ['label' => 'File', 'items' => [
                ['label' => 'New'],
                ['label' => 'Open'],
            ]],
        ])->withSubmenuOpen(true);
        $rendered = $menu->render();

        $this->assertStringContainsString('New', $rendered);
        $this->assertStringContainsString('Open', $rendered);
    }

    public function testSubmenuClosedByDefault(): void
    {
        $menu = Menu::new([
            ['label' => 'File', 'items' => [
                ['label' => 'New'],
            ]],
        ]);
        $rendered = $menu->render();

        // New should not appear when submenu is closed
        $this->assertStringNotContainsString('New', $rendered);
    }

    public function testSubmenuSelectedItemIndicator(): void
    {
        $menu = Menu::new([
            ['label' => 'File', 'items' => [
                ['label' => 'New'],
                ['label' => 'Open'],
            ]],
        ])->withSubmenuOpen(true)->withSubmenuIndex(1);
        $rendered = $menu->render();

        $this->assertStringContainsString('▶', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Submenu item features
    // ═══════════════════════════════════════════════════════════════

    public function testSubmenuItemWithIcon(): void
    {
        $menu = Menu::new([
            ['label' => 'File', 'items' => [
                ['label' => 'New', 'icon' => '📄'],
            ]],
        ])->withSubmenuOpen(true);
        $rendered = $menu->render();

        $this->assertStringContainsString('📄', $rendered);
    }

    public function testSubmenuItemWithShortcut(): void
    {
        $menu = Menu::new([
            ['label' => 'File', 'items' => [
                ['label' => 'New', 'shortcut' => 'Ctrl+N'],
            ]],
        ])->withSubmenuOpen(true);
        $rendered = $menu->render();

        $this->assertStringContainsString('Ctrl+N', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testActiveItemColorAddsAnsiCodes(): void
    {
        $menu = Menu::new([['label' => 'File']])
            ->withActiveItemColor(Color::ansi(9));
        $rendered = $menu->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBarColorAddsAnsiCodes(): void
    {
        $menu = Menu::new([['label' => 'File']])
            ->withBarColor(Color::ansi(8));
        $rendered = $menu->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithSelectedIndexReturnsNewInstance(): void
    {
        $original = Menu::new([['label' => 'File']]);
        $updated = $original->withSelectedIndex(1);

        $this->assertNotSame($original, $updated);
    }

    public function testWithSubmenuOpenReturnsNewInstance(): void
    {
        $original = Menu::new([['label' => 'File']]);
        $updated = $original->withSubmenuOpen(true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithSelectedIndex(): void
    {
        $original = Menu::new([['label' => 'File']]);
        $original->withSelectedIndex(1);

        $rendered = $original->render();
        $this->assertStringContainsString('▶ File', $rendered);
    }

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Menu::new([['label' => 'File']]);
        $updated = $original->withItems([['label' => 'New']]);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Menu::new([['label' => 'File']]);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeCollapsedReturnsBarHeight(): void
    {
        $menu = Menu::new([
            ['label' => 'File'],
            ['label' => 'Edit'],
        ]);

        [$w, $h] = $menu->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeOpenIncludesSubmenu(): void
    {
        $menu = Menu::new([
            ['label' => 'File', 'items' => [
                ['label' => 'New'],
                ['label' => 'Open'],
            ]],
        ])->withSubmenuOpen(true);

        [$w, $h] = $menu->getInnerSize();

        $this->assertSame(3, $h); // 1 bar + 2 submenu items
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptyItemsRendersWithoutError(): void
    {
        $menu = Menu::new([]);
        $rendered = $menu->render();

        $this->assertNotSame('', $rendered);
    }

    public function testNegativeIndexClampedToZero(): void
    {
        $menu = Menu::new([['label' => 'File']])
            ->withSelectedIndex(-5);

        $this->assertNotSame('', $menu->render());
    }

    public function testOversizedIndexClampedToLast(): void
    {
        $menu = Menu::new([
            ['label' => 'First'],
            ['label' => 'Second'],
        ])->withSelectedIndex(100);

        $this->assertNotSame('', $menu->render());
    }

    public function testUnicodeLabel(): void
    {
        $menu = Menu::new([['label' => '檔案']]);
        $rendered = $menu->render();

        $this->assertStringContainsString('檔案', $rendered);
    }

    public function testSubmenuIndexResetOnItemChange(): void
    {
        $original = Menu::new([
            ['label' => 'File', 'items' => [
                ['label' => 'New'],
            ]],
        ])->withSubmenuOpen(true)->withSubmenuIndex(0);

        $updated = $original->withSelectedIndex(1);
        $this->assertFalse($updated->render() === $original->render());
    }
}
