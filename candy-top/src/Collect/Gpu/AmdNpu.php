<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Gpu;

use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Sentinel;

/**
 * AMD Ryzen AI NPUs (XDNA, driver amdxdna) — btop #1839, detection only.
 *
 * Detection is pure sysfs: `/sys/class/accel/accelN` whose PCI vendor is
 * 0x1022 and driver amdxdna. #1839 reads per-AIE-column utilization and
 * power through `DRM_IOCTL_AMDXDNA_GET_INFO` on /dev/accel/accelN; that
 * ioctl's argument structs are vendored kernel uapi that has no PHP
 * binding, and an FFI ioctl() against a guessed layout cannot be tested
 * here (no hardware, no header on the build hosts) — so it is DEFERRED.
 * Until then each NPU is listed as present with every measurement at its
 * sentinel (the sentinel law: "n/a", never a fabricated 0). Names from
 * pci.ids, else "AMD NPU".
 */
final class AmdNpu implements Backend
{
    /**
     * @param list<array{card: PciCard, name: string}> $cards
     */
    private function __construct(
        private readonly array $cards,
    ) {
    }

    public static function detect(?Paths $paths = null, ?PciIds $pci = null): ?self
    {
        $paths ??= Paths::system();
        $cards = [];
        foreach (PciCards::accel($paths) as $card) {
            if ($card->vendorId !== 0x1022 || $card->driver !== 'amdxdna') {
                continue;
            }
            $pci ??= PciIds::locate($paths);
            $cards[] = ['card' => $card, 'name' => $pci->name(0x1022, $card->deviceId) ?? 'AMD NPU'];
        }

        return $cards === [] ? null : new self($cards);
    }

    public function poll(DrmScan $scan): array
    {
        $devices = [];
        foreach ($this->cards as $i => $c) {
            $devices[] = new GpuDevice(
                $i,
                $c['name'],
                Sentinel::UNMEASURED,
                Sentinel::UNMEASURED_INT,
                Sentinel::UNMEASURED_INT,
                Sentinel::UNMEASURED,
                Sentinel::UNMEASURED,
                vendor: GpuVendor::Amd,
                kind: AcceleratorKind::Npu,
                busId: $c['card']->busId,
                driver: 'amdxdna',
            );
        }

        return [new GpuSnapshot([], null, $devices), $this];
    }

    public function vendor(): GpuVendor
    {
        return GpuVendor::Amd;
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
