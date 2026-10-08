<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Per-device read/write throughput from /proc/diskstats.
 *
 * Mirrors aristocratos/btop Mem::collect's disk-IO block
 * (src/linux/btop_collect.cpp): sectors are always 512 bytes in the
 * kernel's accounting regardless of the device's logical block size, so
 * bytes = Δsectors × 512; the activity figure is Δio_ticks (ms the queue
 * was non-empty) over the elapsed wall time, clamped 0..100.
 *
 * Deviation: btop resolves IO per *mount* via /sys/block/<dev>/stat. Here
 * the unit is the *device*, because candy-top's disk panel pairs Mounts
 * (space) with DiskIo (throughput) and a device hosting several mounts
 * would otherwise be counted once per mount. Physical filtering (btop
 * `only_physical`, default on) keeps whole disks — names present under
 * /sys/block, so partitions drop — and removes the ones the kernel itself
 * files under /sys/devices/virtual/block (loop, ram, zram, dm-*, md*),
 * whose IO is already counted on the disks beneath them.
 */
final class DiskIo
{
    private const int SECTOR_BYTES = 512;

    /**
     * @param \Closure(): float                      $clock monotonic seconds
     * @param array<string, array{0: int, 1: int, 2: int}> $previous [sectors read, sectors written, io_ticks]
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly \Closure $clock,
        private readonly bool $physicalOnly,
        private readonly array $previous,
        private readonly ?float $lastAt,
    ) {
    }

    /**
     * @param (\Closure(): float)|null $clock defaults to hrtime-based monotonic seconds
     */
    public static function new(?Paths $paths = null, ?\Closure $clock = null, bool $physicalOnly = true): self
    {
        return new self($paths ?? Paths::system(), $clock ?? static fn (): float => hrtime(true) / 1e9, $physicalOnly, [], null);
    }

    /**
     * @return array{0: DiskIoSnapshot, 1: self}
     */
    public function sample(): array
    {
        $now = ($this->clock)();
        $raw = Read::file($this->paths->proc('diskstats'));
        if ($raw === null) {
            return [new DiskIoSnapshot([]), $this];
        }

        $elapsed = $this->lastAt === null ? 0.0 : $now - $this->lastAt;
        $previous = [];
        $devices = [];

        foreach (explode("\n", $raw) as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];
            if (count($cols) < 13) {
                continue;
            }
            $name = $cols[2];
            if ($this->physicalOnly && !$this->isPhysical($name)) {
                continue;
            }

            $current = [(int) $cols[5], (int) $cols[9], (int) $cols[12]];
            $previous[$name] = $current;
            $old = $this->previous[$name] ?? null;

            $readRate = $writeRate = $busy = Sentinel::UNMEASURED;
            if ($old !== null && $elapsed > 0.0) {
                // A counter reset (device re-attached) reads as 0, never negative.
                $readRate = max(0, $current[0] - $old[0]) * self::SECTOR_BYTES / $elapsed;
                $writeRate = max(0, $current[1] - $old[1]) * self::SECTOR_BYTES / $elapsed;
                $busy = max(0.0, min(100.0, max(0, $current[2] - $old[2]) / ($elapsed * 1000.0) * 100.0));
            }

            $devices[$name] = new DiskDevice(
                $name,
                $readRate,
                $writeRate,
                $busy,
                $current[0] * self::SECTOR_BYTES,
                $current[1] * self::SECTOR_BYTES,
            );
        }

        return [new DiskIoSnapshot($devices), new self($this->paths, $this->clock, $this->physicalOnly, $previous, $now)];
    }

    private function isPhysical(string $name): bool
    {
        return is_dir($this->paths->sys("block/{$name}"))
            && !is_dir($this->paths->sys("devices/virtual/block/{$name}"));
    }
}
