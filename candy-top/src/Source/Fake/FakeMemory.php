<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see MemorySnapshot}s over a `$total`-byte machine with
 * a fixed 2 GiB swap.
 */
final class FakeMemory implements Source
{
    private const GIB = 1024 ** 3;

    private function __construct(
        private readonly int $total,
        private readonly int $step,
    ) {
    }

    public static function new(int $total = 16 * self::GIB): self
    {
        return new self(max(1, $total), 0);
    }

    public function sample(): array
    {
        $usedPct = 30.0 + Wave::percent($this->step, 0.7) * 0.4;
        $used = (int) ($this->total * $usedPct / 100);
        $cached = (int) ($this->total * 0.2);
        $available = $this->total - $used;
        $free = max(0, $available - $cached);
        $swapTotal = 2 * self::GIB;
        $swapUsed = (int) ($swapTotal * 0.1);
        $snapshot = new MemorySnapshot($this->total, $used, $available, $cached, $free, $swapTotal, $swapUsed, $swapTotal - $swapUsed);

        return [$snapshot, new self($this->total, $this->step + 1)];
    }
}
