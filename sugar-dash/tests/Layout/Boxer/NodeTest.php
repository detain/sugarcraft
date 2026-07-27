<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Boxer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Boxer\Node;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;

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
        $this->assertTrue($withBorder->hasNoBorder());
    }

    public function testWithBorderTrueToFalse(): void
    {
        $node = Node::horizontal(Node::leaf('0'));
        $noBorder = $node->withBorder(true);
        $withBorder = $noBorder->withBorder(false);

        $this->assertFalse($withBorder->hasNoBorder());
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

        // The node is returned with width set via setSize (no clamping in setSize)
        $this->assertSame(-1, $updated->getWidth());
    }

    public function testImplementsItemInterface(): void
    {
        $node = Node::leaf('0');

        $this->assertInstanceOf(Item::class, $node);
    }
}
