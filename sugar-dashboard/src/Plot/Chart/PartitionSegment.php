<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot\Chart;

use SugarCraft\Core\Util\Color;

final class PartitionSegment
{
    /** @var list<PartitionSegment> */
    public array $children = [];

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly float $value,
        public readonly ?Color $color = null,
    ) {}

    /**
     * @param list<PartitionSegment> $children
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

    public function totalValue(): float
    {
        $total = $this->value;
        foreach ($this->children as $child) {
            $total += $child->totalValue();
        }
        return $total;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::totalValue()} instead. REMOVED-IN: 2.0.0.
     */
    public function getTotalValue(): float { return $this->totalValue(); }

    public function depth(): int
    {
        $maxChildDepth = 0;
        foreach ($this->children as $child) {
            $maxChildDepth = max($maxChildDepth, $child->depth());
        }
        return 1 + $maxChildDepth;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::depth()} instead. REMOVED-IN: 2.0.0.
     */
    public function getDepth(): int { return $this->depth(); }
}