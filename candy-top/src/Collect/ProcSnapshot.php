<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

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
