<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Tree\DrilldownTree;
use SugarCraft\Dash\Components\Tree\TreeNode;

final class DrilldownTreeTest extends TestCase
{
    public function testDrilldownTreeBreadcrumb(): void
    {
        $child2 = new TreeNode(id: 'child2', label: 'Child 2', value: 2.0);
        $child1 = new TreeNode(id: 'child1', label: 'Child 1', value: 1.0);
        $child1WithChildren = $child1->withChildren([$child2]);
        $root = new TreeNode(id: 'root', label: 'Root', value: 0.0);
        $rootWithChildren = $root->withChildren([$child1WithChildren]);

        $tree = DrilldownTree::new($rootWithChildren)
            ->pushNode('child1')
            ->pushNode('child2');

        $items = $tree->getBreadcrumbItems();
        $this->assertCount(3, $items);
        $this->assertSame('Root', $items[0]);
        $this->assertSame('Child 1', $items[1]);
        $this->assertSame('Child 2', $items[2]);
    }

    public function testDrilldownTreeOnEnterOnExitLifecycle(): void
    {
        $entered = [];
        $exited = [];

        $child = new TreeNode(id: 'child', label: 'Child', value: 1.0);
        $root = new TreeNode(id: 'root', label: 'Root', value: 0.0);
        $rootWithChild = $root->withChildren([$child]);

        $tree = DrilldownTree::new($rootWithChild)
            ->withOnEnter(function ($nodeId) use (&$entered) {
                $entered[] = $nodeId;
            })
            ->withOnExit(function ($nodeId) use (&$exited) {
                $exited[] = $nodeId;
            })
            ->pushNode('child');

        $this->assertSame(['child'], $entered);
        $this->assertEmpty($exited);

        $tree = $tree->popNode();

        $this->assertSame(['child'], $entered);
        $this->assertSame(['child'], $exited);
    }

    public function testDrilldownTreeNavigationStack(): void
    {
        $child2 = new TreeNode(id: 'child2', label: 'Child 2', value: 2.0);
        $child1 = new TreeNode(id: 'child1', label: 'Child 1', value: 1.0);
        $child1WithChildren = $child1->withChildren([$child2]);
        $root = new TreeNode(id: 'root', label: 'Root', value: 0.0);
        $rootWithChildren = $root->withChildren([$child1WithChildren]);

        $tree = DrilldownTree::new($rootWithChildren)
            ->pushNode('child1')
            ->pushNode('child2');

        $stack = $tree->getNavigationStack();
        $this->assertCount(2, $stack);
        $this->assertSame('child1', $stack[0]);
        $this->assertSame('child2', $stack[1]);
    }

    public function testDrilldownTreePopEmptyStack(): void
    {
        $root = new TreeNode(id: 'root', label: 'Root', value: 0.0);
        $tree = DrilldownTree::new($root);

        // Popping from empty stack should not cause errors
        $tree = $tree->popNode();
        $this->assertEmpty($tree->getNavigationStack());
    }
}
