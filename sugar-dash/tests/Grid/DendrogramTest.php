<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Dendrogram;
use SugarCraft\Dash\Grid\DendrogramNode;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;

final class DendrogramTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testDendrogramImplementsSizer(): void
    {
        $dendrogram = Dendrogram::new();
        $this->assertInstanceOf(Sizer::class, $dendrogram);
    }

    // ═══════════════════════════════════════════════════════════════
    // DendrogramNode
    // ═══════════════════════════════════════════════════════════════

    public function testNodeCreation(): void
    {
        $node = new DendrogramNode('1', 'Root', 100);

        $this->assertSame('1', $node->id);
        $this->assertSame('Root', $node->label);
        $this->assertSame(100.0, $node->value);
        $this->assertNull($node->color);
        $this->assertCount(0, $node->children);
    }

    public function testNodeWithColor(): void
    {
        $color = Color::hex('#89B4FA');
        $node = new DendrogramNode('1', 'Root', 100, $color);

        $this->assertSame($color, $node->color);
    }

    public function testNodeWithChildren(): void
    {
        $child1 = new DendrogramNode('1a', 'Child 1', 50);
        $child2 = new DendrogramNode('1b', 'Child 2', 50);
        $node = (new DendrogramNode('1', 'Root', 100))->withChildren([$child1, $child2]);

        $this->assertCount(2, $node->children);
    }

    public function testNodeGetTotalValue(): void
    {
        $child1 = new DendrogramNode('1a', 'Child 1', 50);
        $child2 = new DendrogramNode('1b', 'Child 2', 50);
        $node = (new DendrogramNode('1', 'Root', 100))->withChildren([$child1, $child2]);

        $this->assertSame(200.0, $node->getTotalValue());
    }

    public function testNodeGetDepth(): void
    {
        $grandchild = new DendrogramNode('1aa', 'Grandchild', 25);
        $child = (new DendrogramNode('1a', 'Child', 50))->withChildren([$grandchild]);
        $root = (new DendrogramNode('1', 'Root', 100))->withChildren([$child]);

        $this->assertSame(3, $root->getDepth());
    }

    public function testNodeWithColorReturnsNewInstance(): void
    {
        $node = new DendrogramNode('1', 'Root', 100);
        $color = Color::hex('#89B4FA');
        $withColor = $node->withColor($color);

        $this->assertSame($color, $withColor->color);
        $this->assertNull($node->color);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testNewCreatesDefaultInstance(): void
    {
        $dendrogram = Dendrogram::new();
        $this->assertInstanceOf(Dendrogram::class, $dendrogram);
    }

    public function testRenderReturnsEmptyWithNoRoot(): void
    {
        $dendrogram = Dendrogram::new();
        $this->assertSame('', $dendrogram->render());
    }

    public function testRenderReturnsNonEmptyWithRoot(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root);

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBorderCharacters(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(60, 20);

        $rendered = $dendrogram->render();
        $this->assertMatchesRegularExpression('/[╭╮╰╯│─]/', $rendered);
    }

    public function testSmallDimensionsReturnEmpty(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(15, 5);

        $this->assertSame('', $dendrogram->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Sample creation
    // ═══════════════════════════════════════════════════════════════

    public function testSampleCreatesValidTree(): void
    {
        $dendrogram = Dendrogram::sample();

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Display options
    // ═══════════════════════════════════════════════════════════════

    public function testWithShowLabels(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root);

        $result = $dendrogram->withShowLabels(false);
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    public function testWithShowValues(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root);

        $result = $dendrogram->withShowValues(true);
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    public function testWithHorizontal(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root);

        $result = $dendrogram->withHorizontal(true);
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    public function testWithStyle(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root);

        $result = $dendrogram->withStyle('bold');
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    public function testWithNodeSize(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root);

        $result = $dendrogram->withNodeSize(10, 3);
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testBorderStyles(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $styles = ['rounded', 'single', 'double', 'bold', 'empty'];

        foreach ($styles as $style) {
            $dendrogram = Dendrogram::new()
                ->withRoot($root)
                ->withStyle($style)
                ->setSize(60, 20);

            $rendered = $dendrogram->render();
            $this->assertNotSame('', $rendered, "Style '$style' should render");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsSizer(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root);

        $result = $dendrogram->setSize(60, 20);
        $this->assertInstanceOf(Sizer::class, $result);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(60, 20);

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSize(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(60, 20);

        [$w, $h] = $dendrogram->getInnerSize();
        $this->assertSame(60, $w);
        $this->assertSame(20, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithNodeColor(): void
    {
        $dendrogram = Dendrogram::new();
        $result = $dendrogram->withNodeColor(Color::hex('#FF0000'));
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    public function testWithLineColor(): void
    {
        $dendrogram = Dendrogram::new();
        $result = $dendrogram->withLineColor(Color::hex('#00FF00'));
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    public function testWithTextColor(): void
    {
        $dendrogram = Dendrogram::new();
        $result = $dendrogram->withTextColor(Color::hex('#0000FF'));
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    public function testWithLeafColor(): void
    {
        $dendrogram = Dendrogram::new();
        $result = $dendrogram->withLeafColor(Color::hex('#FFFF00'));
        $this->assertInstanceOf(Dendrogram::class, $result);
    }

    public function testWithersReturnNewInstance(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $original = Dendrogram::new()->withRoot($root);
        $updated = $original->withNodeColor(Color::hex('#FF0000'));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Hierarchy rendering
    // ═══════════════════════════════════════════════════════════════

    public function testSingleNode(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(60, 20);

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }

    public function testTwoLevels(): void
    {
        $child = new DendrogramNode('1a', 'Child', 50);
        $root = (new DendrogramNode('1', 'Root', 100))->withChildren([$child]);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(60, 20);

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }

    public function testThreeLevels(): void
    {
        $grandchild = new DendrogramNode('1aa', 'Grandchild', 25);
        $child = (new DendrogramNode('1a', 'Child', 50))->withChildren([$grandchild]);
        $root = (new DendrogramNode('1', 'Root', 100))->withChildren([$child]);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(60, 20);

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }

    public function testMultipleChildren(): void
    {
        $child1 = new DendrogramNode('1a', 'Child A', 33);
        $child2 = new DendrogramNode('1b', 'Child B', 33);
        $child3 = new DendrogramNode('1c', 'Child C', 34);
        $root = (new DendrogramNode('1', 'Root', 100))->withChildren([$child1, $child2, $child3]);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(60, 20);

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }

    public function testHorizontalOrientation(): void
    {
        $child = new DendrogramNode('1a', 'Child', 50);
        $root = (new DendrogramNode('1', 'Root', 100))->withChildren([$child]);
        $dendrogram = Dendrogram::new()->withRoot($root)->withHorizontal(true)->setSize(60, 20);

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testColorAddsAnsiCodes(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()
            ->withRoot($root)
            ->withNodeColor(Color::ansi(12));

        $rendered = $dendrogram->render();
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testNodeWithSpecificColor(): void
    {
        $node = new DendrogramNode('1', 'Root', 100, Color::hex('#FF0000'));
        $dendrogram = Dendrogram::new()->withRoot($node)->setSize(60, 20);

        $rendered = $dendrogram->render();
        $this->assertNotSame('', $rendered);
    }
}
