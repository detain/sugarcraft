<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Layout\Boxer\Boxer;
use SugarCraft\Dash\Layout\Boxer\Node;
use SugarCraft\Dash\Layout\Boxer\NotFoundError;
use SugarCraft\Dash\Layout\Boxer\SizeError;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;

final class BoxerTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Test Helpers
    // ═══════════════════════════════════════════════════════════════

    private function strItem(string $s): Item
    {
        return new class($s) implements Item {
            public function __construct(private readonly string $s) {}
            public function render(): string { return $this->s; }
        };
    }

    private function sizedItem(int $w = 0, int $h = 0): Item
    {
        return new class($w, $h) implements Item, Sizer {
            public int $capturedW = 0;
            public int $capturedH = 0;

            public function __construct(
                private int $w,
                private int $h,
            ) {}

            public function setSize(int $width, int $height): Sizer
            {
                $clone = clone $this;
                $clone->capturedW = $width;
                $clone->capturedH = $height;
                $clone->w = $width;
                $clone->h = $height;
                return $clone;
            }

            public function render(): string
            {
                return "Size:{$this->w}x{$this->h}";
            }
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testBoxerImplementsItem(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $this->assertInstanceOf(Item::class, $boxer);
    }

    public function testBoxerImplementsSizer(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $this->assertInstanceOf(Sizer::class, $boxer);
    }

    // ═══════════════════════════════════════════════════════════════
    // createLeaf / factory methods
    // ═══════════════════════════════════════════════════════════════

    public function testCreateLeafReturnsNodeWithAddress(): void
    {
        $boxer = Boxer::leaf('0.1.2', $this->strItem('content'));
        $node = $boxer->getNode('0.1.2');

        $this->assertNotNull($node);
        $this->assertSame('0.1.2', $node->getAddress());
        $this->assertTrue($node->isLeaf());
    }

    public function testCreateLeafWithEmptyAddressThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Boxer::leaf('', $this->strItem('test'));
    }

    public function testHorizontalFactoryCreatesHorizontalNode(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');

        $boxer = Boxer::horizontal($child1, $child2);

        $this->assertFalse($boxer->getRoot()->isLeaf());
        $this->assertFalse($boxer->getRoot()->isVerticalStacked());
        $this->assertCount(2, $boxer->getRoot()->getChildren());
    }

    public function testVerticalFactoryCreatesVerticalNode(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');

        $boxer = Boxer::vertical($child1, $child2);

        $this->assertFalse($boxer->getRoot()->isLeaf());
        $this->assertTrue($boxer->getRoot()->isVerticalStacked());
        $this->assertCount(2, $boxer->getRoot()->getChildren());
    }

    // ═══════════════════════════════════════════════════════════════
    // editLeaf
    // ═══════════════════════════════════════════════════════════════

    public function testEditLeafModifiesNode(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('original'));
        $modifiedBoxer = $boxer->editLeaf('0', fn($item) => $this->strItem('modified'));

        // Original unchanged
        $this->assertSame('original', $boxer->getItem('0')->render());

        // New instance has modified content
        $this->assertSame('modified', $modifiedBoxer->getItem('0')->render());
    }

    public function testEditLeafAutoSaves(): void
    {
        $sized = $this->sizedItem();
        $boxer = Boxer::leaf('0', $sized);

        // Set size to propagate
        $boxer = $boxer->setSize(20, 5);

        // Edit leaf
        $modifiedBoxer = $boxer->editLeaf('0', function ($item) {
            return $item->setSize(15, 3);
        });

        // Verify the modification persisted
        $this->assertSame(15, $modifiedBoxer->getItem('0')->capturedW ?? 0);
        $this->assertSame(3, $modifiedBoxer->getItem('0')->capturedH ?? 0);
    }

    public function testEditLeafThrowsNotFoundForUnknownAddress(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));

        $this->expectException(NotFoundError::class);
        $boxer->editLeaf('99', fn($item) => $item);
    }

    public function testEditLeafRethrowsErrorFromEditFunc(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));

        $this->expectException(\RuntimeException::class);
        $boxer->editLeaf('0', function ($item) {
            throw new \RuntimeException('edit failed');
        });
    }

    // ═══════════════════════════════════════════════════════════════
    // Size distribution
    // ═══════════════════════════════════════════════════════════════

    public function testEvenSplitHorizontal(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $child3 = Node::leaf('2');

        $boxer = Boxer::horizontal($child1, $child2, $child3);
        $boxer = $boxer->setSize(30, 10);

        // Each child should get 10 width (30 / 3)
        $this->assertSame(10, $boxer->getNode('0')->getWidth());
        $this->assertSame(10, $boxer->getNode('1')->getWidth());
        $this->assertSame(10, $boxer->getNode('2')->getWidth());

        // All should have full height
        $this->assertSame(10, $boxer->getNode('0')->getHeight());
        $this->assertSame(10, $boxer->getNode('1')->getHeight());
        $this->assertSame(10, $boxer->getNode('2')->getHeight());
    }

    public function testEvenSplitVertical(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $child3 = Node::leaf('2');

        $boxer = Boxer::vertical($child1, $child2, $child3);
        $boxer = $boxer->setSize(20, 30);

        // Each child should get 10 height (30 / 3)
        $this->assertSame(10, $boxer->getNode('0')->getHeight());
        $this->assertSame(10, $boxer->getNode('1')->getHeight());
        $this->assertSame(10, $boxer->getNode('2')->getHeight());

        // All should have full width
        $this->assertSame(20, $boxer->getNode('0')->getWidth());
        $this->assertSame(20, $boxer->getNode('1')->getWidth());
        $this->assertSame(20, $boxer->getNode('2')->getWidth());
    }

    public function testEvenSplitWithRemainder(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');
        $child3 = Node::leaf('2');

        $boxer = Boxer::horizontal($child1, $child2, $child3);
        $boxer = $boxer->setSize(31, 10);  // 31 / 3 = 10 remainder 1

        // First child gets the extra
        $this->assertSame(11, $boxer->getNode('0')->getWidth());
        $this->assertSame(10, $boxer->getNode('1')->getWidth());
        $this->assertSame(10, $boxer->getNode('2')->getWidth());
    }

    public function testSizeFuncOverridesEvenSplit(): void
    {
        $child1 = Node::leaf('0');
        $child2 = Node::leaf('1');

        // Custom sizeFunc that gives 70% to first, 30% to second
        $sizeFunc = fn(Node $node, int $totalWidth): array => [
            (int) ($totalWidth * 0.7),
            (int) ($totalWidth * 0.3),
        ];

        $root = Node::horizontal($child1, $child2)->withCustomSizeFunc($sizeFunc);
        $boxer = Boxer::tree($root)->setSize(100, 20);

        // Should use the sizeFunc distribution
        $this->assertEquals(70, $boxer->getNode('0')->getWidth());
        $this->assertEquals(30, $boxer->getNode('1')->getWidth());
    }

    // ═══════════════════════════════════════════════════════════════
    // Address handling
    // ═══════════════════════════════════════════════════════════════

    public function testAddressUniqueness(): void
    {
        $boxer1 = Boxer::leaf('leaf-a', $this->strItem('content1'));
        $boxer2 = Boxer::leaf('leaf-b', $this->strItem('content2'));

        $this->assertSame('leaf-a', $boxer1->getNode('leaf-a')->getAddress());
        $this->assertSame('leaf-b', $boxer2->getNode('leaf-b')->getAddress());
        $this->assertNotSame($boxer1->getNode('leaf-a')->getAddress(), $boxer2->getNode('leaf-b')->getAddress());
    }

    public function testNestedAddresses(): void
    {
        $leaf1 = Node::leaf('0');
        $leaf2 = Node::leaf('1');
        $leaf3 = Node::leaf('0.0');  // Child of leaf1's would-be parent
        $leaf4 = Node::leaf('0.1');

        $boxer = Boxer::vertical(
            Node::horizontal($leaf1, $leaf2),
            Node::horizontal($leaf3, $leaf4),
        );

        $this->assertSame('0', $boxer->getNode('0')->getAddress());
        $this->assertSame('1', $boxer->getNode('1')->getAddress());
        $this->assertSame('0.0', $boxer->getNode('0.0')->getAddress());
        $this->assertSame('0.1', $boxer->getNode('0.1')->getAddress());
    }

    // ═══════════════════════════════════════════════════════════════
    // Rendering
    // ═══════════════════════════════════════════════════════════════

    public function testBoxerRendersWithContent(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('Hello World'));
        $boxer = $boxer->setSize(20, 3);

        $rendered = $boxer->render();

        $this->assertStringContainsString('Hello World', $rendered);
    }

    public function testRenderWithoutSizeReturnsWaitingMessage(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $rendered = $boxer->render();

        $this->assertSame('waiting for size information', $rendered);
    }

    public function testBoxerSetSizeReturnsNewInstance(): void
    {
        $original = Boxer::leaf('0', $this->strItem('test'));
        $resized = $original->setSize(10, 5);

        $this->assertNotSame($original, $resized);
        $this->assertSame(0, $original->getWidth());
        $this->assertSame(0, $original->getHeight());
        $this->assertSame(10, $resized->getWidth());
        $this->assertSame(5, $resized->getHeight());
    }

    public function testNestedBoxerLayout(): void
    {
        // Create a nested layout: [ [A][B] ] [C] ]
        // This is: vertical containing horizontal(A,B) and C

        $itemA = $this->strItem('A');
        $itemB = $this->strItem('B');
        $itemC = $this->strItem('C');

        $root = Node::vertical(
            Node::horizontal(Node::leaf('0'), Node::leaf('1')),
            Node::leaf('2')
        );

        $boxer = Boxer::tree($root, [
            '0' => $itemA,
            '1' => $itemB,
            '2' => $itemC,
        ])->setSize(30, 10);

        $rendered = $boxer->render();

        $this->assertStringContainsString('A', $rendered);
        $this->assertStringContainsString('B', $rendered);
        $this->assertStringContainsString('C', $rendered);
    }

    public function testSetSizeToZeroReturnsZeroSizedBoxer(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $boxer = $boxer->setSize(10, 5);
        $boxer = $boxer->setSize(0, 0);

        $this->assertSame(0, $boxer->getWidth());
        $this->assertSame(0, $boxer->getHeight());
        $this->assertSame('waiting for size information', $boxer->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Accessors
    // ═══════════════════════════════════════════════════════════════

    public function testGetModelMap(): void
    {
        $item = $this->strItem('content');
        $boxer = Boxer::leaf('my-address', $item);

        $map = $boxer->getModelMap();

        $this->assertArrayHasKey('my-address', $map);
        $this->assertSame($item, $map['my-address']);
    }

    public function testGetItem(): void
    {
        $item = $this->strItem('content');
        $boxer = Boxer::leaf('addr', $item);

        $this->assertSame($item, $boxer->getItem('addr'));
        $this->assertNull($boxer->getItem('non-existent'));
    }

    public function testGetNode(): void
    {
        $boxer = Boxer::leaf('test-addr', $this->strItem('test'));

        $node = $boxer->getNode('test-addr');

        $this->assertNotNull($node);
        $this->assertSame('test-addr', $node->getAddress());
    }

    public function testGetNodeReturnsNullForNonexistent(): void
    {
        $boxer = Boxer::leaf('exists', $this->strItem('test'));

        $this->assertNull($boxer->getNode('does-not-exist'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testTreeWithEmptyModelMap(): void
    {
        $boxer = Boxer::tree(Node::horizontal(Node::leaf('0'), Node::leaf('1')));

        $this->assertSame([], $boxer->getModelMap());
    }

    public function testSetSizeIdempotent(): void
    {
        $boxer = Boxer::leaf('0', $this->strItem('test'));
        $boxer = $boxer->setSize(10, 5);
        $sameBoxer = $boxer->setSize(10, 5);

        $this->assertSame($boxer, $sameBoxer);
    }

    public function testEditLeafReturnsNewInstance(): void
    {
        $original = Boxer::leaf('0', $this->strItem('original'));
        $modified = $original->editLeaf('0', fn($item) => $this->strItem('modified'));

        $this->assertNotSame($original, $modified);
        $this->assertSame('original', $original->getItem('0')->render());
        $this->assertSame('modified', $modified->getItem('0')->render());
    }
}
