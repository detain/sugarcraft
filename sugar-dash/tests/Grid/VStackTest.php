<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\VStack;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Text;
use SugarCraft\Dash\Grid\Badge;
use SugarCraft\Dash\Grid\HAlign;
use PHPUnit\Framework\TestCase;

final class VStackTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testVStackImplementsSizer(): void
    {
        $stack = VStack::new();
        $this->assertInstanceOf(Sizer::class, $stack);
    }

    public function testVStackImplementsItem(): void
    {
        $stack = VStack::new();
        $this->assertInstanceOf(Item::class, $stack);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyStack(): void
    {
        $stack = VStack::new();
        $rendered = $stack->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderSingleItem(): void
    {
        $stack = VStack::new(new Text('Hello'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderMultipleItemsVertically(): void
    {
        $stack = VStack::new(
            new Text('First'),
            new Text('Second'),
            new Text('Third')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
        $this->assertStringContainsString('Third', $rendered);

        // Items should be on separate lines
        $lines = explode("\n", $rendered);
        $this->assertGreaterThanOrEqual(3, count($lines));
    }

    // ═══════════════════════════════════════════════════════════════
    // Spacing
    // ═══════════════════════════════════════════════════════════════

    public function testSpacedFactory(): void
    {
        $stack = VStack::spaced(2,
            new Text('First'),
            new Text('Second')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Second', $rendered);
    }

    public function testWithSpacingReturnsNewInstance(): void
    {
        $original = VStack::new(new Text('A'));
        $updated = $original->withSpacing(3);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testCenteredFactory(): void
    {
        $stack = VStack::centered(new Text('Centered'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Centered', $rendered);
    }

    public function testRightFactory(): void
    {
        $stack = VStack::right(new Text('Right'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Right', $rendered);
    }

    public function testWithAlignmentReturnsNewInstance(): void
    {
        $original = VStack::new(new Text('A'));
        $updated = $original->withAlignment(HAlign::Right);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = VStack::new(new Text('Test'));
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = VStack::new(new Text('A'));
        $updated = $original->withItems([new Text('B')]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithAppendedAddsItem(): void
    {
        $original = VStack::new(new Text('A'));
        $updated = $original->withAppended(new Text('B'));
        $rendered = $updated->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testWithPrependedAddsItem(): void
    {
        $original = VStack::new(new Text('B'));
        $updated = $original->withPrepended(new Text('A'));
        $rendered = $updated->render();

        // A should come before B
        $posA = strpos($rendered, 'A');
        $posB = strpos($rendered, 'B');
        $this->assertNotFalse($posA);
        $this->assertNotFalse($posB);
        $this->assertLessThan($posB, $posA);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeEmptyStack(): void
    {
        $stack = VStack::new();
        [$w, $h] = $stack->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeSingleItem(): void
    {
        $stack = VStack::new(new Text('Hello'));
        [$w, $h] = $stack->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeMultipleItems(): void
    {
        $stack = VStack::new(new Text('A'), new Text('B'));
        [, $h] = $stack->getInnerSize();

        $this->assertGreaterThan(1, $h); // Combined height
    }

    public function testGetInnerSizeWithSpacing(): void
    {
        $stackNoSpacing = VStack::new(new Text('A'), new Text('B'));
        $stackWithSpacing = VStack::new(new Text('A'), new Text('B'))->withSpacing(2);

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
        for ($i = 0; $i < 5; $i++) {
            $items[] = new Text("Line $i");
        }
        $stack = VStack::new(...$items);
        $rendered = $stack->render();

        $this->assertStringContainsString('Line 0', $rendered);
        $this->assertStringContainsString('Line 4', $rendered);
    }
}
