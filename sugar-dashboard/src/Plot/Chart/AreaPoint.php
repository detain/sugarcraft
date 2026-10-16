<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot\Chart;

final readonly class AreaPoint
{
    public function __construct(
        public string $label,
        public float $value,
        public ?float $y0 = null,
    ) {}
}
