<?php

declare(strict_types=1);

namespace SugarCraft\Charts\BarChart;

use SugarCraft\Charts\Support\Finite;

/**
 * One labelled value for a {@see BarChart}.
 */
final class Bar
{
    public function __construct(
        public readonly string $label,
        public readonly float $value,
    ) {
        // Ingestion guard: a non-finite bar value would defeat the
        // min/max range maths in BarChart::renderChart().
        Finite::assert($value);
    }
}
