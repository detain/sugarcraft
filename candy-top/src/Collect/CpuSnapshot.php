<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One CPU sample: utilisation percentages over the interval since the
 * previous sample, plus the instantaneous load averages and uptime.
 *
 * Every percentage is 0..100 or Sentinel::UNMEASURED.
 */
final class CpuSnapshot
{
    /**
     * @param list<float>          $cores  per-core busy % indexed by cpu number
     * @param array<string, float> $fields per-field % of the interval, keyed by Cpu::FIELDS
     * @param array{0: float, 1: float, 2: float} $load 1/5/15-minute load averages
     */
    public function __construct(
        public readonly float $total,
        public readonly array $cores,
        public readonly array $fields,
        public readonly array $load,
        public readonly float $uptime,
    ) {
    }

    public static function unmeasured(array $load, float $uptime): self
    {
        return new self(Sentinel::UNMEASURED, [], [], $load, $uptime);
    }

    public function coreCount(): int
    {
        return count($this->cores);
    }
}
