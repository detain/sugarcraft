<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One block device's throughput for a sample. Rates are bytes/second and
 * busy is the percent of the interval the device had IO in flight; all
 * three are Sentinel::UNMEASURED on the first sample for the device.
 */
final class DiskDevice
{
    public function __construct(
        public readonly string $name,
        public readonly float $readRate,
        public readonly float $writeRate,
        public readonly float $busy,
        public readonly int $readTotal,
        public readonly int $writeTotal,
    ) {
    }
}
