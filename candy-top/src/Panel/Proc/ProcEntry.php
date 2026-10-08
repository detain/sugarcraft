<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Proc;

use SugarCraft\Top\Collect\Process;

/**
 * One row of the process list as the proc panel sorts, filters and draws
 * it — btop's `proc_info` after Proc::collect's post-processing.
 *
 * The metric fields start as the sampled {@see Process} values with the
 * #1008 carry applied (an UNMEASURED cpu reading keeps the pid's
 * last good value, a pid with none reads 0; io passes through as sampled),
 * and in tree view become the collapsed-branch or proc_aggregate sums btop's
 * `_tree_gen` writes back into the parent. `prefix`/`depth`/`collapsed`
 * are tree-view only.
 *
 *  - `cpu`: btop cpu_p, >= 0;
 *  - `cpuC`: btop cpu_c (cumulative, the "cpu lazy" key), >= 0;
 *  - `ioRead` / `ioWrite`: bytes/s, Sentinel::UNMEASURED when unknown
 *    (rendered "-", sorted as 0 like btop's io_read_b default);
 *  - `gpu` / `gpuMem`: btop #1552 gpu_p (percent, summed over the pid's
 *    GPUs and clamped to 0-100 as the PR does) and gpu_m (bytes), 0 for a
 *    process no GPU snapshot lists — joined in by the panel from
 *    {@see GpuUsage}.
 */
final class ProcEntry
{
    public function __construct(
        public readonly Process $process,
        public readonly float $cpu,
        public readonly float $cpuC,
        public readonly int $mem,
        public readonly int $threads,
        public readonly float $ioRead,
        public readonly float $ioWrite,
        public readonly string $prefix = '',
        public readonly int $depth = 0,
        public readonly bool $collapsed = false,
        public readonly float $gpu = 0.0,
        public readonly int $gpuMem = 0,
    ) {
    }

    /** The sampled values, #1008 carry already applied by the caller. */
    public static function of(Process $p, float $cpu, float $ioRead, float $ioWrite, float $gpu = 0.0, int $gpuMem = 0): self
    {
        return new self(
            $p,
            max(0.0, $cpu),
            max(0.0, $p->cpuCumulative),
            max(0, $p->mem),
            max(0, $p->threads),
            $ioRead,
            $ioWrite,
            gpu: max(0.0, min(100.0, $gpu)),
            gpuMem: max(0, $gpuMem),
        );
    }

    public function pid(): int
    {
        return $this->process->pid;
    }

    /** btop #1552's GPU-only test: no GPU time and no GPU memory. */
    public function gpuIdle(): bool
    {
        return $this->gpu <= 0.0 && $this->gpuMem === 0;
    }

    /** read + write bytes/s with unknown halves as 0 (btop "io total"). */
    public function ioTotal(): float
    {
        return max(0.0, $this->ioRead) + max(0.0, $this->ioWrite);
    }

    /**
     * A copy with tree-derived values.
     *
     * @param array{cpu?: float, cpuC?: float, mem?: int, threads?: int, prefix?: string, depth?: int, collapsed?: bool, gpu?: float, gpuMem?: int} $o
     */
    public function with(array $o): self
    {
        return new self(
            $this->process,
            $o['cpu'] ?? $this->cpu,
            $o['cpuC'] ?? $this->cpuC,
            $o['mem'] ?? $this->mem,
            $o['threads'] ?? $this->threads,
            $this->ioRead,
            $this->ioWrite,
            $o['prefix'] ?? $this->prefix,
            $o['depth'] ?? $this->depth,
            $o['collapsed'] ?? $this->collapsed,
            $o['gpu'] ?? $this->gpu,
            $o['gpuMem'] ?? $this->gpuMem,
        );
    }
}
