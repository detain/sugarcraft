<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * A mind map node with optional children.
 */
final class MindMapNode
{
    /** @var list<MindMapNode> */
    public array $children = [];

    public function __construct(
        public readonly string $text,
        public readonly ?Color $color = null,
        public readonly ?string $icon = null,
    ) {}

    /**
     * Add a child node.
     */
    public function withChild(MindMapNode $child): self
    {
        $clone = clone $this;
        $clone->children[] = $child;
        return $clone;
    }

    /**
     * Create a new child node and add it.
     */
    public function addChild(string $text, ?Color $color = null, ?string $icon = null): self
    {
        $child = new MindMapNode($text, $color, $icon);
        return $this->withChild($child);
    }
}
