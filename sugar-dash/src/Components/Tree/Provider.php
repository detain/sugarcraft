<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * Interface for tree data providers.
 *
 * Implementations of this interface provide tree data
 * (nodes, values, labels) for tree visualization components.
 */
interface TreeProvider
{
    /**
     * Get the root node(s) of the tree.
     *
     * @return list<TreeNode>
     */
    public function getRoots(): array;

    /**
     * Get children of a specific node.
     *
     * @param string $nodeId
     * @return list<TreeNode>
     */
    public function getChildren(string $nodeId): array;

    /**
     * Get a node by its ID.
     */
    public function getNode(string $nodeId): ?TreeNode;

    /**
     * Check if a node has children.
     */
    public function hasChildren(string $nodeId): bool;

    /**
     * Get the depth of a node in the tree.
     */
    public function getDepth(string $nodeId): int;

    /**
     * Get total value of all nodes (for proportional sizing).
     */
    public function getTotalValue(): float;
}

/**
 * Basic tree data provider implementation.
 */
final class ArrayTreeProvider implements TreeProvider
{
    /** @var array<string, TreeNode> */
    private array $nodes = [];

    /** @var array<string, list<string>> */
    private array $childrenMap = [];

    /** @var list<string> */
    private array $rootIds = [];

    /**
     * @param list<TreeNode> $nodes
     * @param array<string, list<string>> $childrenMap
     * @param list<string> $rootIds
     */
    public function __construct(array $nodes, array $childrenMap, array $rootIds = []) {
        foreach ($nodes as $node) {
            $this->nodes[$node->id] = $node;
        }
        $this->childrenMap = $childrenMap;
        $this->rootIds = $rootIds ?: array_keys($childrenMap);
    }

    /**
     * @return list<TreeNode>
     */
    public function getRoots(): array
    {
        return array_values(array_filter(
            $this->nodes,
            fn(string $id) => in_array($id, $this->rootIds, true),
            ARRAY_FILTER_USE_KEY
        ));
    }

    /**
     * @return list<TreeNode>
     */
    public function getChildren(string $nodeId): array
    {
        $childIds = $this->childrenMap[$nodeId] ?? [];
        return array_values(array_filter(
            $this->nodes,
            fn(string $id) => in_array($id, $childIds, true),
            ARRAY_FILTER_USE_KEY
        ));
    }

    public function getNode(string $nodeId): ?TreeNode
    {
        return $this->nodes[$nodeId] ?? null;
    }

    public function hasChildren(string $nodeId): bool
    {
        return !empty($this->childrenMap[$nodeId] ?? []);
    }

    public function getDepth(string $nodeId): int
    {
        $depth = 0;
        $currentId = $nodeId;

        while ($currentId !== null) {
            $parentId = null;
            foreach ($this->childrenMap as $parent => $children) {
                if (in_array($currentId, $children, true)) {
                    $parentId = $parent;
                    break;
                }
            }
            if ($parentId === null) {
                break;
            }
            $currentId = $parentId;
            $depth++;
        }

        return $depth;
    }

    public function getTotalValue(): float
    {
        return array_sum(array_map(
            fn(TreeNode $node) => $node->value,
            $this->nodes
        ));
    }
}
