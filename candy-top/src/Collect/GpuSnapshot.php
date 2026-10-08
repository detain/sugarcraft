<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Every accelerator one GPU sample saw.
 *
 * `devices` holds the GPUs only (kind GPU, any vendor) — the list every
 * pre-P-I consumer reads (CpuPanel's gpu sub-graphs, averages and totals),
 * so NPUs can never leak into a GPU average (#985). NPUs (#985 Intel,
 * #1839 AMD) live in `npus`; {@see accelerators()} is both.
 */
final class GpuSnapshot
{
    /**
     * @param list<GpuDevice>       $devices   GPUs; empty when no GPU could be queried
     * @param list<GpuProcess>|null $processes per-process GPU use; null when not
     *                                         measured this cycle (query failed, not
     *                                         enabled, or the base query carried no
     *                                         uuid to map them)
     * @param list<GpuDevice>       $npus      NPUs (kind NPU)
     */
    public function __construct(
        public readonly array $devices,
        public readonly ?array $processes = null,
        public readonly array $npus = [],
    ) {
    }

    /** At least one GPU answered (NPUs do not count — CpuPanel's #1008 hold keys on this). */
    public function available(): bool
    {
        return $this->devices !== [];
    }

    /** At least one GPU or NPU answered. */
    public function hasAccelerators(): bool
    {
        return $this->devices !== [] || $this->npus !== [];
    }

    /**
     * GPUs then NPUs.
     *
     * @return list<GpuDevice>
     */
    public function accelerators(): array
    {
        return [...$this->devices, ...$this->npus];
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

    /**
     * GPU utilization percent per pid summed across every GPU it uses —
     * like CPU% over cores, a process busy on two GPUs can exceed 100.
     * Unmeasured entries are skipped; a pid with only unmeasured entries
     * maps to UNMEASURED.
     *
     * @return array<int, float>
     */
    public function utilizationByPid(): array
    {
        $out = [];
        foreach ($this->processes ?? [] as $p) {
            $current = $out[$p->pid] ?? Sentinel::UNMEASURED;
            $out[$p->pid] = $p->utilization >= 0 ? max(0.0, $current) + $p->utilization : $current;
        }

        return $out;
    }
}
