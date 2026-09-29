<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Color;

/**
 * A flow connection between two nodes in a Sankey diagram.
 */
final class SankeyFlow
{
    public function __construct(
        public readonly string $source,
        public readonly string $target,
        public readonly float $value,
        public readonly ?Color $color = null,
    ) {}

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            $this->source,
            $this->target,
            $this->value,
            $color,
        );
    }
}
