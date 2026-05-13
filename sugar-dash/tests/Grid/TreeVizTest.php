<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\TreeViz;
use SugarCraft\Dash\Grid\TreeVizNode;

final class TreeVizTest extends TestCase
{
    public function testNewCreatesTree(): void
    {
        $tree = TreeViz::new([]);
        $this->assertNotNull($tree);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $leaf1 = new TreeVizNode('Leaf A', 'leaf');
        $leaf2 = new TreeVizNode('Leaf B', 'leaf');
        $branch = new TreeVizNode('Branch', 'branch', [$leaf1, $leaf2]);
        $root = new TreeVizNode('Root', 'root', [$branch]);

        $tree = TreeViz::new([$root]);
        $rendered = $tree->render();
        $this->assertNotSame('', $rendered);
    }

    public function testSampleCreatesTree(): void
    {
        $tree = TreeViz::sample();
        $rendered = $tree->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $tree = TreeViz::sample();
        [$width, $height] = $tree->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithNodesReturnsNewInstance(): void
    {
        $tree = TreeViz::new([]);
        $leaf = new TreeVizNode('Leaf', 'leaf');
        $newTree = $tree->withNodes([$leaf]);
        $this->assertNotSame($tree, $newTree);
    }

    public function testWithShowLinesReturnsNewInstance(): void
    {
        $tree = TreeViz::new([]);
        $newTree = $tree->withShowLines(false);
        $this->assertNotSame($tree, $newTree);
    }

    public function testWithShowLabelsReturnsNewInstance(): void
    {
        $tree = TreeViz::new([]);
        $newTree = $tree->withShowLabels(false);
        $this->assertNotSame($tree, $newTree);
    }

    public function testEmptyNodesRendersEmpty(): void
    {
        $tree = TreeViz::new([]);
        $rendered = $tree->render();
        $this->assertSame('', $rendered);
    }
}
