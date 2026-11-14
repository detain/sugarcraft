<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use SugarCraft\Dashboard\Components\Tree\Tree;
use SugarCraft\Dashboard\Components\Tree\TreeNode;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Width;
use PHPUnit\Framework\TestCase;

final class TreeTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testTreeImplementsSizer(): void
    {
        $tree = Tree::new([TreeNode::new('Root')]);
        $this->assertInstanceOf(Sizer::class, $tree);
    }

    public function testTreeImplementsItem(): void
    {
        $tree = Tree::new([TreeNode::new('Root')]);
        $this->assertInstanceOf(Item::class, $tree);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $tree = Tree::new([TreeNode::new('Root')]);
        $rendered = $tree->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsLabel(): void
    {
        $tree = Tree::new([TreeNode::new('My Root')]);
        $rendered = $tree->render();

        $this->assertStringContainsString('My Root', $rendered);
    }

    public function testRenderEmptyNodesReturnsEmpty(): void
    {
        $tree = Tree::new([]);
        $rendered = $tree->render();

        $this->assertSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Tree structure
    // ═══════════════════════════════════════════════════════════════

    public function testRenderSingleNode(): void
    {
        $tree = Tree::new([TreeNode::new('Root')]);
        $rendered = $tree->render();

        $this->assertStringContainsString('Root', $rendered);
    }

    public function testRenderNodeWithChildren(): void
    {
        $tree = Tree::new([
            TreeNode::new('Root')->withChildren([
                TreeNode::new('Child 1'),
                TreeNode::new('Child 2'),
            ]),
        ]);
        $rendered = $tree->render();

        $this->assertStringContainsString('Root', $rendered);
        $this->assertStringContainsString('Child 1', $rendered);
        $this->assertStringContainsString('Child 2', $rendered);
    }

    public function testRenderNestedChildren(): void
    {
        $tree = Tree::new([
            TreeNode::new('Level 1')->withChildren([
                TreeNode::new('Level 2')->withChildren([
                    TreeNode::new('Level 3'),
                ]),
            ]),
        ]);
        $rendered = $tree->render();

        $this->assertStringContainsString('Level 1', $rendered);
        $this->assertStringContainsString('Level 2', $rendered);
        $this->assertStringContainsString('Level 3', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Collapsed nodes
    // ═══════════════════════════════════════════════════════════════

    public function testCollapsedNodeHidesChildren(): void
    {
        $tree = Tree::new([
            TreeNode::new('Parent', false, [
                TreeNode::new('Hidden Child'),
            ]),
        ])->withNodeCollapsed('Parent', true);

        $rendered = $tree->render();

        $this->assertStringContainsString('Parent', $rendered);
        $this->assertStringNotContainsString('Hidden Child', $rendered);
    }

    public function testCollapsedNodeShowsIndicator(): void
    {
        $tree = Tree::new([
            TreeNode::new('Parent', false, [
                TreeNode::new('Child'),
            ]),
        ])->withNodeCollapsed('Parent', true);

        $rendered = $tree->render();

        $this->assertStringContainsString('[+]', $rendered);
    }

    public function testExpandedNodeDoesNotShowIndicator(): void
    {
        $tree = Tree::new([
            TreeNode::new('Parent', false, [
                TreeNode::new('Child'),
            ]),
        ]);

        $rendered = $tree->render();

        $this->assertStringNotContainsString('[+]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Tree lines
    // ═══════════════════════════════════════════════════════════════

    public function testShowLinesAddsBranchChars(): void
    {
        $tree = Tree::new([
            TreeNode::new('Root')->withChildren([
                TreeNode::new('Child'),
            ]),
        ]);

        $rendered = $tree->render();

        // Should contain branch characters
        $this->assertMatchesRegularExpression('/[├└]/', $rendered);
    }

    public function testHideLinesRemovesBranchChars(): void
    {
        $tree = Tree::new([
            TreeNode::new('Root')->withChildren([
                TreeNode::new('Child'),
            ]),
        ])->withShowLines(false);

        $rendered = $tree->render();

        $this->assertStringNotContainsString('├', $rendered);
        $this->assertStringNotContainsString('└', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testNodeColorAddsAnsiCodes(): void
    {
        $tree = Tree::new([TreeNode::new('Root')])
            ->withNodeColor(Color::ansi(9));
        $rendered = $tree->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testCollapsedColorAddsAnsiCodes(): void
    {
        $tree = Tree::new([
            TreeNode::new('Parent', false, [
                TreeNode::new('Child'),
            ]),
        ])->withNodeCollapsed('Parent', true);

        $rendered = $tree->render();
        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $tree = Tree::new([TreeNode::new('Root')])
            ->withNodeColor(Color::ansi(9));
        $rendered = $tree->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom branch characters
    // ═══════════════════════════════════════════════════════════════

    public function testWithBranchCharsChangesMarkers(): void
    {
        // Use two root nodes to ensure both branch and end chars appear
        // First root will use branch char, second root will use end char
        $tree = Tree::new([
            TreeNode::new('Root1')->withChildren([
                TreeNode::new('Child1'),
            ]),
            TreeNode::new('Root2'),
        ])->withBranchChars('|-', '`->');

        $rendered = $tree->render();

        $this->assertStringContainsString('|-', $rendered);
        $this->assertStringContainsString('`->', $rendered);
        $this->assertStringNotContainsString('├──', $rendered);
        $this->assertStringNotContainsString('└──', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithShowLinesReturnsNewInstance(): void
    {
        $original = Tree::new([TreeNode::new('Root')]);
        $updated = $original->withShowLines(false);

        $this->assertNotSame($original, $updated);
    }

    public function testWithNodeColorReturnsNewInstance(): void
    {
        $original = Tree::new([TreeNode::new('Root')]);
        $updated = $original->withNodeColor(Color::ansi(9));

        $this->assertNotSame($original, $updated);
    }

    public function testWithNodeCollapsedReturnsNewInstance(): void
    {
        $original = Tree::new([TreeNode::new('Root')]);
        $updated = $original->withNodeCollapsed('Root', true);

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithNodeCollapsed(): void
    {
        $original = Tree::new([
            TreeNode::new('Parent', false, [
                TreeNode::new('Child'),
            ]),
        ]);
        $original->withNodeCollapsed('Parent', true);

        $this->assertStringNotContainsString('[+]', $original->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Tree::new([TreeNode::new('Root')]);
        $resized = $original->setSize(20, 5);

        $this->assertNotSame($original, $resized);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $tree = Tree::new([TreeNode::new('Root')]);
        [$w, $h] = $tree->innerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertGreaterThan(0, $h);
    }

    public function testGetInnerSizeWithChildren(): void
    {
        $tree = Tree::new([
            TreeNode::new('Root')->withChildren([
                TreeNode::new('Child'),
            ]),
        ]);
        [, $h] = $tree->innerSize();

        // Root + Child = 2 lines
        $this->assertSame(2, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // D-140: id-keyed state and drawing-aware measurement
    // ═══════════════════════════════════════════════════════════════

    public function testSameLabelNodesToggleIndependentlyById(): void
    {
        $tree = Tree::new([
            TreeNode::withId('sec-a', 'Details')->withChildren([
                TreeNode::withId('sec-a-1', 'Alpha'),
            ]),
            TreeNode::withId('sec-b', 'Details')->withChildren([
                TreeNode::withId('sec-b-1', 'Beta'),
            ]),
        ]);

        $collapsed = $tree->withNodeCollapsed('sec-a', true);
        $rendered = $collapsed->render();

        // Exactly one ' [+]' marker: only the addressed node collapsed.
        $this->assertSame(1, substr_count($rendered, '[+]'));
        // sec-a's child is hidden, sec-b's child still paints.
        $this->assertStringNotContainsString('Alpha', $rendered);
        $this->assertStringContainsString('Beta', $rendered);

        // The untouched tree keeps both expanded (immutability).
        $this->assertSame(0, substr_count($tree->render(), '[+]'));
    }

    public function testIdlessNodesKeepLegacyLabelToggle(): void
    {
        $tree = Tree::new([
            TreeNode::new('Details')->withChildren([TreeNode::new('One')]),
            TreeNode::new('Other')->withChildren([TreeNode::new('Two')]),
        ]);

        $collapsed = $tree->withNodeCollapsed('Details', true);

        // Legacy contract: id-less nodes answer to their label; 'Other'
        // is untouched and its child keeps painting.
        $this->assertSame(1, substr_count($collapsed->render(), '[+]'));
        $this->assertStringNotContainsString('One', $collapsed->render());
        $this->assertStringContainsString('Two', $collapsed->render());
    }

    public function testMeasureFollowsConfiguredBranchDrawing(): void
    {
        $nodes = [
            TreeNode::withId('r', 'Root')->withChildren([
                TreeNode::withId('c', 'Child')->withChildren([
                    TreeNode::withId('g', 'Grandchild'),
                ]),
                TreeNode::withId('l', 'Last')->withCollapsed(true),
            ]),
        ];

        $drawings = [
            'default 3-cell' => Tree::new($nodes),
            'custom 2-cell' => (new Tree($nodes))
                ->withBranchChars('|-', '`->'),
            'plain box' => new Tree($nodes, showLines: true, branchChar: '++', endChar: '--', verticalChar: '!!', spaceChar: '  '),
        ];

        foreach ($drawings as $case => $tree) {
            $width = $tree->innerSize()[0];
            $lines = explode("\n", $tree->render());
            $painted = max(array_map(static fn (string $l): int => Width::string($l), $lines));
            $this->assertSame($width, $painted, "size math must agree with painted rows: {$case}");
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testUnicodeLabel(): void
    {
        $tree = Tree::new([TreeNode::new('ルート')]);
        $rendered = $tree->render();

        $this->assertStringContainsString('ルート', $rendered);
    }

    public function testDeeplyNestedTree(): void
    {
        $tree = Tree::new([
            TreeNode::new('L1')->withChildren([
                TreeNode::new('L2')->withChildren([
                    TreeNode::new('L3')->withChildren([
                        TreeNode::new('L4'),
                    ]),
                ]),
            ]),
        ]);

        $rendered = $tree->render();

        $this->assertStringContainsString('L1', $rendered);
        $this->assertStringContainsString('L4', $rendered);
    }

    public function testMultipleRoots(): void
    {
        $tree = Tree::new([
            TreeNode::new('Root 1'),
            TreeNode::new('Root 2'),
        ]);

        $rendered = $tree->render();

        $this->assertStringContainsString('Root 1', $rendered);
        $this->assertStringContainsString('Root 2', $rendered);
    }
}
