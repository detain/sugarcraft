<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Heatmap;

use SugarCraft\Charts\Support\Finite;

/**
 * Single sample in a {@see Heatmap}'s grid. Mirrors ntcharts'
 * `HeatPoint`. Coordinates are 0-based; row 0 is the top of the grid.
 */
final class HeatPoint
{
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly float $value,
    ) {
        // Ingestion guard: a non-finite sample would defeat the value
        // range maths and palette indexing in Heatmap::sample().
        Finite::assert($value);
    }

    /** Convenience for integer values. Mirrors ntcharts' `NewHeatPointInt`. */
    public static function ofInt(int $x, int $y, int $value): self
    {
        return new self($x, $y, (float) $value);
    }
}
