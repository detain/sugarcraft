<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One interface's counters for a sample. Rates are bytes/second over the
 * interval since the previous sample (Sentinel::UNMEASURED on the first
 * sample); totals are bytes since the collector started watching, minus
 * any user zeroing, and survive 32-bit counter wraps.
 */
final class NetInterface
{
    public function __construct(
        public readonly string $name,
        public readonly bool $connected,
        public readonly float $rxRate,
        public readonly float $txRate,
        public readonly int $rxTotal,
        public readonly int $txTotal,
        public readonly float $rxTop,
        public readonly float $txTop,
    ) {
    }
}
