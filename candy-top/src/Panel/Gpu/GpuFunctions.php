<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Gpu;

use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\View\GpuDetail;

/**
 * btop's `gpu_info_supported` for one device: which sections of the gpu
 * box exist. btop asks NVML/ROCm once at init; here a column is supported
 * while the (held) device reports it — so after the panel's #1008 hold a
 * column that measured once stays, and one the driver never answers never
 * appears.
 *
 * `pcieTxRx` is always false: btop's TX/RX line shows PCIe throughput
 * (NVML pcie_tx/rx), which no candy-top collector reads.
 *
 * Mirrors aristocratos/btop gpu_info_supported (btop_shared.hpp) and the
 * Linux collector's gpu_b_height_offsets formula.
 */
final class GpuFunctions
{
    public function __construct(
        public readonly bool $utilization = false,
        public readonly bool $temp = false,
        public readonly bool $power = false,
        public readonly bool $pstate = false,
        public readonly bool $clock = false,
        public readonly bool $memClock = false,
        public readonly bool $memTotal = false,
        public readonly bool $memUsed = false,
        public readonly bool $memUtilization = false,
        public readonly bool $encoder = false,
        public readonly bool $decoder = false,
        public readonly bool $pcieTxRx = false,
    ) {
    }

    public static function of(GpuDevice $d): self
    {
        return new self(
            utilization: $d->utilization >= 0,
            temp: $d->temp >= 0,
            power: $d->watts >= 0,
            pstate: self::pstate($d) !== null,
            clock: $d->clockGraphics >= 0,
            memClock: $d->clockMem >= 0,
            memTotal: $d->memTotal > 0,
            memUsed: $d->memUsed >= 0,
            memUtilization: $d->memUtilization >= 0,
            encoder: $d->encoderUtilization >= 0,
            decoder: $d->decoderUtilization >= 0,
        );
    }

    /** nvidia-smi's "P0".."P15" as btop's pwr_state integer; null when unknown. */
    public static function pstate(GpuDevice $d): ?int
    {
        return preg_match('/^P(\d{1,2})$/D', $d->pstate, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * btop gpu_b_height_offsets: the stat rows this device's box needs —
     * utilization + power + (enc or dec) + memory (1, or 3 with total and
     * used, + 2 with controller utilization).
     */
    public function offset(): int
    {
        $mem = $this->memTotal || $this->memUsed;

        return (int) $this->utilization + (int) $this->power + (int) ($this->encoder || $this->decoder)
            + ($mem ? 1 + 2 * (int) ($this->memTotal && $this->memUsed) + 2 * (int) $this->memUtilization : 0);
    }

    /**
     * btop PR #1881: a narrow box drops sections instead of overflowing —
     * below Full no enc/dec, PCIe or memory; Minimal no power either.
     */
    public function forDetail(GpuDetail $detail): self
    {
        if ($detail === GpuDetail::Full) {
            return $this;
        }
        $minimal = $detail === GpuDetail::Minimal;

        return new self(
            utilization: $this->utilization,
            temp: $this->temp,
            power: $this->power && !$minimal,
            pstate: $this->pstate && !$minimal,
            clock: $this->clock,
        );
    }
}
