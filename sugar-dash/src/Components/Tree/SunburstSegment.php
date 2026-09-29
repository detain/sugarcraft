<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * A segment in a Sunburst chart.
 */
final class SunburstSegment
{
    /** @var list<SunburstSegment> */
    public array $children = [];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly float $value,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a copy with children.
     *
     * @param list<SunburstSegment> $children
     */
    public function withChildren(array $children): self
    {
        $clone = clone $this;
        $clone->children = $children;
        return $clone;
    }

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return (new self(
            id: $this->id,
            label: $this->label,
            value: $this->value,
            color: $color,
        ))->withChildren($this->children);
    }

    /**
     * Calculate total value including children.
     */
    public function getTotalValue(): float
    {
        $total = $this->value;
        foreach ($this->children as $child) {
            $total += $child->getTotalValue();
        }
        return $total;
    }
}
