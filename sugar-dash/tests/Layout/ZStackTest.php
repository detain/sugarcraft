<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use SugarCraft\Dash\Layout\ZStack;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Components\Card\Text;
use SugarCraft\Dash\Components\Card\Badge;
use SugarCraft\Dash\Layout\Spacer;
use SugarCraft\Dash\Layout\HAlign;
use SugarCraft\Dash\Layout\VAlign;
use PHPUnit\Framework\TestCase;

final class ZStackTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testZStackImplementsSizer(): void
    {
        $stack = ZStack::new();
        $this->assertInstanceOf(Sizer::class, $stack);
    }

    public function testZStackImplementsItem(): void
    {
        $stack = ZStack::new();
        $this->assertInstanceOf(Item::class, $stack);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyStack(): void
    {
        $stack = ZStack::new();
        $rendered = $stack->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderSingleItem(): void
    {
        $stack = ZStack::new(new Text('Hello'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderShowsTopItem(): void
    {
        $stack = ZStack::new(
            new Text('Bottom'),
            new Text('Top')
        );
        $rendered = $stack->render();

        // Only the top item should be visible
        $this->assertStringContainsString('Top', $rendered);
        $this->assertStringNotContainsString('Bottom', $rendered);
    }

    public function testWithTopAddsItemOnTop(): void
    {
        $stack = ZStack::new(new Text('Base'))
            ->withTop(new Text('Overlay'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Overlay', $rendered);
        $this->assertStringNotContainsString('Base', $rendered);
    }

    public function testWithBottomAddsItemAtBottom(): void
    {
        $stack = ZStack::new(new Text('Top'))
            ->withBottom(new Text('Bottom'));
        $rendered = $stack->render();

        // Should still show "Top" since it's on top
        $this->assertStringContainsString('Top', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment presets
    // ═══════════════════════════════════════════════════════════════

    public function testLeftFactory(): void
    {
        $stack = ZStack::left(new Text('Left'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Left', $rendered);
    }

    public function testRightFactory(): void
    {
        $stack = ZStack::right(new Text('Right'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Right', $rendered);
    }

    public function testTopFactory(): void
    {
        $stack = ZStack::top(new Text('TopAligned'));
        $rendered = $stack->render();

        $this->assertStringContainsString('TopAligned', $rendered);
    }

    public function testBottomFactory(): void
    {
        $stack = ZStack::bottom(new Text('BottomAligned'));
        $rendered = $stack->render();

        $this->assertStringContainsString('BottomAligned', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = ZStack::new(new Text('Test'));
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testSizeAllocationAffectsAllItems(): void
    {
        $stack = ZStack::new(
            Badge::new('A'),
            Badge::new('B')
        )->setSize(40, 1);
        $rendered = $stack->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = ZStack::new(new Text('A'));
        $updated = $original->withItems([new Text('B')]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithAlignmentReturnsNewInstance(): void
    {
        $original = ZStack::new(new Text('A'));
        $updated = $original->withAlignment(HAlign::Right);

        $this->assertNotSame($original, $updated);
    }

    public function testWithVAlignmentReturnsNewInstance(): void
    {
        $original = ZStack::new(new Text('A'));
        $updated = $original->withVAlignment(VAlign::Bottom);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeEmptyStack(): void
    {
        $stack = ZStack::new();
        [$w, $h] = $stack->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeSingleItem(): void
    {
        $stack = ZStack::new(new Text('Hello'));
        [$w, $h] = $stack->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeMultipleItems(): void
    {
        $stack = ZStack::new(
            new Text('Small'),
            new Text('Medium Text')
        );
        [$w, $h] = $stack->getInnerSize();

        // Should be the max dimensions
        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeReturnsMaxDimensions(): void
    {
        $smallBadge = Badge::new('X');
        $largeBadge = Badge::new('Longer Label');

        $stack = ZStack::new($smallBadge, $largeBadge);
        [$w, ] = $stack->getInnerSize();

        [$smallW, ] = $smallBadge->getInnerSize();
        [$largeW, ] = $largeBadge->getInnerSize();

        // Should be at least as large as the largest item
        $this->assertGreaterThanOrEqual($largeW, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testManyLayers(): void
    {
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = new Text("Layer $i");
        }
        $stack = ZStack::new(...$items);
        $rendered = $stack->render();

        // Only the top layer should be visible
        $this->assertStringContainsString('Layer 4', $rendered);
        $this->assertStringNotContainsString('Layer 0', $rendered);
    }

    public function testTransparentItemsStillRender(): void
    {
        $stack = ZStack::new(
            Spacer::new(5, 1)->withFillChar(' '),
            new Text('Visible')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('Visible', $rendered);
    }
}
