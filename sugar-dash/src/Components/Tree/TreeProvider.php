<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

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
