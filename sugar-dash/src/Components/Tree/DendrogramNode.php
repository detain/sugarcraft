<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

final class DendrogramNode
{
    /** @var list<DendrogramNode> */
    public array $children = [];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly float $value = 0.0,
        public readonly ?Color $color = null,
    ) {}

    /**
     * @param list<DendrogramNode> $children
     */
    public function withChildren(array $children): self
    {
        $clone = clone $this;
        $clone->children = $children;
        return $clone;
    }

    public function withColor(?Color $color): self
    {
        $clone = new self($this->id, $this->label, $this->value, $color);
        $clone->children = $this->children;
        return $clone;
    }

    public function getTotalValue(): float
    {
        $total = $this->value;
        foreach ($this->children as $child) {
            $total += $child->getTotalValue();
        }
        return $total;
    }

    public function getDepth(): int
    {
        $maxChildDepth = 0;
        foreach ($this->children as $child) {
            $maxChildDepth = max($maxChildDepth, $child->getDepth());
        }
        return 1 + $maxChildDepth;
    }
}
