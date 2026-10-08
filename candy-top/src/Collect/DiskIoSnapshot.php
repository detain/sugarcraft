<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

final class DiskIoSnapshot
{
    /**
     * @param array<string, DiskDevice> $devices keyed by kernel name, /proc/diskstats order
     */
    public function __construct(
        public readonly array $devices,
    ) {
    }

    /** Sum of measured read rates; UNMEASURED when no device has a rate yet. */
    public function readRate(): float
    {
        return self::sum(array_map(static fn (DiskDevice $d): float => $d->readRate, $this->devices));
    }

    public function writeRate(): float
    {
        return self::sum(array_map(static fn (DiskDevice $d): float => $d->writeRate, $this->devices));
    }

    /** @param array<float> $rates */
    private static function sum(array $rates): float
    {
        $measured = array_filter($rates, static fn (float $r): bool => $r >= 0.0);

        return $measured === [] ? Sentinel::UNMEASURED : (float) array_sum($measured);
    }
}
