<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\Tree;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Components\Tree\ArrayTreeProvider;
use SugarCraft\Dashboard\Components\Tree\TreeNode;

/**
 * Finding 003 — roots()/children() filtered the ENTIRE node map per call
 * with a per-key in_array (O(N·k) — and the Tree render path re-ran it per
 * node, O(N²) per repaint), and depth() scanned the whole childrenMap on
 * every hop. The rewrite serves all of them from constructor-built
 * indexes; these pins capture the exact pre-rewrite observable behavior
 * (they were written against the scan implementation and must stay green
 * byte-equal across the rewrite):
 *
 *   - children/roots are served in NODE-INSERTION order, not childrenMap
 *     order (array_filter walked the node map);
 *   - ids in a child list that are not present in the node map are
 *     dropped; duplicates within a list collapse to one;
 *   - hasChildren() answers from the RAW map (true even when every listed
 *     child is unknown);
 *   - depth() walks the FIRST declaring parent in childrenMap order and
 *     answers 0 for roots and unknown ids.
 */
final class ArrayTreeProviderTest extends TestCase
{
    /** @param list<TreeNode> $nodes @param array<string,list<string>> $map */
    private static function provider(array $nodes, array $map, array $rootIds = []): ArrayTreeProvider
    {
        return new ArrayTreeProvider($nodes, $map, $rootIds);
    }

    private static function ids(array $nodes): array
    {
        return array_map(static fn (TreeNode $n): string => $n->id, $nodes);
    }

    public function testRootsServesDeclaredRootsInNodeInsertionOrder(): void
    {
        $p = self::provider(
            [new TreeNode(id: 'a'), new TreeNode(id: 'b'), new TreeNode(id: 'c')],
            ['a' => ['c'], 'b' => []],
            ['b', 'a'],
        );

        self::assertSame(['a', 'b'], self::ids($p->roots()), 'insertion order wins over rootIds order');
    }

    public function testRootsDefaultsToChildrenMapKeys(): void
    {
        $p = self::provider(
            [new TreeNode(id: 'a'), new TreeNode(id: 'b'), new TreeNode(id: 'c')],
            ['c' => ['a'], 'b' => []],
        );

        self::assertSame(['b', 'c'], self::ids($p->roots()));
    }

    public function testChildrenServesFromMapInNodeInsertionOrder(): void
    {
        $p = self::provider(
            [new TreeNode(id: 'a'), new TreeNode(id: 'b'), new TreeNode(id: 'c')],
            ['r' => ['c', 'a']],
            ['r'],
        );

        self::assertSame(['a', 'c'], self::ids($p->children('r')), 'node insertion order, not childrenMap order');
    }

    public function testChildrenDropsUnknownIdsAndDuplicates(): void
    {
        $p = self::provider(
            [new TreeNode(id: 'a'), new TreeNode(id: 'b')],
            ['r' => ['b', 'ghost', 'b', 'a']],
        );

        self::assertSame(['a', 'b'], self::ids($p->children('r')));
    }

    public function testChildrenOfUnknownOrLeaflessParentIsEmpty(): void
    {
        $p = self::provider([new TreeNode(id: 'a')], ['a' => []]);

        self::assertSame([], self::ids($p->children('nope')));
        self::assertSame([], self::ids($p->children('a')));
    }

    public function testHasChildrenAnswersFromRawMapEvenWhenAllListedChildrenAreUnknown(): void
    {
        $p = self::provider([new TreeNode(id: 'a')], ['a' => ['ghost']]);

        self::assertTrue($p->hasChildren('a'));
        self::assertFalse($p->hasChildren('b'));
    }

    public function testNodeAndTotalValue(): void
    {
        $p = self::provider(
            [new TreeNode(id: 'a', value: 1.5), new TreeNode(id: 'b', value: 2.25)],
            ['a' => ['b']],
        );

        self::assertSame('b', $p->node('b')?->id);
        self::assertNull($p->node('ghost'));
        self::assertSame(3.75, $p->totalValue(), 'sums EVERY node, not just leaves');
    }

    public function testDepthWalksFirstDeclaringParentInMapOrder(): void
    {
        // 'm' is listed under 'z' first and also under 'root'; the walk
        // takes the FIRST declaring parent in childrenMap key order (z),
        // so m's chain is m -> z -> root.
        $p = self::provider(
            [new TreeNode(id: 'root'), new TreeNode(id: 'm'), new TreeNode(id: 'z'), new TreeNode(id: 'leaf')],
            ['z' => ['m'], 'm' => ['leaf'], 'root' => ['m', 'z']],
        );

        self::assertSame(0, $p->depth('root'));
        self::assertSame(1, $p->depth('z'));
        self::assertSame(2, $p->depth('m'), 'm -> z -> root via first declaring parent z');
        self::assertSame(3, $p->depth('leaf'));
        self::assertSame(0, $p->depth('ghost'));
    }

    public function testFiveThousandNodeFullTraversalIsLinearAndCorrect(): void
    {
        $nodes = [];
        $map = [];
        $nodes[] = new TreeNode(id: 'n0', value: 1.0);
        for ($i = 1; $i < 5000; $i++) {
            $nodes[] = new TreeNode(id: "n$i", value: 1.0);
            $map['n' . intdiv($i, 2)][] = "n$i";
        }

        $p = self::provider($nodes, $map, ['n0']);

        $t0 = microtime(true);
        $seen = 0;
        foreach ($p->roots() as $root) {
            $stack = [$root->id];
            while ($stack !== []) {
                $id = array_pop($stack);
                $seen++;
                foreach ($p->children($id) as $child) {
                    $stack[] = $child->id;
                }
            }
        }
        self::assertSame(13, $p->depth('n4096'), 'n4096 -> n2048 -> ... -> n0 (log2 chain of 13 hops)');
        $elapsed = microtime(true) - $t0;

        self::assertSame(5000, $seen);
        self::assertSame(5000.0, $p->totalValue());
        // The old per-call whole-map filter put a full traversal at ~N^2
        // comparisons (tens of seconds here); the indexed provider must
        // stay comfortably inside a CI-second envelope. Relative bound,
        // no absolute-ms pin: 5k nodes x (children + depth) < 2s.
        self::assertLessThan(2.0, $elapsed, sprintf('5k full traversal took %.3fs', $elapsed));
    }
}
