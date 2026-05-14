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
        public readonly string $id = '',
        public readonly string $label = '',
        public readonly float $value = 0.0,
        public readonly bool $collapsed = false,
        public readonly array $children = [],
    ) {}

    /**
     * Create a new tree node with just a label.
     */
    public static function new(string $label): self
    {
        return new self(id: '', label: $label, value: 0.0, collapsed: false, children: []);
    }

    /**
     * Create a new tree node with id and label.
     */
    public static function withId(string $id, string $label): self
    {
        return new self(id: $id, label: $label, value: 0.0, collapsed: false, children: []);
    }

    /**
     * Create a collapsed tree node.
     */
    public static function collapsed(string $label): self
    {
        return new self(id: '', label: $label, value: 0.0, collapsed: true, children: []);
    }

    /**
     * Create a node with children.
     *
     * @param array<int, TreeNode> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
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
            id: $this->id,
            label: $this->label,
            value: $this->value,
            collapsed: $collapsed,
            children: $this->children,
        );
    }
}
