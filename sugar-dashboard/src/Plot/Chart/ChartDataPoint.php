<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot\Chart;

/**
 * Data point for charts.
 */
final readonly class ChartDataPoint
{
    public function __construct(
        public string $label,
        public float $value,
    ) {}
}
