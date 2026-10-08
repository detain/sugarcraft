<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

final class ProcSnapshot
{
    /**
     * @param list<Process> $processes ascending pid order; sorting/filtering is the panel's job
     */
    public function __construct(
        public readonly array $processes,
        public readonly int $coreCount,
    ) {
    }

    public function count(): int
    {
        return count($this->processes);
    }
}
