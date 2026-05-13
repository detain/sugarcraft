<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Color;

final class TreemapLeaf
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly float $value,
        public readonly ?Color $color = null,
        /** @var list<TreemapLeaf> */
        public readonly array $children = [],
    ) {}

    public function withColor(?Color $color): self
    {
        return new self(
            $this->id,
            $this->label,
            $this->value,
            $color,
            $this->children,
        );
    }

    /**
     * @param list<TreemapLeaf> $children
     */
    public function withChildren(array $children): self
    {
        return new self(
            $this->id,
            $this->label,
            $this->value,
            $this->color,
            $children,
        );
    }
}
