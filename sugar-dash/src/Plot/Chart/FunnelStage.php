<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Color;

/**
 * A stage in a funnel chart.
 */
final readonly class FunnelStage
{
    public function __construct(
        public string $label,
        public float $value,
        public ?Color $color = null,
    ) {}

    /**
     * Create a copy with a different color.
     */
    public function withColor(?Color $color): self
    {
        return new FunnelStage(
            $this->label,
            $this->value,
            $color,
        );
    }
}
