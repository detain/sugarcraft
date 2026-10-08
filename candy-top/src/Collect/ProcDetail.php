<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * The extra facts btop's detailed view shows for ONE process, read only
 * for that pid (ProcList::withDetail) — never in the list scan.
 *
 *  - `cwd` (btop #1546): readlink of /proc/[pid]/cwd, raw (a " (deleted)"
 *    suffix is kept); null when unreadable — EACCES for another uid
 *    without CAP_SYS_PTRACE, or a zombie;
 *  - `elapsed`: seconds since the process started (btop
 *    `uptime - starttime / clkTck`), UNMEASURED when /proc/uptime is
 *    unreadable;
 *  - `ioReadTotal` / `ioWriteTotal`: /proc/[pid]/io read_bytes /
 *    write_bytes counters (btop's IO/R and IO/W detail labels),
 *    UNMEASURED_INT when unreadable.
 *
 * Mirrors aristocratos/btop Proc::_collect_details (src/linux/btop_collect.cpp).
 */
final class ProcDetail
{
    public function __construct(
        public readonly int $pid,
        public readonly ?string $cwd = null,
        public readonly float $elapsed = Sentinel::UNMEASURED,
        public readonly int $ioReadTotal = Sentinel::UNMEASURED_INT,
        public readonly int $ioWriteTotal = Sentinel::UNMEASURED_INT,
    ) {
    }
}
