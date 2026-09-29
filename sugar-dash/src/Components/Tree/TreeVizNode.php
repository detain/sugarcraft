<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

/**
 * A node in a tree visualization.
 */
final readonly class TreeVizNode
{
    /**
     * @param list<TreeVizNode> $children
     */
    public function __construct(
        public string $label,
        public string $type = 'leaf',
        public array $children = [],
    ) {}

    /**
     * Create a copy with children.
     *
     * @param list<TreeVizNode> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            label: $this->label,
            type: $this->type,
            children: $children,
        );
    }
}
