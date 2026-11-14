<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\DrilldownTree;
use SugarCraft\Dashboard\Components\Tree\TreeNode;
use SugarCraft\Core\Util\Color;
use SugarCraft\Dashboard\Foundation\ColorMode;

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

    public function testWithPathInstallsExactAncestorChainAndLightsIt(): void
    {
        // D-54 (finding 047): breadcrumb and highlight are ONE stack now.
        $child2 = new TreeNode(id: 'child2', label: 'Two', value: 2.0);
        $child1 = (new TreeNode(id: 'child1', label: 'One', value: 1.0))->withChildren([$child2]);
        $root = (new TreeNode(id: 'root', label: 'Top', value: 0.0))->withChildren([$child1]);

        $tree = (new DrilldownTree(Color::ansi(1), Color::ansi(2)))->withRoot($root)->withPath('child2');

        $this->assertSame(['child1', 'child2'], $tree->navigationStack());
        $this->assertSame(['Top', 'One', 'Two'], $tree->breadcrumbItems());

        $selected = Color::ansi(2)->toFg(ColorMode::profile());
        $lines = explode("\n", $tree->render());
        $byLabel = [];
        foreach ($lines as $line) {
            foreach (['Top', 'One', 'Two'] as $label) {
                if (str_contains($line, $label)) {
                    $byLabel[$label] = $line;
                }
            }
        }
        $this->assertArrayHasKey('Two', $byLabel);
        $this->assertStringContainsString($selected, $byLabel['Two']);
        $this->assertStringContainsString($selected, $byLabel['One']);
        $this->assertStringContainsString($selected, $byLabel['Top']);
    }

    public function testPushAndPopMoveTheHighlightWithTheBreadcrumb(): void
    {
        $child2 = new TreeNode(id: 'child2', label: 'Two', value: 2.0);
        $child1 = (new TreeNode(id: 'child1', label: 'One', value: 1.0))->withChildren([$child2]);
        $root = (new TreeNode(id: 'root', label: 'Top', value: 0.0))->withChildren([$child1]);

        $tree = (new DrilldownTree(Color::ansi(1), Color::ansi(2)))->withRoot($root)
            ->pushNode('child1')->pushNode('child2');

        $selected = Color::ansi(2)->toFg(ColorMode::profile());
        $lines = explode("\n", $tree->render());
        $twoLine = '';
        $oneLine = '';
        foreach ($lines as $line) {
            if (str_contains($line, 'Two')) {
                $twoLine = $line;
            }
            if (str_contains($line, 'One')) {
                $oneLine = $line;
            }
        }
        $this->assertStringContainsString($selected, $twoLine, 'drilled leaf lights with the breadcrumb');
        $this->assertStringContainsString($selected, $oneLine);

        $back = $tree->popNode();
        $this->assertSame(['child1'], $back->navigationStack());
        foreach (explode("\n", $back->render()) as $line) {
            if (str_contains($line, 'Two')) {
                $this->assertStringNotContainsString($selected, $line, 'popped node loses the light');
            }
        }
    }

    public function testSiblingWithCommonPrefixIdStaysDark(): void
    {
        // The old prefix match lit the sibling whose id was a string prefix.
        $ab = new TreeNode(id: 'ab', label: 'AyeBee', value: 1.0);
        $abc = new TreeNode(id: 'abc', label: 'AyeBeeCee', value: 2.0);
        $root = (new TreeNode(id: 'root', label: 'Top', value: 0.0))->withChildren([$ab, $abc]);

        $tree = (new DrilldownTree(Color::ansi(1), Color::ansi(2)))->withRoot($root)->withPath('abc');

        $selected = Color::ansi(2)->toFg(ColorMode::profile());
        foreach (explode("\n", $tree->render()) as $line) {
            if (str_contains($line, 'AyeBee') && !str_contains($line, 'AyeBeeCee')) {
                $this->assertStringNotContainsString($selected, $line, 'prefix sibling must not light');
            }
        }
    }

    public function testLeafCarriesNoArrowWhileBranchDoes(): void
    {
        $leaf = new TreeNode(id: 'leaf', label: 'Leafy', value: 1.0);
        $branch = (new TreeNode(id: 'branch', label: 'Branchy', value: 2.0))
            ->withChildren([new TreeNode(id: 'kid', label: 'Kid', value: 1.0)]);
        $root = (new TreeNode(id: 'root', label: 'Top', value: 0.0))->withChildren([$branch, $leaf]);

        $tree = DrilldownTree::new($root);

        $lines = explode("\n", $tree->render());
        foreach ($lines as $line) {
            if (str_contains($line, 'Leafy') || str_contains($line, 'Kid')) {
                $this->assertStringNotContainsString('▶', $line, 'leaf rows carry no arrow');
                $this->assertStringNotContainsString('▼', $line, 'leaf rows carry no arrow');
            }
            if (str_contains($line, 'Branchy')) {
                $this->assertStringContainsString('▼ ', $line, 'expanded branch shows the down arrow');
            }
        }

        $collapsed = $tree->withExpanded(false);
        foreach (explode("\n", $collapsed->render()) as $line) {
            if (str_contains($line, 'Branchy')) {
                $this->assertStringContainsString('▶ ', $line, 'collapsed branch shows the right arrow');
            }
            if (str_contains($line, 'Leafy')) {
                $this->assertStringNotContainsString('▶', $line);
            }
        }
    }

    public function testWithPathUnknownIdClearsTheWholePath(): void
    {
        $child = new TreeNode(id: 'child', label: 'Kid', value: 1.0);
        $root = (new TreeNode(id: 'root', label: 'Top', value: 0.0))->withChildren([$child]);

        $tree = (new DrilldownTree(Color::ansi(1), Color::ansi(2)))->withRoot($root)
            ->pushNode('child')->withPath('nowhere');

        $this->assertSame([], $tree->navigationStack());

        $fresh = (new DrilldownTree(Color::ansi(1), Color::ansi(2)))->withRoot($root);
        $this->assertSame($fresh->render(), $tree->render(), 'cleared path renders like a fresh surface view');
    }

    public function testWithPathIsPure(): void
    {
        $child = new TreeNode(id: 'child', label: 'Kid', value: 1.0);
        $root = (new TreeNode(id: 'root', label: 'Top', value: 0.0))->withChildren([$child]);
        $original = DrilldownTree::new($root);

        $changed = $original->withPath('child');

        $this->assertSame([], $original->navigationStack(), 'withPath must not touch the receiver');
        $this->assertSame(['child'], $changed->navigationStack());
    }
}
