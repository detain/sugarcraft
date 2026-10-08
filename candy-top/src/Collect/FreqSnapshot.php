<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * CPU frequency in MHz. `mhz` is the freq_mode-collapsed figure (for
 * Range, the lowest; `label` carries "min - max"). min/max are the
 * scaling limits of the first policy. `boost` is null when the platform
 * has no global boost switch.
 */
final class FreqSnapshot
{
    /**
     * @param list<float> $cores per-policy current MHz
     */
    public function __construct(
        public readonly float $mhz,
        public readonly array $cores,
        public readonly float $minMhz,
        public readonly float $maxMhz,
        public readonly ?bool $boost,
        public readonly string $label,
    ) {
    }
}
