<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use SugarCraft\Dash\Components\Tree\Sankey;
use SugarCraft\Dash\Components\Tree\SankeyNode;
use SugarCraft\Dash\Components\Tree\SankeyFlow;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class SankeyTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testSankeyImplementsSizer(): void
    {
        $sankey = Sankey::new();
        $this->assertInstanceOf(Sizer::class, $sankey);
    }

    public function testSankeyImplementsItem(): void
    {
        $sankey = Sankey::new();
        $this->assertInstanceOf(Item::class, $sankey);
    }

    // ═══════════════════════════════════════════════════════════════
    // SankeyNode
    // ═══════════════════════════════════════════════════════════════

    public function testSankeyNodeCreation(): void
    {
        $node = new SankeyNode('test', 'Test Node', 100);

        $this->assertSame('test', $node->id);
        $this->assertSame('Test Node', $node->label);
        $this->assertSame(100.0, $node->value);
        $this->assertNull($node->color);
    }

    public function testSankeyNodeWithColor(): void
    {
        $color = Color::hex('#89B4FA');
        $node = new SankeyNode('test', 'Test', 50, $color);

        $this->assertSame($color, $node->color);
    }

    public function testSankeyNodeWithColorViaWither(): void
    {
        $node = new SankeyNode('test', 'Test', 50);
        $color = Color::hex('#89B4FA');
        $withColor = $node->withColor($color);

        $this->assertSame($color, $withColor->color);
        $this->assertNull($node->color);
    }

    public function testSankeyNodeWithColorViaConstructor(): void
    {
        $node = new SankeyNode('test', 'Test', 50);
        $color = Color::hex('#89B4FA');
        $withColor = $node->withColor($color);

        $this->assertSame($color, $withColor->color);
        $this->assertNull($node->color);
    }

    // ═══════════════════════════════════════════════════════════════
    // SankeyFlow
    // ═══════════════════════════════════════════════════════════════

    public function testSankeyFlowCreation(): void
    {
        $flow = new SankeyFlow('source1', 'target1', 50);

        $this->assertSame('source1', $flow->source);
        $this->assertSame('target1', $flow->target);
        $this->assertSame(50.0, $flow->value);
        $this->assertNull($flow->color);
    }

    public function testSankeyFlowWithColor(): void
    {
        $color = Color::hex('#F38BA8');
        $flow = new SankeyFlow('a', 'b', 75, $color);

        $this->assertSame($color, $flow->color);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsEmptyWithNoNodes(): void
    {
        $sankey = Sankey::new();
        $this->assertSame('', $sankey->render());
    }

    public function testRenderReturnsNonEmptyWithNodes(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->addNode('n2', 'Node 2', 50);
        $rendered = $sankey->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Node and flow operations
    // ═══════════════════════════════════════════════════════════════

    public function testAddNodeReturnsNewInstance(): void
    {
        $original = Sankey::new();
        $updated = $original->addNode('n1', 'Node 1', 100);

        $this->assertNotSame($original, $updated);
    }

    public function testAddMultipleNodes(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->addNode('n2', 'Node 2', 75)
            ->addNode('n3', 'Node 3', 50);

        $rendered = $sankey->render();

        $this->assertStringContainsString('Node 1', $rendered);
        $this->assertStringContainsString('Node 2', $rendered);
        $this->assertStringContainsString('Node 3', $rendered);
    }

    public function testAddFlowReturnsNewInstance(): void
    {
        $original = Sankey::new()->addNode('n1', 'Node 1', 100);
        $updated = $original->addFlow('n1', 'n2', 50);

        $this->assertNotSame($original, $updated);
    }

    public function testWithNodesReplacesNodes(): void
    {
        $nodes = [
            new SankeyNode('n1', 'Node 1', 100),
            new SankeyNode('n2', 'Node 2', 50),
        ];
        $sankey = Sankey::new()->withNodes($nodes);

        $rendered = $sankey->render();

        $this->assertStringContainsString('Node 1', $rendered);
        $this->assertStringContainsString('Node 2', $rendered);
    }

    public function testWithFlowsReplacesFlows(): void
    {
        $flows = [
            new SankeyFlow('n1', 'n2', 50),
        ];
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->withFlows($flows);

        $this->assertNotSame('', $sankey->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Layout options
    // ═══════════════════════════════════════════════════════════════

    public function testHorizontalLayout(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->addNode('n2', 'Node 2', 50)
            ->withHorizontal(true);

        $this->assertNotSame('', $sankey->render());
    }

    public function testVerticalLayout(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->addNode('n2', 'Node 2', 50)
            ->withHorizontal(false);

        $this->assertNotSame('', $sankey->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Label and value display
    // ═══════════════════════════════════════════════════════════════

    public function testShowLabelsDefaultTrue(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Alpha', 100);

        $rendered = $sankey->render();

        $this->assertStringContainsString('Alpha', $rendered);
    }

    public function testHideLabels(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Alpha', 100)
            ->withShowLabels(false);

        $rendered = $sankey->render();

        $this->assertStringNotContainsString('Alpha', $rendered);
    }

    public function testShowValues(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Alpha', 100)
            ->withShowValues(true);

        $rendered = $sankey->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testNodeColorAddsAnsiCodes(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Alpha', 100)
            ->withNodeColor(Color::ansi(9));

        $rendered = $sankey->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testLabelColorAddsAnsiCodes(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Alpha', 100)
            ->withLabelColor(Color::ansi(10));

        $rendered = $sankey->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testBorderStyles(): void
    {
        $styles = ['rounded', 'single', 'double', 'bold', 'empty'];

        foreach ($styles as $style) {
            $sankey = Sankey::new()
                ->addNode('n1', 'Alpha', 100)
                ->withStyle($style);

            $rendered = $sankey->render();

            $this->assertNotSame('', $rendered, "Style '$style' should render");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Sankey::new()->addNode('n1', 'Node 1', 100);
        $resized = $original->setSize(60, 20);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->setSize(60, 20);

        $rendered = $sankey->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithNodeColorReturnsNewInstance(): void
    {
        $original = Sankey::new()->addNode('n1', 'Node 1', 100);
        $updated = $original->withNodeColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithFlowColorReturnsNewInstance(): void
    {
        $original = Sankey::new()->addNode('n1', 'Node 1', 100);
        $updated = $original->withFlowColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithLabelColorReturnsNewInstance(): void
    {
        $original = Sankey::new()->addNode('n1', 'Node 1', 100);
        $updated = $original->withLabelColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithNodeWidthReturnsNewInstance(): void
    {
        $original = Sankey::new()->addNode('n1', 'Node 1', 100);
        $updated = $original->withNodeWidth(4);

        $this->assertNotSame($original, $updated);
    }

    public function testWithNodeSpacingReturnsNewInstance(): void
    {
        $original = Sankey::new()->addNode('n1', 'Node 1', 100);
        $updated = $original->withNodeSpacing(5);

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->addNode('n2', 'Node 2', 50)
            ->setSize(60, 20);
        [$w, $h] = $sankey->getInnerSize();

        $this->assertSame(60, $w);
        $this->assertSame(20, $h);
    }

    public function testGetInnerSizeWithDefaultValues(): void
    {
        $sankey = Sankey::new()->addNode('n1', 'Node 1', 100);
        [$w, $h] = $sankey->getInnerSize();

        $this->assertSame(60, $w);
        $this->assertGreaterThanOrEqual(10, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumWidthRendersEmpty(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->setSize(15, 20);

        $this->assertSame('', $sankey->render());
    }

    public function testMinimumHeightRendersEmpty(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->setSize(60, 3);

        $this->assertSame('', $sankey->render());
    }

    public function testLargeValueFormatting(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Big', 1000000)
            ->withShowValues(true);

        $rendered = $sankey->render();

        $this->assertNotSame('', $rendered);
    }

    public function testZeroNodeSpacing(): void
    {
        $sankey = Sankey::new()
            ->addNode('n1', 'Node 1', 100)
            ->addNode('n2', 'Node 2', 50)
            ->withNodeSpacing(0);

        $rendered = $sankey->render();

        $this->assertNotSame('', $rendered);
    }
}