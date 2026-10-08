<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * CPU frequency in MHz. `mhz` is the freq_mode-collapsed figure (for
 * Range, the lowest; `label` carries "min - max"). min/max are the
 * scaling limits of the first policy. `boost` is null when the platform
 * has no global boost switch.
 *
 * Per-core arrays (btop #1785) are keyed by logical cpu index and empty
 * unless the collector was asked for them (Freq::withPerCore); a cpu
 * whose reading failed holds Sentinel::UNMEASURED. Format with
 * Freq::label(). For a min/max-scaled history graph btop uses
 * maxValue = max − min and offset = −min per core.
 */
final class FreqSnapshot
{
    /**
     * @param list<float>        $cores      per-policy current MHz
     * @param array<int, float> $perCore    per-cpu current MHz
     * @param array<int, float> $perCoreMin per-cpu scaling_min_freq MHz
     * @param array<int, float> $perCoreMax per-cpu scaling_max_freq MHz
     */
    public function __construct(
        public readonly float $mhz,
        public readonly array $cores,
        public readonly float $minMhz,
        public readonly float $maxMhz,
        public readonly ?bool $boost,
        public readonly string $label,
        public readonly array $perCore = [],
        public readonly array $perCoreMin = [],
        public readonly array $perCoreMax = [],
    ) {
    }
}
