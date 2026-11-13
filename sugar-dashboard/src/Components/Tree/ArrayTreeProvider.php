<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\Tree;

/**
 * Basic tree data provider implementation.
 *
 * Every relation is indexed ONCE in the constructor — the node list and
 * the children map are immutable after `new` — so roots(), children()
 * and depth() serve in O(result) / O(chain) instead of re-filtering the
 * whole node map per call. The previous scans ran once per rendered node
 * (O(N²) per repaint, finding 003).
 *
 * Observable semantics (pinned by ArrayTreeProviderTest):
 *   - children()/roots() serve in NODE-INSERTION order, not the order a
 *     child list happens to declare;
 *   - ids missing from the node map are dropped from served lists, and
 *     duplicates inside a child list collapse to one entry;
 *   - hasChildren() answers from the RAW children map — it reports true
 *     for a parent whose every listed child is unknown, matching the
 *     pre-indexing behavior;
 *   - depth() follows the FIRST declaring parent in childrenMap key
 *     order and answers 0 for roots and unknown ids. Cyclic maps used to
 *     spin forever; the memo walk carries a cycle guard and stops.
 */
final class ArrayTreeProvider implements TreeProvider
{
    /** @var array<string, TreeNode> */
    private array $nodes = [];

    /** @var array<string, list<string>> raw parent => declared child ids (hasChildren seat) */
    private array $childrenMap = [];

    /** @var array<string, list<string>> parent => served child ids, insertion-ordered, known-only, deduped */
    private array $childrenIndex = [];

    /** @var array<string, string> child id => first declaring parent */
    private array $parentOf = [];

    /** @var list<string> node-insertion-ordered root ids */
    private array $rootIndex = [];

    /** @var array<string, int> lazily memoized depth per node id */
    private array $depthCache = [];

    private float $totalValue = 0.0;

    /**
     * @param list<TreeNode> $nodes
     * @param array<string, list<string>> $childrenMap
     * @param list<string> $rootIds
     */
    public function __construct(array $nodes, array $childrenMap, array $rootIds = []) {
        $insertOrder = [];
        foreach ($nodes as $node) {
            $this->nodes[$node->id] = $node;
            $insertOrder[$node->id] = count($insertOrder);
            $this->totalValue += $node->value;
        }

        $this->childrenMap = $childrenMap;

        foreach ($childrenMap as $parent => $declared) {
            $known = [];
            $seen = [];
            foreach ($declared as $childId) {
                if (!isset($this->nodes[$childId]) || isset($seen[$childId])) {
                    continue;
                }
                $seen[$childId] = true;
                $known[] = (string) $childId;
                $this->parentOf[$childId] ??= (string) $parent;
            }
            // The pre-indexing filter walked the NODE map, so served
            // order is insertion order even when the declared list
            // disagrees. Preserve that byte-for-byte.
            usort($known, static fn (string $a, string $b): int => $insertOrder[$a] <=> $insertOrder[$b]);
            $this->childrenIndex[$parent] = $known;
        }

        $rootSet = array_flip($rootIds !== [] ? $rootIds : array_keys($childrenMap));
        foreach ($this->nodes as $id => $_) {
            if (isset($rootSet[$id])) {
                $this->rootIndex[] = (string) $id;
            }
        }
    }

    /**
     * @return list<TreeNode>
     */
    public function roots(): array
    {
        return array_map(
            fn (string $id): TreeNode => $this->nodes[$id],
            $this->rootIndex
        );
    }

    /**
     * @return list<TreeNode>
     */
    public function children(string $nodeId): array
    {
        return array_map(
            fn (string $id): TreeNode => $this->nodes[$id],
            $this->childrenIndex[$nodeId] ?? []
        );
    }

    public function node(string $nodeId): ?TreeNode
    {
        return $this->nodes[$nodeId] ?? null;
    }

    public function hasChildren(string $nodeId): bool
    {
        // Answers from the RAW map on purpose: a parent whose listed
        // children are all unknown still reports true (served list is
        // empty). Pinned pre/post by ArrayTreeProviderTest.
        return !empty($this->childrenMap[$nodeId] ?? []);
    }

    public function depth(string $nodeId): int
    {
        if (isset($this->depthCache[$nodeId])) {
            return $this->depthCache[$nodeId];
        }

        $chain = [];
        $current = $nodeId;
        while (true) {
            if (isset($this->depthCache[$current])) {
                break;
            }
            $parent = $this->parentOf[$current] ?? null;
            if ($parent === null || isset($chain[$current])) {
                // root, unknown id, or cycle anchor: base depth 0
                $this->depthCache[$current] = 0;
                break;
            }
            $chain[$current] = true;
            $current = $parent;
        }

        $depth = $this->depthCache[$current];
        foreach (array_reverse(array_keys($chain)) as $id) {
            $this->depthCache[$id] = ++$depth;
        }

        return $this->depthCache[$nodeId];
    }

    public function totalValue(): float
    {
        return $this->totalValue;
    }
}
