<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * A node in a Sankey diagram.
 */
final class SankeyNode
{
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly float $value,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            $this->id,
            $this->label,
            $this->value,
            $color,
        );
    }
}
