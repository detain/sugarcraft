<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\Cpu;
use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see CpuSnapshot}s: `$cores` cores on independent
 * phases, totals as their mean, a slowly drifting load average and an
 * uptime advancing by `$intervalSec` per sample.
 */
final class FakeCpu implements Source
{
    private function __construct(
        private readonly int $cores,
        private readonly int $step,
        private readonly float $intervalSec,
    ) {
    }

    public static function new(int $cores = 8, float $intervalSec = 2.0): self
    {
        return new self(max(1, $cores), 0, $intervalSec);
    }

    public function sample(): array
    {
        $cores = [];
        for ($i = 0; $i < $this->cores; $i++) {
            $cores[] = Wave::percent($this->step, $i * 0.9);
        }
        $total = round(array_sum($cores) / count($cores), 1);
        $fields = array_fill_keys(Cpu::FIELDS, 0.0);
        $fields['user'] = round($total * 0.7, 1);
        $fields['system'] = round($total * 0.25, 1);
        $fields['iowait'] = round($total * 0.05, 1);
        $fields['idle'] = round(100.0 - $total, 1);
        $load = [
            round($this->cores * Wave::percent($this->step, 0.3) / 100, 2),
            round($this->cores * Wave::percent($this->step, 1.3) / 120, 2),
            round($this->cores * Wave::percent($this->step, 2.3) / 140, 2),
        ];
        $snapshot = new CpuSnapshot($total, $cores, $fields, $load, 86_400.0 + $this->step * $this->intervalSec);

        return [$snapshot, new self($this->cores, $this->step + 1, $this->intervalSec)];
    }
}
