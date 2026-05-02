<?php

declare(strict_types=1);

namespace CandyCore\Charts\BarChart;

/**
 * One labelled value for a {@see BarChart}.
 */
final class Bar
{
    public function __construct(
        public readonly string $label,
        public readonly float $value,
    ) {}
}
