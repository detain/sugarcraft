<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Stack;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Text;
use SugarCraft\Dash\Grid\Badge;
use PHPUnit\Framework\TestCase;

final class StackTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testStackImplementsSizer(): void
    {
        $stack = Stack::new();
        $this->assertInstanceOf(Sizer::class, $stack);
    }

    public function testStackImplementsItem(): void
    {
        $stack = Stack::new();
        $this->assertInstanceOf(Item::class, $stack);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyStack(): void
    {
        $stack = Stack::new();
        $rendered = $stack->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderSingleItem(): void
    {
        $stack = Stack::new(new Text('Hello'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderMultipleItems(): void
    {
        $stack = Stack::new(
            new Text('First'),
            new Text('Second'),
            new Text('Third')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
        $this->assertStringContainsString('Third', $rendered);
    }

    public function testRenderItemsOnSeparateLines(): void
    {
        $stack = Stack::new(
            new Text('First'),
            new Text('Second')
        );
        $rendered = $stack->render();

        $lines = explode("\n", $rendered);
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Spacing
    // ═══════════════════════════════════════════════════════════════

    public function testSpacedFactory(): void
    {
        $stack = Stack::spaced(2,
            new Text('First'),
            new Text('Second')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
    }

    public function testZeroSpacingNoExtraNewlines(): void
    {
        $stack = Stack::new(new Text('A'), new Text('B'));
        $rendered = $stack->render();

        // Should have minimal newlines
        $this->assertSame(1, substr_count($rendered, "\n"));
    }

    public function testWithSpacingAddsNewlines(): void
    {
        $stack = Stack::new(new Text('A'), new Text('B'))->withSpacing(2);
        $rendered = $stack->render();

        // Should have extra newlines between items
        $this->assertGreaterThan(1, substr_count($rendered, "\n"));
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Stack::new(new Text('Test'));
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $stack = Stack::new(Badge::new('Hi'))->setSize(40, 1);
        $rendered = $stack->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = Stack::new(new Text('A'));
        $updated = $original->withItems([new Text('B')]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithAppendedAddsItem(): void
    {
        $original = Stack::new(new Text('A'));
        $updated = $original->withAppended(new Text('B'));
        $rendered = $updated->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testWithPrependedAddsItem(): void
    {
        $original = Stack::new(new Text('B'));
        $updated = $original->withPrepended(new Text('A'));
        $rendered = $updated->render();

        // A should come before B
        $posA = strpos($rendered, 'A');
        $posB = strpos($rendered, 'B');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA);
    }

    public function testWithSpacingReturnsNewInstance(): void
    {
        $original = Stack::new(new Text('A'));
        $updated = $original->withSpacing(3);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithItems(): void
    {
        $original = Stack::new(new Text('Original'));
        $original->withItems([new Text('Changed')]);
        $rendered = $original->render();

        $this->assertStringContainsString('Original', $rendered);
        $this->assertStringNotContainsString('Changed', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeEmptyStack(): void
    {
        $stack = Stack::new();
        [$w, $h] = $stack->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeSingleItem(): void
    {
        $stack = Stack::new(new Text('Hello'));
        [$w, $h] = $stack->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeMultipleItems(): void
    {
        $stack = Stack::new(new Text('A'), new Text('B'));
        [$w, $h] = $stack->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithSpacing(): void
    {
        $stackNoSpacing = Stack::new(new Text('A'), new Text('B'));
        $stackWithSpacing = Stack::new(new Text('A'), new Text('B'))->withSpacing(2);

        [, $hNoSpacing] = $stackNoSpacing->getInnerSize();
        [, $hWithSpacing] = $stackWithSpacing->getInnerSize();

        $this->assertGreaterThan($hNoSpacing, $hWithSpacing);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testManyItems(): void
    {
        $items = [];
        for ($i = 0; $i < 10; $i++) {
            $items[] = new Text("Item $i");
        }
        $stack = Stack::new(...$items);
        $rendered = $stack->render();

        $this->assertStringContainsString('Item 0', $rendered);
        $this->assertStringContainsString('Item 9', $rendered);
    }

    public function testNestedStacks(): void
    {
        $inner = Stack::new(new Text('Inner'));
        $outer = Stack::new($inner, new Text('Outer'));
        $rendered = $outer->render();

        $this->assertStringContainsString('Inner', $rendered);
        $this->assertStringContainsString('Outer', $rendered);
    }
}
