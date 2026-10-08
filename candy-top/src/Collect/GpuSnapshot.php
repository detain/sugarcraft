<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

final class GpuSnapshot
{
    /**
     * @param list<GpuDevice>       $devices   empty when no GPU could be queried
     * @param list<GpuProcess>|null $processes compute processes per GPU; null when not
     *                                         measured this cycle (query failed, or the
     *                                         base query carried no uuid to map them)
     */
    public function __construct(
        public readonly array $devices,
        public readonly ?array $processes = null,
    ) {
    }

    public function available(): bool
    {
        return $this->devices !== [];
    }

    /**
     * Bytes of GPU memory per pid summed across every GPU it uses;
     * unmeasured ([N/A]) entries are skipped, and a pid with only
     * unmeasured entries maps to UNMEASURED_INT.
     *
     * @return array<int, int>
     */
    public function memoryByPid(): array
    {
        $out = [];
        foreach ($this->processes ?? [] as $p) {
            $current = $out[$p->pid] ?? Sentinel::UNMEASURED_INT;
            if ($p->usedMemory >= 0) {
                $out[$p->pid] = max(0, $current) + $p->usedMemory;
            } else {
                $out[$p->pid] = $current;
            }
        }

        return $out;
    }
}
