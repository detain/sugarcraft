<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Collect\Sentinel;

/**
 * Memory classes from vm.stats page counters, plus swap from swapinfo(8).
 *
 * Mirrors aristocratos/btop Mem::collect (src/freebsd/btop_collect.cpp)
 * with the four open FreeBSD upstream PRs folded in:
 *  - #1851: total = vm.stats.vm.v_page_count × hw.pagesize (the VM
 *    subsystem's own view, consistent with every other counter here),
 *    hw.physmem only as a fallback; laundry pages (dirty, queued for
 *    write-back) count as used;
 *  - #1728: v_cache_count has been 0 since FreeBSD 12, so cached =
 *    vfs.bufspace (UFS buffer cache) + the reclaimable ZFS ARC
 *    (arcstats.size − c_min); both live inside wired memory, so they are
 *    taken back out of used — the Linux semantic of cache-as-available;
 *  - #1851 / #1728 overflow: btop multiplied u_int page counts by the
 *    page size in 32 bits; PHP ints are 64-bit, nothing to do but say so.
 *
 *    used      = active + laundry + max(0, wired − cached)
 *    available = total − used
 *    free      = v_free_count × pagesize
 *
 * `zfs_arc_cached` off leaves the ARC out of cached (it then stays in
 * wired, i.e. used), as on Linux. An absent OID (no ZFS → no arcstats; no
 * bufspace) reads 0 — that is a measurement of "none", not a gap. A host
 * whose page counters are unreadable reads MemorySnapshot::unmeasured().
 *
 * Swap: btop uses kvm_getswapinfo; `swapinfo -k` prints the same xswdev
 * figures without libkvm. A swapless host prints only the header → 0 / 0
 * / 0 (a measurement); a failed swapinfo run → the swap fields read
 * UNMEASURED_INT. Zswap does not exist on FreeBSD (UNMEASURED_INT).
 *
 * Cost: two children per sample (sysctl, swapinfo). hw.pagesize,
 * hw.physmem and v_page_count do not change while the host runs; they are
 * read with the first successful sample and carried forward (STATIC_OIDS),
 * so later samples batch only the counters that move (DYNAMIC_OIDS).
 */
final class Memory
{
    public const array STATIC_OIDS = ['hw.pagesize', 'hw.physmem', 'vm.stats.vm.v_page_count'];

    public const array DYNAMIC_OIDS = [
        'vm.stats.vm.v_free_count',
        'vm.stats.vm.v_active_count',
        'vm.stats.vm.v_wire_count',
        'vm.stats.vm.v_laundry_count',
        'vfs.bufspace',
        'kstat.zfs.misc.arcstats.size',
        'kstat.zfs.misc.arcstats.c_min',
    ];

    private function __construct(
        private readonly Probe $probe,
        private readonly bool $zfsArcCached,
        /** @var array<string, string>|null the STATIC_OIDS values, once read */
        private readonly ?array $static = null,
    ) {
    }

    public static function new(?Probe $probe = null, bool $zfsArcCached = true): self
    {
        return new self($probe ?? LiveProbe::new(), $zfsArcCached);
    }

    /**
     * @return array{0: MemorySnapshot, 1: self}
     */
    public function sample(): array
    {
        $v = $this->static === null
            ? $this->probe->sysctl([...self::STATIC_OIDS, ...self::DYNAMIC_OIDS])
            : $this->static + $this->probe->sysctl(self::DYNAMIC_OIDS);
        $page = Sysctl::int($v, 'hw.pagesize') ?? 0;
        $pages = static fn (string $oid): ?int => ($n = Sysctl::int($v, 'vm.stats.vm.' . $oid)) === null || $page <= 0 ? null : $n * $page;

        $total = $pages('v_page_count') ?? Sysctl::int($v, 'hw.physmem') ?? 0;
        $active = $pages('v_active_count');
        $wired = $pages('v_wire_count');
        $free = $pages('v_free_count');
        if ($total <= 0 || $active === null || $wired === null || $free === null) {
            return [MemorySnapshot::unmeasured(), $this];
        }
        $next = $this->static !== null ? $this
            : new self($this->probe, $this->zfsArcCached, array_intersect_key($v, array_flip(self::STATIC_OIDS)));
        $laundry = $pages('v_laundry_count') ?? 0;

        $cached = max(0, Sysctl::int($v, 'vfs.bufspace') ?? 0);
        if ($this->zfsArcCached) {
            $arc = Sysctl::int($v, 'kstat.zfs.misc.arcstats.size') ?? 0;
            $arcMin = Sysctl::int($v, 'kstat.zfs.misc.arcstats.c_min') ?? 0;
            $cached += max(0, $arc - $arcMin);
        }

        $used = min($total, $active + $laundry + max(0, $wired - $cached));
        [$swapTotal, $swapUsed] = self::swap($this->probe->run(['swapinfo', '-k']));

        return [
            new MemorySnapshot(
                $total,
                $used,
                $total - $used,
                $cached,
                $free,
                $swapTotal,
                $swapUsed,
                $swapTotal >= 0 && $swapUsed >= 0 ? max(0, $swapTotal - $swapUsed) : Sentinel::UNMEASURED_INT,
            ),
            $next,
        ];
    }

    /**
     * swapinfo -k: "Device 1K-blocks Used Avail Capacity" then one row per
     * device, plus a "Total" row when there are several.
     *
     * @return array{0: int, 1: int} [total, used] bytes; UNMEASURED_INT both when the run failed
     */
    public static function swap(?string $text): array
    {
        if ($text === null) {
            return [Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED_INT];
        }
        $total = 0;
        $used = 0;
        foreach (explode("\n", $text) as $line) {
            $cols = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($cols) < 3 || $cols[0] === 'Device' || $cols[0] === 'Total'
                || !ctype_digit($cols[1]) || !ctype_digit($cols[2])) {
                continue;
            }
            $total += (int) $cols[1] * 1024;
            $used += (int) $cols[2] * 1024;
        }

        return [$total, $used];
    }
}
