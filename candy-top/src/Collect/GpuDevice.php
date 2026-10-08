<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One GPU as nvidia-smi reports it. Any column the driver answers with
 * "[N/A]"/"[Not Supported]" takes the matching sentinel.
 */
final class GpuDevice
{
    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly float $utilization,
        public readonly int $memUsed,
        public readonly int $memTotal,
        public readonly float $temp,
        public readonly float $watts,
    ) {
    }
}
