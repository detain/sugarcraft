<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Tree\Node;

/**
 * Tests for {@see Node} — immutable tree node value object.
 */
final class NodeTest extends TestCase
{
    public function testLeafFactory(): void
    {
        $leaf = Node::leaf('readme', 'README.md');
        $this->assertTrue($leaf->isLeaf());
        $this->assertSame('readme', $leaf->label);
        $this->assertSame('README.md', $leaf->value);
        $this->assertSame([], $leaf->children);
        $this->assertTrue($leaf->expanded);
    }

    public function testLeafFactoryWithDefaultValue(): void
    {
        $leaf = Node::leaf('notes');
        $this->assertSame('notes', $leaf->label);
        $this->assertNull($leaf->value);
    }

    public function testBranchFactory(): void
    {
        $child1 = Node::leaf('a.txt', 'content-a');
        $child2 = Node::leaf('b.txt', 'content-b');
        $branch = Node::branch('files', $child1, $child2);

        $this->assertFalse($branch->isLeaf());
        $this->assertSame('files', $branch->label);
        $this->assertNull($branch->value);
        $this->assertCount(2, $branch->children);
        $this->assertSame($child1, $branch->children[0]);
        $this->assertSame($child2, $branch->children[1]);
        $this->assertTrue($branch->expanded);
    }

    public function testBranchWithNoChildrenIsLeaf(): void
    {
        $branch = Node::branch('empty');
        $this->assertTrue($branch->isLeaf());
        $this->assertSame([], $branch->children);
    }

    public function testConstructorDefaults(): void
    {
        $node = new Node('label');
        $this->assertSame('label', $node->label);
        $this->assertNull($node->value);
        $this->assertSame([], $node->children);
        $this->assertTrue($node->expanded);
    }

    public function testConstructorWithAllFields(): void
    {
        $child = Node::leaf('x');
        $node = new Node('parent', 'val', [$child], false);
        $this->assertSame('parent', $node->label);
        $this->assertSame('val', $node->value);
        $this->assertCount(1, $node->children);
        $this->assertFalse($node->expanded);
    }

    public function testWithExpandedTrue(): void
    {
        $leaf = Node::leaf('a');
        $this->assertTrue($leaf->expanded);

        $expanded = $leaf->withExpanded(true);
        $this->assertTrue($expanded->expanded);
        $this->assertEquals($leaf, $expanded, 'leaf expanded=true should be equivalent to original');
    }

    public function testWithExpandedFalseOnBranch(): void
    {
        $branch = Node::branch('src', Node::leaf('a.php'));
        $this->assertTrue($branch->expanded);

        $collapsed = $branch->withExpanded(false);
        $this->assertFalse($collapsed->expanded);
        // Children unchanged
        $this->assertCount(1, $collapsed->children);
        // Original unchanged
        $this->assertTrue($branch->expanded);
    }

    public function testWithExpandedReturnsNewInstance(): void
    {
        $branch = Node::branch('src', Node::leaf('a'));
        $collapsed = $branch->withExpanded(false);
        $this->assertNotSame($branch, $collapsed);
    }

    public function testWithChildrenReplacesChildren(): void
    {
        $parent = Node::branch('root', Node::leaf('old'));
        $newKids = [
            Node::leaf('new1'),
            Node::leaf('new2'),
        ];
        $replaced = $parent->withChildren($newKids);

        $this->assertCount(2, $replaced->children);
        $this->assertSame('new1', $replaced->children[0]->label);
        $this->assertSame('new2', $replaced->children[1]->label);
        // Label unchanged
        $this->assertSame('root', $replaced->label);
        // Expanded flag unchanged
        $this->assertTrue($replaced->expanded);
        // Original unchanged
        $this->assertCount(1, $parent->children);
    }

    public function testWithChildrenReturnsNewInstance(): void
    {
        $parent = Node::branch('root');
        $new = $parent->withChildren([]);
        $this->assertNotSame($parent, $new);
    }

    public function testIsLeafWhenNoChildren(): void
    {
        $leaf = Node::leaf('x');
        $this->assertTrue($leaf->isLeaf());

        $branch = Node::branch('x');
        $this->assertTrue($branch->isLeaf(), 'branch with no children is still a leaf');
    }

    public function testIsLeafFalseWhenHasChildren(): void
    {
        $branch = Node::branch('x', Node::leaf('y'));
        $this->assertFalse($branch->isLeaf());
    }

    public function testImmutabilityOfStoredNodes(): void
    {
        $child = Node::leaf('original');
        $parent = Node::branch('parent', $child);

        // Mutating child does not affect stored reference (Node is immutable too)
        $this->assertSame('original', $parent->children[0]->label);
    }

    public function testChildrenArrayIsReindexed(): void
    {
        $children = [
            Node::leaf('first'),
            Node::leaf('second'),
            Node::leaf('third'),
        ];
        $branch = Node::branch('items', ...$children);
        $this->assertSame([0, 1, 2], array_keys($branch->children));
    }

    public function testSubtreeInValueSlotThrowsNamingBranch(): void
    {
        // W-44/069: the 2-arg new Node(label, children) footgun renders
        // flat and silent — now a typed construction error.
        $this->expectException(\InvalidArgumentException::class);
        new Node('x', [Node::leaf('y')]);
    }

    public function testTraversableValueSlotThrowsToo(): void
    {
        $gen = (static function (): \Generator {
            yield Node::leaf('y');
        })();
        $this->expectException(\InvalidArgumentException::class);
        new Node('x', $gen);
    }

    public function testPlainPayloadsStillStore(): void
    {
        // The guard hunts node-lists, not payloads: scalars, maps, and
        // non-list arrays keep working (selectedValue() contract).
        $s = Node::leaf('a', 'v');
        $this->assertSame('v', $s->value);
        $m = new Node('m', ['path' => '/tmp', 'size' => 2]);
        $this->assertSame(['path' => '/tmp', 'size' => 2], $m->value);
        $nums = new Node('n', [1, 2, 3]);
        $this->assertSame([1, 2, 3], $nums->value);
    }

    public function testFactoriesConstructByteUnchanged(): void
    {
        // leaf()/branch() were never the footgun; their shapes must not
        // move (construction-path regression pin).
        $leaf = Node::leaf('l', 'p');
        $this->assertSame('l', $leaf->label);
        $this->assertSame('p', $leaf->value);
        $this->assertSame([], $leaf->children);
        $this->assertTrue($leaf->expanded);
        $branch = Node::branch('b', Node::leaf('c'));
        $this->assertNull($branch->value);
        $this->assertCount(1, $branch->children);
    }
}
