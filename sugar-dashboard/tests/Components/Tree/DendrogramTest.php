<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\Dendrogram;
use SugarCraft\Dashboard\Components\Tree\DendrogramNode;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

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

        $wide = Dendrogram::new()->withRoot($root)->setSize(60, 20)->render();
        $this->assertNotSame('', $wide);
        // Presence asserts stay as secondary evidence (finding 085: the box
        // characters used to be the ENTIRE claim - a component that ignored
        // the allocation and painted a constant frame stayed green).
        $this->assertStringContainsString('╭', $wide);
        $this->assertStringContainsString('╰', $wide);
        $this->assertSame(
            60,
            self::maxLineWidth($wide),
            'a 60-wide allocation must paint exactly 60 cells of frame',
        );

        $narrow = Dendrogram::new()->withRoot($root)->setSize(40, 20)->render();
        $this->assertSame(
            40,
            self::maxLineWidth($narrow),
            'the requested width is honored, not a hard-coded frame',
        );

        // A real tree must paint its bottom border as its OWN row - the
        // glued-border shape hid it inside a 257-cell content line.
        // (Full width-clipping of oversized tree rows is the D-24 clip
        // family; evidence banked in HANDOFF.)
        $sampleLines = array_map(
            static fn(string $line): string => preg_replace('/\x1b\[[0-9;]*m/', '', $line) ?? $line,
            explode("\n", Dendrogram::sample()->setSize(60, 20)->render()),
        );
        $borderRows = array_values(array_filter(
            $sampleLines,
            static fn(string $line): bool => str_starts_with($line, '╰'),
        ));
        $this->assertNotEmpty($borderRows, 'the bottom border must start its own row');
        $this->assertSame(60, Width::string($borderRows[0]));
    }

    // ═══════════════════════════════════════════════════════════════
    // Connector emission (finding sugar-dashboard-157, D-87)
    // ═══════════════════════════════════════════════════════════════

    public function testTwoChildTreePaintsConnectorsBetweenLabels(): void
    {
        $tree = (new DendrogramNode('r', 'Root'))
            ->withChildren([new DendrogramNode('a', 'Alpha'), new DendrogramNode('b', 'Beta')]);
        $rows = self::plainRows(Dendrogram::new()->withRoot($tree)->withStyle('single')->setSize(60, 20)->render());

        $parentRow = array_key_first(array_filter($rows, static fn(string $r): bool => (bool) preg_match('/\[\s*Root\s*\]/', $r)));
        $this->assertNotNull($parentRow, 'root label is painted');

        $bridge = $rows[$parentRow + 1];
        $this->assertMatchesRegularExpression('/┌─+┬─+┐/u', $bridge, 'corner-span-tee bridge row between parent and children');

        // Both children sit on a lower row, in slot order.
        for ($r = $parentRow + 2; $r < count($rows); $r++) {
            if (preg_match('/\[\s*Alpha\s*\]/', $rows[$r])) {
                $this->assertMatchesRegularExpression('/\[\s*Beta\s*\]/', $rows[$r], 'both children share their level row');
                preg_match('/\[\s*Alpha\s*\]/', $rows[$r], $ma, PREG_OFFSET_CAPTURE);
                preg_match('/\[\s*Beta\s*\]/', $rows[$r], $mb, PREG_OFFSET_CAPTURE);
                $this->assertLessThan($mb[0][1], $ma[0][1], 'left slot keeps the left child');
                return;
            }
        }
        $this->fail('children labels never painted below their bridge');
    }

    public function testSingleChildTreeDropsAStraightStub(): void
    {
        $tree = (new DendrogramNode('r', 'Root'))
            ->withChildren([(new DendrogramNode('a', 'OnlyChild'))]);
        $rows = self::plainRows(Dendrogram::new()->withRoot($tree)->withStyle('single')->setSize(60, 20)->render());

        $parentRow = array_key_first(array_filter($rows, static fn(string $r): bool => (bool) preg_match('/\[\s*Root\s*\]/', $r)));
        $this->assertNotNull($parentRow);
        preg_match('/\[\s*Root\s*\]/', $rows[$parentRow], $mp, PREG_OFFSET_CAPTURE);
        $parentCol = $mp[0][1] + intdiv(12, 2); // centre of the 12-cell box

        $stub = $rows[$parentRow + 1];
        $this->assertNotFalse(strpos($stub, '│'), 'a vertical stub leaves the parent');
        $this->assertSame(1, substr_count($stub, '│'), 'exactly one drop under a single child');
        $this->assertSame($parentCol, strpos($stub, '│'), 'the stub sits under the parent centre');

        $childRow = array_key_first(array_filter($rows, static fn(string $r): bool => (bool) preg_match('/\[\s*OnlyChild\s*\]/', $r)));
        $this->assertNotNull($childRow, 'the child label is painted');
        $this->assertGreaterThan($parentRow + 1, $childRow);
        preg_match('/\[\s*OnlyChild\s*\]/', $rows[$childRow], $mc, PREG_OFFSET_CAPTURE);
        $this->assertSame(strpos($stub, '│'), $mc[0][1] + intdiv(12, 2), 'stub column meets the child centre');
    }

    public function testBridgeUsesStyleGlyphs(): void
    {
        $tree = (new DendrogramNode('r', 'Root'))
            ->withChildren([new DendrogramNode('a', 'Alpha'), new DendrogramNode('b', 'Beta')]);

        $rounded = self::plainRows(Dendrogram::new()->withRoot($tree)->withStyle('rounded')->setSize(60, 20)->render());
        $double = self::plainRows(Dendrogram::new()->withRoot($tree)->withStyle('double')->setSize(60, 20)->render());

        $roundedBridge = implode('', array_filter($rounded, static fn(string $r): bool => str_contains($r, '╭') && str_contains($r, '╮')));
        $this->assertStringContainsString('┬', $roundedBridge, 'rounded style keeps the single-weight tee');
        $doubleBridge = implode('', array_filter($double, static fn(string $r): bool => str_contains($r, '╔') && str_contains($r, '╗')));
        $this->assertStringContainsString('╥', $doubleBridge, 'double style carries its heavy tee');
    }

    /**
     * Render rows with SGR stripped - connectors are always colored by
     * default, geometry pins need plain cells.
     *
     * @return list<string>
     */
    private static function plainRows(string $block): array
    {
        return explode("\n", (string) preg_replace('/\x1b\[[0-9;]*m/', '', $block));
    }

    /**
     * Widest painted line in cells - the geometry claim behind D-86.
     */
    private static function maxLineWidth(string $block): int
    {
        return max(array_map(
            static fn(string $line): int => Width::string($line),
            explode("\n", $block),
        ));
    }

    public function testGetInnerSize(): void
    {
        $root = new DendrogramNode('1', 'Root', 100);
        $dendrogram = Dendrogram::new()->withRoot($root)->setSize(60, 20);

        [$w, $h] = $dendrogram->innerSize();
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
