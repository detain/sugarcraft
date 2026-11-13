<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Layout\Boxer\Node;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;

final class NodeTest extends TestCase
{
    public function testLeafFactory(): void
    {
        $node = Node::leaf('0');

        $this->assertTrue($node->isLeaf());
        $this->assertSame('0', $node->getAddress());
    }

    public function testLeafHasNoChildren(): void
    {
        $node = Node::leaf('0');

        $this->assertEmpty($node->getChildren());
    }

    public function testHorizontalFactory(): void
    {
        $node = Node::horizontal();

        $this->assertFalse($node->isLeaf());
        $this->assertFalse($node->isVerticalStacked());
        $this->assertEmpty($node->getChildren());
    }

    public function testHorizontalWithChildren(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $node = Node::horizontal($child1, $child2);

        $this->assertCount(2, $node->getChildren());
        $this->assertFalse($node->isVerticalStacked());
    }

    public function testVerticalFactory(): void
    {
        $node = Node::vertical();

        $this->assertFalse($node->isLeaf());
        $this->assertTrue($node->isVerticalStacked());
    }

    public function testVerticalWithChildren(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $node = Node::vertical($child1, $child2);

        $this->assertCount(2, $node->getChildren());
        $this->assertTrue($node->isVerticalStacked());
    }

    public function testSetSizeReturnsNewInstance(): void
    {
        $node = Node::leaf('0');
        $sized = $node->setSize(80, 24);

        $this->assertNotSame($node, $sized);
        $this->assertSame(80, $sized->getWidth());
        $this->assertSame(24, $sized->getHeight());
    }

    public function testSetSizeSameDimensionsReturnsSame(): void
    {
        $node = Node::leaf('0')->setSize(80, 24);
        $result = $node->setSize(80, 24);

        $this->assertSame($node, $result);
    }

    public function testWithChildrenReturnsNewInstance(): void
    {
        $node = Node::leaf('0');
        $newChildren = [Node::leaf('1'), Node::leaf('2')];
        $withChildren = $node->withChildren($newChildren);

        $this->assertNotSame($node, $withChildren);
        $this->assertCount(2, $withChildren->getChildren());
    }

    public function testWithVerticalStacked(): void
    {
        $node = Node::horizontal();
        $vertical = $node->withVerticalStacked(true);

        $this->assertNotSame($node, $vertical);
        $this->assertTrue($vertical->isVerticalStacked());
    }

    public function testWithBorder(): void
    {
        $node = Node::horizontal();
        $withBorder = $node->withBorder(true);

        $this->assertNotSame($node, $withBorder);
        // withBorder(true) ENABLES the border (finding sugar-dashboard-123:
        // the old parameter carried the internal $noBorder polarity, so the
        // API did the opposite of its name and these pins froze the inversion).
        $this->assertFalse($withBorder->hasNoBorder());
    }

    public function testWithBorderTrueToFalse(): void
    {
        $node = Node::horizontal(Node::leaf('0'));
        $bordered = $node->withBorder(true);
        $unbordered = $bordered->withBorder(false);

        $this->assertTrue($unbordered->hasNoBorder());
    }

    public function testWithBorderPolarityReachesTheRenderedSeparator(): void
    {
        $item = new class implements Item {
            public function render(): string
            {
                return 'ab';
            }
        };
        $tree = Node::vertical(Node::leaf('0'), Node::leaf('1'))->withBorder(true);
        $sized = $tree->updateSizeRecursive(4, 5);
        $bordered = implode("\n", (array) $sized->renderTree(['0' => $item, '1' => $item], 4, 5));
        $this->assertStringContainsString(Node::VERTICAL_SEPARATOR, $bordered);

        $bare = $tree->withBorder(false);
        $bareSized = $bare->updateSizeRecursive(4, 5);
        $plain = implode("\n", (array) $bareSized->renderTree(['0' => $item, '1' => $item], 4, 5));
        $this->assertStringNotContainsString(Node::VERTICAL_SEPARATOR, $plain);
        // Content survives on both sides of the polarity pin.
        $this->assertStringContainsString('ab', $plain);
    }

    public function testHasNoBorderDefaultHorizontal(): void
    {
        $node = Node::horizontal();

        $this->assertTrue($node->hasNoBorder());
    }

    public function testHasNoBorderDefaultVertical(): void
    {
        $node = Node::vertical();

        $this->assertTrue($node->hasNoBorder());
    }

    public function testRenderReturnsEmptyString(): void
    {
        $node = Node::leaf('0');

        $this->assertSame('', $node->render());
    }

    public function testUpdateSizeRecursiveLeaf(): void
    {
        $node = Node::leaf('0')->setSize(0, 0);
        $updated = $node->updateSizeRecursive(80, 24);

        $this->assertSame(80, $updated->getWidth());
        $this->assertSame(24, $updated->getHeight());
    }

    public function testUpdateSizeRecursiveHorizontal(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $node = Node::horizontal($child1, $child2);
        $updated = $node->updateSizeRecursive(80, 24);

        $this->assertSame(80, $updated->getWidth());
        $this->assertSame(24, $updated->getHeight());
        $this->assertCount(2, $updated->getChildren());
    }

    public function testUpdateSizeRecursiveVertical(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $node = Node::vertical($child1, $child2);
        $updated = $node->updateSizeRecursive(80, 24);

        $this->assertSame(80, $updated->getWidth());
        $this->assertSame(24, $updated->getHeight());
        $this->assertCount(2, $updated->getChildren());
    }

    public function testUpdateSizeRecursiveNegativeReturnsSameNode(): void
    {
        $node = Node::leaf('0')->setSize(0, 0);
        $updated = $node->updateSizeRecursive(-1, 24);

        // A non-positive budget clamps the node own size and collapses the whole
        // subtree (finding C-11): stale child dimensions from a prior pass used to
        // survive the early return and desync later resizes.
        $this->assertSame(0, $updated->getWidth());
    }

    public function testImplementsItemInterface(): void
    {
        $node = Node::leaf('0');

        $this->assertInstanceOf(Item::class, $node);
    }

    public function testNonPositiveBudgetCollapsesWholeStaleSubtree(): void
    {
        $root = Node::horizontal(Node::leaf('0'), Node::leaf('1'))->setSize(0, 0);
        $seeded = $root->updateSizeRecursive(40, 10);

        $children = (new \ReflectionProperty(Node::class, 'children'))->getValue($seeded);
        foreach ($children as $child) {
            $this->assertGreaterThan(0, $child->getWidth());
        }

        $collapsed = $seeded->updateSizeRecursive(0, 10);
        $children = (new \ReflectionProperty(Node::class, 'children'))->getValue($collapsed);
        foreach ($children as $child) {
            $this->assertSame(0, $child->getWidth());
        }
        $this->assertSame(0, $collapsed->getWidth());
    }

}
