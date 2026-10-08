<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * btop's memory classes in bytes. Every field is Sentinel::UNMEASURED_INT
 * when /proc/meminfo could not be read; swap fields are 0 on a swapless
 * host (that is a measurement, not a gap).
 */
final class MemorySnapshot
{
    public function __construct(
        public readonly int $total,
        public readonly int $used,
        public readonly int $available,
        public readonly int $cached,
        public readonly int $free,
        public readonly int $swapTotal,
        public readonly int $swapUsed,
        public readonly int $swapFree,
    ) {
    }

    public static function unmeasured(): self
    {
        $u = Sentinel::UNMEASURED_INT;

        return new self($u, $u, $u, $u, $u, $u, $u, $u);
    }

    public function measured(): bool
    {
        return $this->total > 0;
    }

    /**
     * Percent of MemTotal for a class ('used', 'available', 'cached',
     * 'free'), rounded the way btop rounds; UNMEASURED when unknown.
     */
    public function percent(string $class): float
    {
        $value = match ($class) {
            'used' => $this->used,
            'available' => $this->available,
            'cached' => $this->cached,
            'free' => $this->free,
            default => Sentinel::UNMEASURED_INT,
        };

        return $this->total > 0 && $value >= 0 ? round($value * 100.0 / $this->total) : Sentinel::UNMEASURED;
    }

    /** Percent of SwapTotal for 'swap_used' / 'swap_free'; UNMEASURED without swap. */
    public function swapPercent(string $class): float
    {
        $value = $class === 'swap_used' ? $this->swapUsed : ($class === 'swap_free' ? $this->swapFree : -1);

        return $this->swapTotal > 0 && $value >= 0 ? round($value * 100.0 / $this->swapTotal) : Sentinel::UNMEASURED;
    }
}
