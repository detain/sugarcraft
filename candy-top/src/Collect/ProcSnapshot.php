<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One process-table sample: every surviving process, ascending pid, with
 * the host facts the proc panel scales against.
 *
 * Sorting and filtering belong to the panel; this DTO is the raw census.
 * `memTotal` is MemTotal in bytes — the Mem% denominator (btop
 * Mem::get_totalMem) — and is Sentinel::UNMEASURED_INT when /proc/meminfo
 * went unreadable, in which case per-process Mem% renders as a sentinel.
 * `coreCount` is the parsed cpu-row count — 0 on an unreadable /proc/stat,
 * never a sentinel; callers use `max(1, …)` where a divisor is needed. `detail` is the detailed-view
 * pid's extras (ProcList::withDetail), null when not asked or the pid died.
 */
final class ProcSnapshot
{
    /**
     * @param list<Process> $processes ascending pid order; sorting/filtering is the panel's job
     * @param int $memTotal MemTotal in bytes (the Mem% denominator, btop Mem::get_totalMem), UNMEASURED_INT when unreadable
     * @param ?ProcDetail $detail the detailed-view pid's extras (ProcList::withDetail), null when not asked or the pid is gone
     */
    public function __construct(
        public readonly array $processes,
        public readonly int $coreCount,
        public readonly int $memTotal = Sentinel::UNMEASURED_INT,
        public readonly ?ProcDetail $detail = null,
    ) {
    }

    public function count(): int
    {
        return count($this->processes);
    }
}
