<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One GPU as nvidia-smi reports it. Any column the driver answers with
 * "N/A"/"[N/A]"/"[Not Supported]" — or that the query variant in use did
 * not ask for (the base query on an older driver) — takes the matching
 * sentinel: UNMEASURED for floats, UNMEASURED_INT for ints, UNAVAILABLE
 * for text.
 *
 * The extended fields after `watts` are the ones btop's NVML backend shows
 * in its gpu box (Gpu::gpu_info: mem utilization, clocks, power cap,
 * pstate, PCIe link, encoder/decoder): power limit, graphics/memory clock
 * (current and max), fan, pstate ("P0".."P12"), PCIe gen/width, memory
 * temperature, encoder/decoder utilization and the device UUID that
 * --query-compute-apps keys processes by.
 *
 * Multi-vendor (P-I): the trailing identity fields say who reported the
 * device — `vendor` (nvidia / amd / intel, btop `shown_gpus` tokens),
 * `kind` (GPU, or NPU for btop #985/#1839), `busId` (PCI slot
 * "0000:03:00.0", the key DRM fdinfo `drm-pdev` matches; n/a for
 * nvidia-smi rows) and `driver` (amdgpu, i915, xe, intel_vpu, amdxdna;
 * n/a for nvidia-smi). They default to an NVIDIA GPU so every existing
 * constructor call keeps its meaning. `index` is the device's position in
 * {@see GpuSnapshot::$devices} (GPUs) or {@see GpuSnapshot::$npus} (NPUs),
 * NVIDIA first, then AMD, then Intel — btop Gpu::collect's slice order.
 *
 * `utilizationLowerBound` marks a utilization that is a floor, not the
 * full figure: Intel's DRM fdinfo scan could not read every process's fd
 * dir (a non-root monitor sees only its own uid's clients), so a UI may
 * render "≥" — or n/a — instead of a plain percent.
 */
final class GpuDevice
{
    public function __construct(
        public readonly int $index,
        public readonly string $name,
        public readonly float $utilization,
        public readonly int $memUsed,
        public readonly int $memTotal,
        public readonly float $temp,
        public readonly float $watts,
        public readonly float $memUtilization = Sentinel::UNMEASURED,
        public readonly float $powerLimit = Sentinel::UNMEASURED,
        public readonly float $clockGraphics = Sentinel::UNMEASURED,
        public readonly float $clockMem = Sentinel::UNMEASURED,
        public readonly float $clockGraphicsMax = Sentinel::UNMEASURED,
        public readonly float $clockMemMax = Sentinel::UNMEASURED,
        public readonly float $fanSpeed = Sentinel::UNMEASURED,
        public readonly string $pstate = Sentinel::UNAVAILABLE,
        public readonly int $pcieGen = Sentinel::UNMEASURED_INT,
        public readonly int $pcieWidth = Sentinel::UNMEASURED_INT,
        public readonly float $tempMem = Sentinel::UNMEASURED,
        public readonly float $encoderUtilization = Sentinel::UNMEASURED,
        public readonly float $decoderUtilization = Sentinel::UNMEASURED,
        public readonly string $uuid = Sentinel::UNAVAILABLE,
        public readonly GpuVendor $vendor = GpuVendor::Nvidia,
        public readonly AcceleratorKind $kind = AcceleratorKind::Gpu,
        public readonly string $busId = Sentinel::UNAVAILABLE,
        public readonly string $driver = Sentinel::UNAVAILABLE,
        public readonly bool $utilizationLowerBound = false,
    ) {
    }

    /** The same device at another position (the merged snapshot re-indexes per backend). */
    public function withIndex(int $index): self
    {
        return $this->copy(['index' => $index]);
    }

    /**
     * The same device with every measurement replaced by its sentinel —
     * identity (index, name, uuid, vendor, kind, bus id, driver, power
     * limit, max clocks) kept. Stands in for a device whose backend hit a
     * transient failure so the devices after it keep their indexes.
     */
    public function unmeasured(): self
    {
        return new self(
            $this->index,
            $this->name,
            Sentinel::UNMEASURED,
            Sentinel::UNMEASURED_INT,
            $this->memTotal,
            Sentinel::UNMEASURED,
            Sentinel::UNMEASURED,
            Sentinel::UNMEASURED,
            $this->powerLimit,
            Sentinel::UNMEASURED,
            Sentinel::UNMEASURED,
            $this->clockGraphicsMax,
            $this->clockMemMax,
            Sentinel::UNMEASURED,
            Sentinel::UNAVAILABLE,
            Sentinel::UNMEASURED_INT,
            Sentinel::UNMEASURED_INT,
            Sentinel::UNMEASURED,
            Sentinel::UNMEASURED,
            Sentinel::UNMEASURED,
            $this->uuid,
            $this->vendor,
            $this->kind,
            $this->busId,
            $this->driver,
            false,
        );
    }

    /**
     * #1008 for one device: every column that is unmeasured now but was
     * measured in `$prev` keeps the previous value, so a row never loses
     * a column on one N/A reading. A different device (index or bus id
     * changed) is not merged. This is the merge CpuPanel::holdDevice()
     * performs, carrying the identity fields too.
     */
    public function heldFrom(?self $prev): self
    {
        if ($prev === null || $prev->index !== $this->index || $prev->busId !== $this->busId || $prev->kind !== $this->kind) {
            return $this;
        }
        $f = static fn (float $now, float $old): float => $now >= 0 ? $now : $old;
        $n = static fn (int $now, int $old): int => $now >= 0 ? $now : $old;

        return new self(
            $this->index,
            $this->name,
            $f($this->utilization, $prev->utilization),
            $n($this->memUsed, $prev->memUsed),
            $n($this->memTotal, $prev->memTotal),
            $f($this->temp, $prev->temp),
            $f($this->watts, $prev->watts),
            $f($this->memUtilization, $prev->memUtilization),
            $f($this->powerLimit, $prev->powerLimit),
            $f($this->clockGraphics, $prev->clockGraphics),
            $f($this->clockMem, $prev->clockMem),
            $f($this->clockGraphicsMax, $prev->clockGraphicsMax),
            $f($this->clockMemMax, $prev->clockMemMax),
            $f($this->fanSpeed, $prev->fanSpeed),
            $this->pstate,
            $n($this->pcieGen, $prev->pcieGen),
            $n($this->pcieWidth, $prev->pcieWidth),
            $f($this->tempMem, $prev->tempMem),
            $f($this->encoderUtilization, $prev->encoderUtilization),
            $f($this->decoderUtilization, $prev->decoderUtilization),
            $this->uuid,
            $this->vendor,
            $this->kind,
            $this->busId,
            $this->driver,
            // The flag travels with the utilization value it describes.
            $this->utilization >= 0 ? $this->utilizationLowerBound : $prev->utilizationLowerBound,
        );
    }

    /**
     * @param array<string, mixed> $over constructor arguments by name
     */
    private function copy(array $over): self
    {
        return new self(...array_merge(get_object_vars($this), $over));
    }

    /** Memory used as a percent of total; UNMEASURED when either is unknown. */
    public function memPercent(): float
    {
        return $this->memTotal > 0 && $this->memUsed >= 0 ? $this->memUsed * 100.0 / $this->memTotal : Sentinel::UNMEASURED;
    }
}
