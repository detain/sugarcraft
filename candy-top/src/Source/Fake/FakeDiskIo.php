<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\DiskDevice;
use SugarCraft\Top\Collect\DiskIoSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see DiskIoSnapshot}s matching {@see FakeMounts}' devices:
 * every partition gets its own read/write rate (bytes/s) and busy %, the
 * whole disks carry the sums, totals accumulate per `$intervalSec`. The
 * EFI partition is idle, so its graphs stay flat (the `R`/`W` labels).
 */
final class FakeDiskIo implements Source
{
    private const MIB = 1024 ** 2;

    /** partition => [disk, read MiB/s at 100 %, write MiB/s at 100 %, phase] */
    private const PARTS = [
        'nvme0n1p1' => ['nvme0n1', 0.0, 0.0, 0.0],
        'nvme0n1p2' => ['nvme0n1', 48.0, 22.0, 0.3],
        'sda1' => ['sda', 12.0, 6.0, 1.9],
        'sdb1' => ['sdb', 2.0, 30.0, 3.3],
    ];

    /**
     * @param array<string, array{0: int, 1: int}> $totals per device [read bytes, written bytes]
     */
    private function __construct(
        private readonly int $step,
        private readonly array $totals,
        private readonly float $intervalSec,
    ) {
    }

    public static function new(float $intervalSec = 2.0): self
    {
        return new self(0, [], $intervalSec);
    }

    public function sample(): array
    {
        $rates = [];
        foreach (self::PARTS as $name => [$disk, $read, $write, $phase]) {
            $r = round(Wave::percent($this->step, $phase) / 100 * $read * self::MIB);
            $w = round(Wave::percent($this->step, $phase + 1.3) / 100 * $write * self::MIB);
            $busy = $read + $write > 0.0 ? round(min(100.0, Wave::percent($this->step, $phase + 0.6) * 0.8)) : 0.0;
            $rates[$name] = [$r, $w, $busy];
            [$dr, $dw, $db] = $rates[$disk] ?? [0.0, 0.0, 0.0];
            $rates[$disk] = [$dr + $r, $dw + $w, max($db, $busy)];
        }
        $totals = [];
        $devices = [];
        foreach ($rates as $name => [$r, $w, $busy]) {
            [$rt, $wt] = $this->totals[$name] ?? [0, 0];
            $totals[$name] = [$rt + (int) ($r * $this->intervalSec), $wt + (int) ($w * $this->intervalSec)];
            $devices[$name] = new DiskDevice($name, $r, $w, $busy, $totals[$name][0], $totals[$name][1]);
        }

        return [new DiskIoSnapshot($devices), new self($this->step + 1, $totals, $this->intervalSec)];
    }
}
