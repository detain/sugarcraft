<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Read;
use SugarCraft\Top\Collect\Sentinel;

/**
 * Intel NPUs through the intel_vpu (ivpu) driver's sysfs — btop #985
 * (and its successor fork PR #1789's frequency read). Pure sysfs:
 *  - utilization = Δ`npu_busy_time_us` / Δwall µs × 100 (a cumulative
 *    counter, so the first sample is UNMEASURED);
 *  - memory = `npu_memory_utilization` (bytes in use; no total exists);
 *  - clock = `npu_current_frequency_mhz`, max `npu_max_frequency_mhz`.
 * Devices are found under /sys/class/accel and
 * /sys/bus/pci/drivers/intel_vpu (#985 reads the latter). Names from
 * pci.ids, else "Intel NPU". Reported with kind NPU, so they land in
 * {@see GpuSnapshot::$npus} and never in a GPU average.
 */
final class IntelNpu implements Backend
{
    /**
     * @param \Closure(): float $clock
     * @param list<array{card: PciCard, name: string, busy: ?array{0: int, 1: float}}> $cards
     */
    private function __construct(
        private readonly \Closure $clock,
        private readonly array $cards,
    ) {
    }

    /**
     * @param (\Closure(): float)|null $clock monotonic seconds
     */
    public static function detect(?Paths $paths = null, ?\Closure $clock = null, ?PciIds $pci = null): ?self
    {
        $paths ??= Paths::system();
        $cards = [];
        foreach (PciCards::accel($paths, ['intel_vpu']) as $card) {
            if ($card->vendorId !== 0x8086 || $card->driver !== 'intel_vpu') {
                continue;
            }
            $pci ??= PciIds::locate($paths);
            $cards[] = ['card' => $card, 'name' => $pci->name(0x8086, $card->deviceId) ?? 'Intel NPU', 'busy' => null];
        }

        return $cards === [] ? null : new self($clock ?? static fn (): float => hrtime(true) / 1e9, $cards);
    }

    public function poll(DrmScan $scan): array
    {
        $now = ($this->clock)();
        $devices = [];
        $cards = $this->cards;
        foreach ($cards as $i => $c) {
            $dev = $c['card']->device;
            $busy = Read::int("$dev/npu_busy_time_us");
            $util = Sentinel::UNMEASURED;
            if ($busy !== null && $c['busy'] !== null && $now > $c['busy'][1] && $busy >= $c['busy'][0]) {
                $util = max(0.0, min(100.0, ($busy - $c['busy'][0]) / (($now - $c['busy'][1]) * 1e6) * 100.0));
            }
            $cards[$i]['busy'] = $busy === null ? null : [$busy, $now];
            $mem = Read::int("$dev/npu_memory_utilization");
            $freq = Read::int("$dev/npu_current_frequency_mhz");
            $freqMax = Read::int("$dev/npu_max_frequency_mhz");

            $devices[] = new GpuDevice(
                $i,
                $c['name'],
                $util,
                $mem !== null && $mem >= 0 ? $mem : Sentinel::UNMEASURED_INT,
                Sentinel::UNMEASURED_INT,
                Sentinel::UNMEASURED,
                Sentinel::UNMEASURED,
                clockGraphics: $freq !== null && $freq >= 0 ? (float) $freq : Sentinel::UNMEASURED,
                clockGraphicsMax: $freqMax !== null && $freqMax > 0 ? (float) $freqMax : Sentinel::UNMEASURED,
                vendor: GpuVendor::Intel,
                kind: AcceleratorKind::Npu,
                busId: $c['card']->busId,
                driver: 'intel_vpu',
            );
        }

        return [new GpuSnapshot([], null, $devices), new self($this->clock, $cards)];
    }

    public function vendor(): GpuVendor
    {
        return GpuVendor::Intel;
    }

    public function kind(): AcceleratorKind
    {
        return AcceleratorKind::Npu;
    }

    public function needsDrmScan(): bool
    {
        return false;
    }

    public function drmClients(): bool
    {
        return false;
    }
}
