<?php

declare(strict_types=1);

namespace SugarCraft\Charts\BarChart;

use SugarCraft\Charts\Support\Finite;
use SugarCraft\Charts\Support\UntrustedText;

/**
 * One labeled value for a {@see BarChart}.
 */
final class Bar
{
    public readonly string $label;

    public function __construct(
        string $label,
        public readonly float $value,
    ) {
        // Ingestion guard: labels reach the frame verbatim; sweep them at
        // construction (idempotent, byte-identical on clean text).
        $this->label = UntrustedText::guard($label);
        // Ingestion guard: a non-finite bar value would defeat the
        // min/max range maths in BarChart::renderChart().
        Finite::assert($value);
    }
}
