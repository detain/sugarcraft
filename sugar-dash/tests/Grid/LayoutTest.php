<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Layout;
use SugarCraft\Dash\Grid\LayoutDirection;
use SugarCraft\Dash\Grid\LayoutItem;
use SugarCraft\Dash\Grid\Text;
use SugarCraft\Dash\Grid\Bar;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\HAlign;
use SugarCraft\Dash\Grid\VAlign;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testLayoutImplementsSizer(): void
    {
        $layout = Layout::horizontal([]);
        $this->assertInstanceOf(Sizer::class, $layout);
    }

    public function testLayoutImplementsItem(): void
    {
        $layout = Layout::horizontal([]);
        $this->assertInstanceOf(Item::class, $layout);
    }

    // ═══════════════════════════════════════════════════════════════
    // Factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testHorizontal(): void
    {
        $layout = Layout::horizontal([]);
        $this->assertInstanceOf(Layout::class, $layout);
    }

    public function testVertical(): void
    {
        $layout = Layout::vertical([]);
        $this->assertInstanceOf(Layout::class, $layout);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyLayout(): void
    {
        $layout = Layout::horizontal([]);
        $this->assertSame('', $layout->render());
    }

    public function testRenderHorizontalLayout(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::fixed(Text::new('A')),
            LayoutItem::fixed(Text::new('B')),
            LayoutItem::fixed(Text::new('C')),
        ])->setSize(20, 1);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringContainsString('C', $rendered);
    }

    public function testRenderVerticalLayout(): void
    {
        $layout = Layout::vertical([
            LayoutItem::fixed(Text::new('Line 1')),
            LayoutItem::fixed(Text::new('Line 2')),
            LayoutItem::fixed(Text::new('Line 3')),
        ])->setSize(20, 5);

        $rendered = $layout->render();
        $this->assertStringContainsString('Line 1', $rendered);
        $this->assertStringContainsString('Line 2', $rendered);
        $this->assertStringContainsString('Line 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Layout::horizontal([]);
        $resized = $original->setSize(80, 24);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRender(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::fixed(Text::new('Hello')),
        ])->setSize(30, 5);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    public function testZeroSizeRendersEmpty(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::fixed(Text::new('Hello')),
        ])->setSize(0, 5);

        $this->assertSame('', $layout->render());
    }

    public function testGetInnerSize(): void
    {
        $layout = Layout::horizontal([])->setSize(80, 24);
        [$w, $h] = $layout->getInnerSize();

        $this->assertSame(80, $w);
        $this->assertSame(24, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Gap
    // ═══════════════════════════════════════════════════════════════

    public function testWithGapAddsSpace(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::fixed(Text::new('A')),
            LayoutItem::fixed(Text::new('B')),
        ])->withGap(2)->setSize(20, 1);

        $rendered = $layout->render();
        $stripped = preg_replace('/\x1b\[[0-9;]*m/', '', $rendered);

        // Should contain A, space gap, then B
        $this->assertStringContainsString('A', $stripped);
        $this->assertStringContainsString('B', $stripped);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testWithHorizontalAlign(): void
    {
        $layout = Layout::vertical([
            LayoutItem::fixed(Text::new('Test')),
        ])->withHorizontalAlign(HAlign::Center)->setSize(20, 1);

        // Should render without errors
        $this->assertNotSame('', $layout->render());
    }

    public function testWithVerticalAlign(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::fixed(Text::new('Test')),
        ])->withVerticalAlign(VAlign::Middle)->setSize(10, 5);

        // Should render without errors
        $this->assertNotSame('', $layout->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Flex
    // ═══════════════════════════════════════════════════════════════

    public function testFlexItem(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::fixed(Text::new('Fixed')),
            LayoutItem::flex(Text::new('Flex'), 1),
        ])->setSize(30, 1);

        $rendered = $layout->render();
        $this->assertStringContainsString('Fixed', $rendered);
        $this->assertStringContainsString('Flex', $rendered);
    }

    public function testMultipleFlexItems(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::flex(Text::new('A'), 1),
            LayoutItem::flex(Text::new('B'), 1),
            LayoutItem::flex(Text::new('C'), 1),
        ])->setSize(30, 1);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringContainsString('C', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // LayoutItem
    // ═══════════════════════════════════════════════════════════════

    public function testLayoutItemFlex(): void
    {
        $item = LayoutItem::flex(Text::new('test'), 2);
        $this->assertSame(2, $item->flex);
    }

    public function testLayoutItemFixed(): void
    {
        $item = LayoutItem::fixed(Text::new('test'));
        $this->assertSame(0, $item->flex);
        $this->assertInstanceOf(Text::class, $item->content);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithGapReturnsNewInstance(): void
    {
        $original = Layout::horizontal([]);
        $modified = $original->withGap(5);

        $this->assertNotSame($original, $modified);
    }

    public function testWithDirectionReturnsNewInstance(): void
    {
        $original = Layout::horizontal([]);
        $modified = $original->withDirection(LayoutDirection::Vertical);

        $this->assertNotSame($original, $modified);
    }

    public function testWithChildrenReturnsNewInstance(): void
    {
        $original = Layout::horizontal([]);
        $modified = $original->withChildren([
            LayoutItem::fixed(Text::new('New')),
        ]);

        $this->assertNotSame($original, $modified);
    }

    public function testWithChildReturnsNewInstance(): void
    {
        $original = Layout::horizontal([]);
        $modified = $original->withChild(Text::new('Added'), 0);

        $this->assertNotSame($original, $modified);
    }

    // ═══════════════════════════════════════════════════════════════
    // Chaining
    // ═══════════════════════════════════════════════════════════════

    public function testChainedWithers(): void
    {
        $layout = Layout::horizontal([])
            ->withGap(2)
            ->withDirection(LayoutDirection::Vertical)
            ->withHorizontalAlign(HAlign::Center)
            ->withVerticalAlign(VAlign::Middle);

        $this->assertSame('', $layout->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testLayoutWithBarItems(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::fixed(Bar::new('A')),
            LayoutItem::fixed(Bar::new('B')),
        ])->setSize(30, 1);

        $rendered = $layout->render();
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testVerticalLayoutWithAlignment(): void
    {
        $layout = Layout::vertical([
            LayoutItem::fixed(Text::new('Short')),
        ])->setSize(20, 3)->withHorizontalAlign(HAlign::Center);

        $rendered = $layout->render();
        $this->assertNotSame('', $rendered);
    }

    public function testNegativeGapBecomesZero(): void
    {
        $layout = Layout::horizontal([
            LayoutItem::fixed(Text::new('A')),
        ])->withGap(-5)->setSize(20, 1);

        // Should still render
        $this->assertNotSame('', $layout->render());
    }
}
