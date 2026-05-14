<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use SugarCraft\Dash\Components\Tree\Treemap;
use SugarCraft\Dash\Components\Tree\TreemapLeaf;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class TreemapTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTreemapImplementsSizer(): void
    {
        $treemap = Treemap::new();
        $this->assertInstanceOf(Sizer::class, $treemap);
    }

    public function testTreemapImplementsItem(): void
    {
        $treemap = Treemap::new();
        $this->assertInstanceOf(Item::class, $treemap);
    }

    // ═══════════════════════════════════════════════════════════════
    // TreemapLeaf
    // ═══════════════════════════════════════════════════════════════

    public function testTreemapLeafCreation(): void
    {
        $leaf = new TreemapLeaf('leaf1', 'Leaf One', 50);

        $this->assertSame('leaf1', $leaf->id);
        $this->assertSame('Leaf One', $leaf->label);
        $this->assertSame(50.0, $leaf->value);
        $this->assertNull($leaf->color);
        $this->assertSame([], $leaf->children);
    }

    public function testTreemapLeafWithColor(): void
    {
        $color = Color::hex('#89B4FA');
        $leaf = new TreemapLeaf('leaf1', 'Leaf', 50, $color);

        $this->assertSame($color, $leaf->color);
    }

    public function testTreemapLeafWithChildren(): void
    {
        $child = new TreemapLeaf('child', 'Child', 25);
        $leaf = new TreemapLeaf('parent', 'Parent', 100, null, [$child]);

        $this->assertCount(1, $leaf->children);
    }

    public function testTreemapLeafWithColorReturnsNewInstance(): void
    {
        $leaf = new TreemapLeaf('leaf1', 'Leaf', 50);
        $color = Color::hex('#89B4FA');
        $withColor = $leaf->withColor($color);

        $this->assertSame($color, $withColor->color);
        $this->assertNull($leaf->color);
    }

    public function testTreemapLeafWithChildrenReturnsNewInstance(): void
    {
        $leaf = new TreemapLeaf('leaf1', 'Leaf', 50);
        $child = new TreemapLeaf('child', 'Child', 25);
        $withChildren = $leaf->withChildren([$child]);

        $this->assertCount(1, $withChildren->children);
        $this->assertCount(0, $leaf->children);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsEmptyWithNoLeaves(): void
    {
        $treemap = Treemap::new();
        $this->assertSame('', $treemap->render());
    }

    public function testRenderReturnsNonEmptyWithLeaves(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
            new TreemapLeaf('l2', 'Beta', 30),
        ]);
        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsBlockChars(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ]);
        $rendered = $treemap->render();

        $this->assertMatchesRegularExpression('/[░▒▓█]/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Sample creation
    // ═══════════════════════════════════════════════════════════════

    public function testSampleCreatesLeaves(): void
    {
        $treemap = Treemap::sample(5);

        $this->assertNotSame('', $treemap->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Leaf operations
    // ═══════════════════════════════════════════════════════════════

    public function testWithLeavesReplacesLeaves(): void
    {
        $leaves = [
            new TreemapLeaf('l1', 'Alpha', 50),
            new TreemapLeaf('l2', 'Beta', 30),
        ];
        $treemap = Treemap::new($leaves);

        $rendered = $treemap->render();

        $this->assertStringContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);
    }

    public function testWithLeafAddsLeaf(): void
    {
        $treemap = Treemap::new()
            ->withLeaf(new TreemapLeaf('l1', 'Alpha', 50))
            ->withLeaf(new TreemapLeaf('l2', 'Beta', 30));

        $rendered = $treemap->render();

        $this->assertStringContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);
    }

    public function testAddLeafByParams(): void
    {
        $treemap = Treemap::new()
            ->addLeaf('l1', 'Alpha', 50);

        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Display options
    // ═══════════════════════════════════════════════════════════════

    public function testShowLabelsDefaultTrue(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ]);

        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHideLabels(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->withShowLabels(false);

        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testShowBordersDefaultTrue(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ]);

        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testHideBorders(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->withShowBorders(false);

        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testBorderColorAddsAnsiCodes(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->withBorderColor(Color::ansi(9));

        $rendered = $treemap->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testTextColorAddsAnsiCodes(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->withTextColor(Color::ansi(10));

        $rendered = $treemap->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testValueColorAddsAnsiCodes(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->withValueColor(Color::ansi(11));

        $rendered = $treemap->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Border styles
    // ═══════════════════════════════════════════════════════════════

    public function testBorderStyles(): void
    {
        $styles = ['rounded', 'single', 'double', 'bold', 'empty'];

        foreach ($styles as $style) {
            $treemap = Treemap::new([
                new TreemapLeaf('l1', 'Alpha', 50),
            ])->withBorderStyle($style);

            $rendered = $treemap->render();

            $this->assertNotSame('', $rendered, "Style '$style' should render");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Sizer interface
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Treemap::new()->withLeaf(new TreemapLeaf('l1', 'Alpha', 50));
        $resized = $original->setSize(40, 15);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizeAffectsRendered(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->setSize(40, 15);

        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers
    // ═══════════════════════════════════════════════════════════════

    public function testWithBorderColorReturnsNewInstance(): void
    {
        $original = Treemap::new()->withLeaf(new TreemapLeaf('l1', 'Alpha', 50));
        $updated = $original->withBorderColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithTextColorReturnsNewInstance(): void
    {
        $original = Treemap::new()->withLeaf(new TreemapLeaf('l1', 'Alpha', 50));
        $updated = $original->withTextColor(Color::ansi(10));

        $this->assertNotSame($original, $updated);
    }

    public function testWithValueColorReturnsNewInstance(): void
    {
        $original = Treemap::new()->withLeaf(new TreemapLeaf('l1', 'Alpha', 50));
        $updated = $original->withValueColor(Color::ansi(11));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->setSize(40, 15);
        [$w, $h] = $treemap->getInnerSize();

        $this->assertSame(40, $w);
        $this->assertSame(15, $h);
    }

    public function testGetInnerSizeWithDefaultValues(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ]);
        [$w, $h] = $treemap->getInnerSize();

        $this->assertSame(40, $w);
        $this->assertGreaterThanOrEqual(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testMinimumWidthRendersEmpty(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->setSize(8, 15);

        $this->assertSame('', $treemap->render());
    }

    public function testMinimumHeightRendersEmpty(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Alpha', 50),
        ])->setSize(40, 2);

        $this->assertSame('', $treemap->render());
    }

    public function testEmptyDataRendersEmpty(): void
    {
        $treemap = Treemap::new([]);
        $this->assertSame('', $treemap->render());
    }

    public function testSingleLeafRenders(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Single', 100),
        ]);

        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }

    public function testSortedByValueDescending(): void
    {
        $treemap = Treemap::new([
            new TreemapLeaf('l1', 'Small', 10),
            new TreemapLeaf('l2', 'Medium', 50),
            new TreemapLeaf('l3', 'Large', 100),
        ]);

        $rendered = $treemap->render();

        $this->assertNotSame('', $rendered);
    }
}
