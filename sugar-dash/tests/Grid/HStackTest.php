<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\HStack;
use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Dash\Grid\Text;
use SugarCraft\Dash\Grid\Badge;
use SugarCraft\Dash\Grid\Spacer;
use SugarCraft\Dash\Grid\HAlign;
use PHPUnit\Framework\TestCase;

final class HStackTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testHStackImplementsSizer(): void
    {
        $stack = HStack::new();
        $this->assertInstanceOf(Sizer::class, $stack);
    }

    public function testHStackImplementsItem(): void
    {
        $stack = HStack::new();
        $this->assertInstanceOf(Item::class, $stack);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderEmptyStack(): void
    {
        $stack = HStack::new();
        $rendered = $stack->render();

        $this->assertSame('', $rendered);
    }

    public function testRenderSingleItem(): void
    {
        $stack = HStack::new(new Text('Hello'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Hello', $rendered);
    }

    public function testRenderMultipleItemsSideBySide(): void
    {
        $stack = HStack::new(
            new Text('A'),
            new Text('B'),
            new Text('C')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringContainsString('C', $rendered);

        // Items should be on same line (no newlines between)
        $lines = explode("\n", $rendered);
        $this->assertCount(1, $lines);
    }

    // ═══════════════════════════════════════════════════════════════
    // Spacing
    // ═══════════════════════════════════════════════════════════════

    public function testSpacedFactory(): void
    {
        $stack = HStack::spaced(3,
            new Text('A'),
            new Text('B')
        );
        $rendered = $stack->render();

        // A and B should be separated
        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testZeroSpacingItemsTouching(): void
    {
        $stack = HStack::new(new Text('A'), new Text('B'));
        $rendered = $stack->render();

        // A and B should be adjacent without space
        $this->assertStringNotContainsString(' A', $rendered);
        $this->assertStringNotContainsString('B ', $rendered);
    }

    public function testWithSpacingAddsSpace(): void
    {
        $stack = HStack::new(new Text('A'), new Text('B'))->withSpacing(3);
        $rendered = $stack->render();

        // Should have spaces between A and B
        $this->assertStringContainsString('A   B', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Alignment
    // ═══════════════════════════════════════════════════════════════

    public function testCenteredFactory(): void
    {
        $stack = HStack::centered(new Text('Center'));
        $rendered = $stack->render();

        $this->assertStringContainsString('Center', $rendered);
    }

    public function testWithAlignmentReturnsNewInstance(): void
    {
        $original = HStack::new(new Text('A'));
        $updated = $original->withAlignment(HAlign::Right);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = HStack::new(new Text('Test'));
        $resized = $original->setSize(50, 10);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithItemsReturnsNewInstance(): void
    {
        $original = HStack::new(new Text('A'));
        $updated = $original->withItems([new Text('B')]);

        $this->assertNotSame($original, $updated);
    }

    public function testWithAppendedAddsItem(): void
    {
        $original = HStack::new(new Text('A'));
        $updated = $original->withAppended(new Text('B'));
        $rendered = $updated->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
    }

    public function testWithPrependedAddsItem(): void
    {
        $original = HStack::new(new Text('B'));
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
        $original = HStack::new(new Text('A'));
        $updated = $original->withSpacing(3);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeEmptyStack(): void
    {
        $stack = HStack::new();
        [$w, $h] = $stack->getInnerSize();

        $this->assertSame(0, $w);
        $this->assertSame(0, $h);
    }

    public function testGetInnerSizeSingleItem(): void
    {
        $stack = HStack::new(new Text('Hello'));
        [$w, $h] = $stack->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeMultipleItems(): void
    {
        $stack = HStack::new(new Text('A'), new Text('B'));
        [$w, $h] = $stack->getInnerSize();

        $this->assertGreaterThan(1, $w); // Combined width
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithSpacing(): void
    {
        $stackNoSpacing = HStack::new(new Text('A'), new Text('B'));
        $stackWithSpacing = HStack::new(new Text('A'), new Text('B'))->withSpacing(3);

        [$wNoSpacing, ] = $stackNoSpacing->getInnerSize();
        [$wWithSpacing, ] = $stackWithSpacing->getInnerSize();

        $this->assertGreaterThan($wNoSpacing, $wWithSpacing);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testManyItems(): void
    {
        $items = [];
        for ($i = 0; $i < 5; $i++) {
            $items[] = new Text("($i)");
        }
        $stack = HStack::new(...$items);
        $rendered = $stack->render();

        $this->assertStringContainsString('(0)', $rendered);
        $this->assertStringContainsString('(4)', $rendered);
    }

    public function testMixedContent(): void
    {
        $stack = HStack::new(
            Badge::new('New'),
            new Text('Label'),
            Spacer::new(1, 1)->withFillChar('-')
        );
        $rendered = $stack->render();

        $this->assertStringContainsString('New', $rendered);
        $this->assertStringContainsString('Label', $rendered);
    }
}
