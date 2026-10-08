<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Memory classes from /proc/meminfo (+ optional ZFS ARC).
 *
 * Mirrors aristocratos/btop Mem::collect (src/linux/btop_collect.cpp):
 *  - available = MemAvailable, else MemFree + Cached on pre-3.14 kernels;
 *  - cached = Cached only (Buffers are NOT folded in, matching btop rather
 *    than free(1));
 *  - with zfs_arc_cached (btop default true) the ARC size is added to
 *    cached, and the part above c_min to available — the ARC shrinks
 *    under pressure but never below c_min;
 *  - used = total − available, falling back to total − free when the
 *    adjusted available overshoots total (the ARC can briefly report more
 *    than physical RAM);
 *  - swap_used = SwapTotal − SwapFree;
 *  - zswap (btop #1739, Linux ≥ 5.19): `Zswap:` is the compressed pool
 *    held in RAM, `Zswapped:` the original size of the pages in it. Both
 *    land in the snapshot raw (UNMEASURED_INT when the kernel has no such
 *    lines); MemorySnapshot::swapUsedOnDisk() gives btop's show_zswap
 *    "Used" (swap_used − Zswapped). `swapUsed` itself stays the plain
 *    SwapTotal − SwapFree figure so the choice is the view's (show_zswap).
 *
 * Stateless: sample() returns $this as the next collector.
 */
final class Memory
{
    private function __construct(
        private readonly Paths $paths,
        private readonly bool $zfsArcCached,
    ) {
    }

    public static function new(?Paths $paths = null, bool $zfsArcCached = true): self
    {
        return new self($paths ?? Paths::system(), $zfsArcCached);
    }

    /**
     * @return array{0: MemorySnapshot, 1: self}
     */
    public function sample(): array
    {
        $raw = Read::file($this->paths->proc('meminfo'));
        if ($raw === null) {
            return [MemorySnapshot::unmeasured(), $this];
        }

        $kb = [];
        foreach (explode("\n", $raw) as $line) {
            if (preg_match('/^(\w+(?:\(\w+\))?):\s+(\d+)/', $line, $m) === 1) {
                $kb[$m[1]] = (int) $m[2] * 1024;
            }
        }

        $total = $kb['MemTotal'] ?? 0;
        if ($total <= 0) {
            return [MemorySnapshot::unmeasured(), $this];
        }

        $free = $kb['MemFree'] ?? 0;
        $cached = $kb['Cached'] ?? 0;
        $available = $kb['MemAvailable'] ?? ($free + $cached);

        if ($this->zfsArcCached) {
            [$arcSize, $arcMin] = $this->readArc();
            $cached += $arcSize;
            if ($arcSize > $arcMin) {
                $available += $arcSize - $arcMin;
            }
        }

        $used = $total - ($available <= $total ? $available : $free);
        $swapTotal = $kb['SwapTotal'] ?? 0;
        $swapFree = $kb['SwapFree'] ?? 0;

        return [
            new MemorySnapshot(
                $total,
                $used,
                $available,
                $cached,
                $free,
                $swapTotal,
                $swapTotal > 0 ? $swapTotal - $swapFree : 0,
                $swapTotal > 0 ? $swapFree : 0,
                $kb['Zswap'] ?? Sentinel::UNMEASURED_INT,
                $kb['Zswapped'] ?? Sentinel::UNMEASURED_INT,
            ),
            $this,
        ];
    }

    /**
     * @return array{0: int, 1: int} [size, c_min] in bytes; zeros when ZFS is absent
     */
    private function readArc(): array
    {
        $raw = Read::file($this->paths->proc('spl/kstat/zfs/arcstats'));
        if ($raw === null) {
            return [0, 0];
        }
        $size = 0;
        $min = 0;
        // "name type data" rows; the third column is the value.
        foreach (explode("\n", $raw) as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];
            if (count($cols) === 3 && ctype_digit($cols[2])) {
                if ($cols[0] === 'size') {
                    $size = (int) $cols[2];
                } elseif ($cols[0] === 'c_min') {
                    $min = (int) $cols[2];
                }
            }
        }

        return [$size, $min];
    }
}
