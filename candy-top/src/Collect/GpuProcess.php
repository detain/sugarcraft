<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One compute process on one GPU, from `nvidia-smi --query-compute-apps`.
 *
 * A process using several GPUs appears once per GPU (the same pid with a
 * different `gpuIndex`) — that is how the driver reports it, and summing
 * is the consumer's choice (GpuSnapshot::memoryByPid()). `usedMemory` is
 * bytes, UNMEASURED_INT when the driver answers "[N/A]" (WDDM / WSL2
 * cannot attribute memory per process). `gpuIndex` is -1 when the
 * process's gpu_uuid matched no device of the same query cycle.
 *
 * Feeds the post-v1 per-process GPU column (btop #1552). Per-process
 * utilization (sm% / mem%) is NOT here: --query-compute-apps has no such
 * field; the later source is `nvidia-smi pmon -c 1 -s um` (columns
 * gpu, pid, type, sm, mem, …, fb, command; "-" when idle).
 */
final class GpuProcess
{
    public function __construct(
        public readonly int $pid,
        public readonly int $gpuIndex,
        public readonly string $gpuUuid,
        public readonly int $usedMemory,
    ) {
    }
}
