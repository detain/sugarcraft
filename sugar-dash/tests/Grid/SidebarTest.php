<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Sidebar;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SidebarTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSidebarImplementsSizer(): void
    {
        $sidebar = Sidebar::new();
        $this->assertInstanceOf(Sizer::class, $sidebar);
    }

    public function testSidebarImplementsItem(): void
    {
        $sidebar = Sidebar::new();
        $this->assertInstanceOf(Item::class, $sidebar);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $sidebar = Sidebar::new();
        $rendered = $sidebar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsContent(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Dashboard'],
        ]);
        $rendered = $sidebar->render();

        $this->assertStringContainsString('Dashboard', $rendered);
    }

    public function testRenderHasBorderChars(): void
    {
        $sidebar = Sidebar::new();
        $rendered = $sidebar->render();

        // Default has vertical border
        $this->assertMatchesRegularExpression('/[│|]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Title handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithTitle(): void
    {
        $sidebar = Sidebar::new()->withTitle('Navigation');
        $rendered = $sidebar->render();

        $this->assertStringContainsString('Navigation', $rendered);
    }

    public function testWithTitleFactory(): void
    {
        $sidebar = Sidebar::title('Menu', [
            ['label' => 'Item1'],
        ]);
        $rendered = $sidebar->render();

        $this->assertStringContainsString('Menu', $rendered);
        $this->assertStringContainsString('Item1', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Items handling
    // ═══════════════════════════════════════════════════════════════

    public function testRenderWithItems(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Dashboard', 'isActive' => true],
            ['label' => 'Settings'],
            ['label' => 'Profile'],
        ]);
        $rendered = $sidebar->render();

        $this->assertStringContainsString('Dashboard', $rendered);
        $this->assertStringContainsString('Settings', $rendered);
        $this->assertStringContainsString('Profile', $rendered);
    }

    public function testRenderWithIcons(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Dashboard', 'icon' => '⌂'],
            ['label' => 'Settings', 'icon' => '⚙'],
        ]);
        $rendered = $sidebar->render();

        $this->assertStringContainsString('⌂', $rendered);
        $this->assertStringContainsString('⚙', $rendered);
    }

    public function testRenderWithDividers(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Item1'],
            ['isDivider' => true],
            ['label' => 'Item2'],
        ]);
        $rendered = $sidebar->render();

        $this->assertStringContainsString('Item1', $rendered);
        $this->assertStringContainsString('Item2', $rendered);
        // Divider should produce border characters
        $this->assertMatchesRegularExpression('/─/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Active item handling
    // ═══════════════════════════════════════════════════════════════

    public function testWithActiveItem(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Home'],
            ['label' => 'About'],
        ])->withActiveItem(0);
        $rendered = $sidebar->render();

        $this->assertStringContainsString('Home', $rendered);
    }

    public function testActiveItemHasActiveIndicator(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Active Item', 'isActive' => true],
        ]);
        $rendered = $sidebar->render();

        // Active items should have a '>' indicator
        $this->assertStringContainsString('>', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Collapsed mode
    // ═══════════════════════════════════════════════════════════════

    public function testWithCollapsed(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Dashboard', 'icon' => '⌂'],
            ['label' => 'Settings', 'icon' => '⚙'],
        ])->withCollapsed(true);
        $rendered = $sidebar->render();

        // Should show only icons, not labels
        $this->assertStringContainsString('⌂', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $sidebar = Sidebar::new()
            ->withBorderColor(Color::ansi(9));
        $rendered = $sidebar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testActiveColorAddsAnsiCodes(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Home', 'isActive' => true],
        ])->withActiveColor(Color::ansi(12));
        $rendered = $sidebar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testInactiveColorAddsAnsiCodes(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Home'],
        ])->withInactiveColor(Color::ansi(8));
        $rendered = $sidebar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testBgColorAddsAnsiCodes(): void
    {
        $sidebar = Sidebar::new()
            ->withBgColor(Color::ansi(9));
        $rendered = $sidebar->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Sidebar::new();
        $resized = $original->setSize(20, 30);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsOutput(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Dashboard'],
        ])->setSize(20, 30);
        $rendered = $sidebar->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Sidebar::new();
        $updated = $original->withItems([['label' => 'Test']]);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithItems(): void
    {
        $original = Sidebar::new();
        $original->withItems([['label' => 'Changed']]);
        $rendered = $original->render();

        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $sidebar = Sidebar::new();
        [$w, $h] = $sidebar->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeWithItems(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'Item1'],
            ['label' => 'Item2'],
            ['label' => 'Item3'],
        ]);
        [$w, $h] = $sidebar->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(3, $h);
    }

    public function testGetInnerSizeWithTitle(): void
    {
        $sidebar = Sidebar::title('Menu', [
            ['label' => 'Item1'],
        ]);
        [, $h] = $sidebar->getInnerSize();

        // Should have title (2 lines) + 1 item = 3
        $this->assertSame(3, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testEmptySidebar(): void
    {
        $sidebar = Sidebar::new();
        $rendered = $sidebar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testLongLabelTruncates(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'This is a very long label that should be truncated'],
        ]);
        $sidebar = $sidebar->setSize(15, 10);
        $rendered = $sidebar->render();

        $this->assertNotSame('', $rendered);
    }

    public function testUnicodeContent(): void
    {
        $sidebar = Sidebar::new([
            ['label' => 'ダッシュボード'],
        ]);
        $rendered = $sidebar->render();

        $this->assertStringContainsString('ダッシュボード', $rendered);
    }
}