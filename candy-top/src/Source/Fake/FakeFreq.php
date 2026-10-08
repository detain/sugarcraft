<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\Freq;
use SugarCraft\Top\Collect\FreqSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see FreqSnapshot}s: a 3.4 GHz-ish aggregate drifting on
 * its own phase, 800..4600 MHz scaling limits, and — once
 * {@see withPerCore()} is on, as Freq::withPerCore — one value per core.
 */
final class FakeFreq implements Source
{
    private function __construct(
        private readonly int $cores,
        private readonly int $step,
        private readonly bool $perCore,
    ) {
    }

    public static function new(int $cores = 8, bool $perCore = false): self
    {
        return new self(max(1, $cores), 0, $perCore);
    }

    public function withPerCore(bool $perCore): self
    {
        return $perCore === $this->perCore ? $this : new self($this->cores, $this->step, $perCore);
    }

    public function perCore(): bool
    {
        return $this->perCore;
    }

    public function sample(): array
    {
        $mhz = 2400.0 + Wave::percent($this->step, 0.5) * 18.0;
        $per = $min = $max = [];
        if ($this->perCore) {
            for ($i = 0; $i < $this->cores; $i++) {
                $per[$i] = 800.0 + Wave::percent($this->step, $i * 0.7) * 38.0;
                $min[$i] = 800.0;
                $max[$i] = 4600.0;
            }
        }
        $snapshot = new FreqSnapshot($mhz, [$mhz], 800.0, 4600.0, true, Freq::label($mhz), $per, $min, $max);

        return [$snapshot, new self($this->cores, $this->step + 1, $this->perCore)];
    }
}
