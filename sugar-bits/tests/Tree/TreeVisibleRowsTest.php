<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Tree;

use SugarCraft\Bits\Tree\Node;
use SugarCraft\Bits\Tree\Tree;
use PHPUnit\Framework\TestCase as FrameworkTestCase;

/**
 * Pins the E736/3.2 visibleRows memo, the E736/4.2 shared path buffer
 * (copy-on-write snapshots per row), and the E736/4.1 pure updateAt —
 * toggling a node never mutates the original tree.
 */
final class TreeVisibleRowsTest extends FrameworkTestCase
{
    private function nested(): Tree
    {
        return Tree::new(
            Node::branch('src',
                Node::branch('inner',
                    Node::leaf('deep.txt'),
                )->withExpanded(true),
                Node::leaf('top.txt'),
            )->withExpanded(true),
            Node::leaf('README'),
        )->withSize(40, 10);
    }

    public function testRepeatedVisibleRowsAreIdentical(): void
    {
        $t = $this->nested();
        $this->assertSame($t->visibleRows(), $t->visibleRows(), 'memoized walk must be deterministic');
    }

    public function testPathsSnapshotEachDepth(): void
    {
        $paths = array_map(
            static fn(array $row): array => $row['path'],
            $this->nested()->visibleRows(),
        );
        // Shared-buffer bug would alias every row to the last [0,0,0] walk.
        $this->assertSame([[0], [0, 0], [0, 0, 0], [0, 1], [1]], $paths);
    }

    public function testCollapseRebuildsProjectionAndLeavesOriginalIntact(): void
    {
        $tree = $this->nested();
        [$focused, ] = $tree->focus();
        $collapsed = $focused->collapseAtCursor(); // cursor 0 = 'src'

        $this->assertCount(5, $tree->visibleRows(), 'original tree must never mutate (E736/4.1)');
        $this->assertSame($tree->view(), $tree->view());
        $this->assertCount(2, $collapsed->visibleRows());
        $this->assertStringNotContainsString('deep.txt', $collapsed->view(), 'stale cache would still show descendants');
        $this->assertStringNotContainsString('top.txt', $collapsed->view(), 'collapsing src hides its whole subtree');
        $rows = $collapsed->visibleRows();
        $this->assertSame([0], $rows[0]['path'], 'surviving root keeps its pristine path (COW snapshot)');
        $this->assertSame([1], $rows[1]['path'], 'second root must not inherit the first subtree walk');
    }

    public function testDeepExpandKeepsSiblingsAndPathsValid(): void
    {
        $tree = $this->nested();
        [$focused, ] = $tree->focus();
        $open = $focused->cursorDown(); // down to 'inner' (index 1)
        $this->assertSame('inner', $open->selectedNode()?->label);
        $reopened = $open->expandAtCursor();          // no-op: already expanded
        $this->assertCount(5, $reopened->visibleRows());

        $closed = $open->collapseAtCursor();
        $this->assertSame('inner', $closed->selectedNode()?->label, 'sibling subtree of the toggled node survives');
        $this->assertCount(4, $closed->visibleRows());
        $this->assertStringNotContainsString('deep.txt', $closed->view());
        $this->assertStringContainsString('top.txt', $closed->view());
        $this->assertSame(
            [[0], [0, 0], [0, 1], [1]],
            array_map(static fn(array $r): array => $r['path'], $closed->visibleRows()),
            'sibling paths must rewind independently of the collapsed subtree walk',
        );
    }

    public function testSetRootsRebuildsProjection(): void
    {
        $tree = $this->nested();
        $swapped = $tree->setRoots(Node::leaf('other'));
        $this->assertCount(1, $swapped->visibleRows());
        $this->assertSame('other', $swapped->selectedValue() ?? $swapped->selectedNode()?->label);
        $this->assertCount(5, $tree->visibleRows(), 'original untouched');
    }
}
