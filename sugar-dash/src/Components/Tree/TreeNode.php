<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * A node in the tree structure.
 */
final class TreeNode
{
    /**
     * @param array<int, TreeNode> $children
     */
    public function __construct(
        public readonly string $label,
        public readonly bool $collapsed = false,
        public readonly array $children = [],
    ) {}

    /**
     * Create a new tree node.
     */
    public static function new(string $label): self
    {
        return new self(label: $label, collapsed: false, children: []);
    }

    /**
     * Create a collapsed tree node.
     */
    public static function collapsed(string $label): self
    {
        return new self(label: $label, collapsed: true, children: []);
    }

    /**
     * Create a node with children.
     *
     * @param array<int, TreeNode> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            label: $this->label,
            collapsed: $this->collapsed,
            children: $children,
        );
    }

    /**
     * Set the collapsed state.
     */
    public function withCollapsed(bool $collapsed): self
    {
        return new self(
            label: $this->label,
            collapsed: $collapsed,
            children: $this->children,
        );
    }
}