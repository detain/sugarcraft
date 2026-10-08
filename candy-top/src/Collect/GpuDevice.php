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
    ) {
    }

    /** Memory used as a percent of total; UNMEASURED when either is unknown. */
    public function memPercent(): float
    {
        return $this->memTotal > 0 && $this->memUsed >= 0 ? $this->memUsed * 100.0 / $this->memTotal : Sentinel::UNMEASURED;
    }
}
