<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One process on one GPU (btop #1552).
 *
 * Sources:
 *  - NVIDIA: `nvidia-smi --query-compute-apps` gives the memory, and
 *    `nvidia-smi pmon -c 1 -s um` adds the utilization columns (sm, mem
 *    bandwidth, enc, dec) — and the graphics (type G) processes the
 *    compute-apps query does not list. `gpuUuid` is the device UUID.
 *  - AMD / Intel: DRM fdinfo (`/proc/<pid>/fdinfo/<fd>` `drm-*` keys).
 *    `utilization` is the busiest engine class over the sample interval,
 *    `usedMemory` the device-local resident memory (vram / local regions;
 *    UNMEASURED_INT on an iGPU, which has none). `gpuUuid` is the PCI slot
 *    from `drm-pdev`.
 *
 * A process using several GPUs appears once per GPU (the same pid with a
 * different `gpuIndex`) — that is how both sources report it, and summing
 * is the consumer's choice (GpuSnapshot::memoryByPid() /
 * utilizationByPid()). `usedMemory` is bytes, UNMEASURED_INT when the
 * driver answers "[N/A]" (WDDM / WSL2 cannot attribute memory per
 * process). `gpuIndex` is -1 when the process's device matched no device
 * of the same cycle. Utilizations are percent, UNMEASURED when the source
 * gave none (compute-apps without pmon, or the first fdinfo sight of a
 * client — a rate needs two samples). pmon prints "-" for a process with
 * no activity in its window: that reads 0.0, a measured idle.
 */
final class GpuProcess
{
    public function __construct(
        public readonly int $pid,
        public readonly int $gpuIndex,
        public readonly string $gpuUuid,
        public readonly int $usedMemory,
        public readonly float $utilization = Sentinel::UNMEASURED,
        public readonly float $memUtilization = Sentinel::UNMEASURED,
        public readonly float $encoderUtilization = Sentinel::UNMEASURED,
        public readonly float $decoderUtilization = Sentinel::UNMEASURED,
    ) {
    }

    /** The same process on the merged snapshot's device index. */
    public function withGpuIndex(int $gpuIndex): self
    {
        return new self(
            $this->pid,
            $gpuIndex,
            $this->gpuUuid,
            $this->usedMemory,
            $this->utilization,
            $this->memUtilization,
            $this->encoderUtilization,
            $this->decoderUtilization,
        );
    }
}
