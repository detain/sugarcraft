<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Color;

final class BubblePoint
{
    public function __construct(
        public readonly string $label,
        public readonly float $x,
        public readonly float $y,
        public readonly float $size,
        public readonly ?Color $color = null,
        public readonly ?string $category = null,
    ) {}

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            $this->label,
            $this->x,
            $this->y,
            $this->size,
            $color,
            $this->category,
        );
    }
}
