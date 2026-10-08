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
 * AMD GPUs from amdgpu's DRM sysfs nodes — a port of btop Gpu::Asysfs
 * (src/linux/btop_collect.cpp, "AMD sysfs (consumer GPU / iGPU) data
 * collection"), no ROCm and no FFI. btop's Rsmi backend has no PHP
 * equivalent, so this is the only AMD path and never self-skips.
 *
 * Discovery (btop Asysfs::init): every `/sys/class/drm/cardN` whose
 * device vendor is 0x1002 and driver amdgpu; a card exposing none of
 * busy / vram / temp / freq / power is skipped (virtual or freshly bound
 * GPUs). Names (#1854): amdgpu.ids by (device, revision), then pci.ids,
 * then btop's "AMD GPU (1002:xxxx)".
 *
 * Per sample (btop Asysfs::collect, extended with nodes btop leaves out):
 *  - utilization `gpu_busy_percent`, memory controller `mem_busy_percent`;
 *  - VRAM `mem_info_vram_used` / `mem_info_vram_total` (bytes);
 *  - temperature hwmon "edge" (else temp1), memory temp "mem";
 *  - power `power1_average` (EMA) else `power1_input`, µW → W;
 *  - power limit `power1_cap`; without one btop's law — the observed
 *    peak — so the power % graph still has a scale;
 *  - graphics clock hwmon `freq1_input` (sclk, Hz) else the `*` level of
 *    `pp_dpm_sclk`; memory clock `freq2_input` (mclk) else `pp_dpm_mclk`
 *    (btop marks mem_clock unsupported because it never reads the DPM
 *    table's current marker); max clocks = top DPM level;
 *  - fan `pwm1` / `pwm1_max` as percent; PCIe gen/width from the PCI
 *    device's current link.
 * Each value is its sentinel when the node is missing or unreadable. A
 * card whose `power/runtime_status` is "suspended" (runtime PM) is read
 * no further — reading other nodes would wake the dGPU — and reports 0 %
 * GPU and memory-controller utilization (idle by definition) with every
 * other column a sentinel.
 */
final class AmdSysfs implements Backend
{
    /**
     * @param list<array{card: PciCard, name: string, hwmon: ?string, temp: ?string, tempMem: ?string, power: ?string, peak: float}> $cards
     */
    private function __construct(
        private readonly array $cards,
    ) {
    }

    /**
     * Enumerate the amdgpu cards; null when there are none (the backend is
     * then not installed at all).
     */
    public static function detect(?Paths $paths = null, ?AmdGpuIds $ids = null, ?PciIds $pci = null): ?self
    {
        $paths ??= Paths::system();
        $cards = [];
        foreach (PciCards::drm($paths) as $card) {
            if ($card->vendorId !== 0x1002 || $card->driver !== 'amdgpu') {
                continue;
            }
            $dev = $card->device;
            $hwmon = $card->hwmon();
            $temp = Hwmon::temp($hwmon, ['edge']);
            $power = Hwmon::first($hwmon, 'power1_average', 'power1_input');
            $hasFreq = Hwmon::first($hwmon, 'freq1_input') !== null || is_file("$dev/pp_dpm_sclk");
            if (!is_file("$dev/gpu_busy_percent") && !is_file("$dev/mem_info_vram_total") && $temp === null && $power === null && !$hasFreq) {
                continue; // btop: no readable metrics
            }
            $ids ??= AmdGpuIds::locate($paths);
            $name = $ids->name($card->deviceId, $card->revision);
            if ($name === null) {
                $pci ??= PciIds::locate($paths);
                $name = $pci->name(0x1002, $card->deviceId);
            }
            $cards[] = [
                'card' => $card,
                'name' => $name ?? sprintf('AMD GPU (1002:%04x)', $card->deviceId),
                'hwmon' => $hwmon,
                'temp' => $temp,
                'tempMem' => Hwmon::temp($hwmon, ['mem'], false),
                'power' => $power,
                'peak' => 0.0,
            ];
        }

        return $cards === [] ? null : new self($cards);
    }

    public function poll(DrmScan $scan): array
    {
        $devices = [];
        $cards = $this->cards;
        foreach ($cards as $i => $c) {
            $card = $c['card'];
            $dev = $card->device;
            if (Read::line("$dev/power/runtime_status") === 'suspended') {
                // Runtime-PM'd dGPU (laptop hybrid graphics): reading any
                // other node would wake it. A suspended GPU is idle by
                // definition, so utilization reads a real 0 % (a sentinel
                // would let the consumers' #1008 hold freeze the last busy
                // value on screen); everything else stays a sentinel.
                $devices[] = new GpuDevice(
                    $i,
                    $c['name'],
                    0.0,
                    Sentinel::UNMEASURED_INT,
                    Sentinel::UNMEASURED_INT,
                    Sentinel::UNMEASURED,
                    Sentinel::UNMEASURED,
                    memUtilization: 0.0,
                    vendor: GpuVendor::Amd,
                    busId: $card->busId,
                    driver: $card->driver,
                );

                continue;
            }
            $hwmon = $c['hwmon'];
            $watts = Hwmon::micro($c['power']);
            $cap = Hwmon::micro(Hwmon::first($hwmon, 'power1_cap'));
            $peak = max($c['peak'], $watts);
            $cards[$i]['peak'] = $peak;
            [$sclk, $sclkMax] = self::dpm("$dev/pp_dpm_sclk");
            [$mclk, $mclkMax] = self::dpm("$dev/pp_dpm_mclk");
            $freq1 = Hwmon::micro(Hwmon::first($hwmon, 'freq1_input'));
            $freq2 = Hwmon::micro(Hwmon::first($hwmon, 'freq2_input'));
            $pwm = $hwmon === null ? null : Read::int("$hwmon/pwm1");
            $pwmMax = $hwmon === null ? 255 : (Read::int("$hwmon/pwm1_max") ?? 255);

            $devices[] = new GpuDevice(
                $i,
                $c['name'],
                self::percent("$dev/gpu_busy_percent"),
                self::bytes("$dev/mem_info_vram_used"),
                self::bytes("$dev/mem_info_vram_total"),
                Hwmon::celsius($c['temp']),
                $watts,
                self::percent("$dev/mem_busy_percent"),
                $cap > 0 ? $cap : ($peak > 0 ? $peak : Sentinel::UNMEASURED),
                $freq1 >= 0 ? $freq1 : $sclk, // Hz / 1e6 = MHz
                $freq2 >= 0 ? $freq2 : $mclk,
                $sclkMax,
                $mclkMax,
                $pwm !== null && $pwmMax > 0 ? max(0.0, min(100.0, $pwm * 100.0 / $pwmMax)) : Sentinel::UNMEASURED,
                Sentinel::UNAVAILABLE,
                $card->pcieGen(),
                $card->pcieWidth(),
                Hwmon::celsius($c['tempMem']),
                Sentinel::UNMEASURED,
                Sentinel::UNMEASURED,
                Sentinel::UNAVAILABLE,
                GpuVendor::Amd,
                AcceleratorKind::Gpu,
                $card->busId,
                $card->driver,
            );
        }

        return [new GpuSnapshot($devices), new self($cards)];
    }

    public function vendor(): GpuVendor
    {
        return GpuVendor::Amd;
    }

    public function kind(): AcceleratorKind
    {
        return AcceleratorKind::Gpu;
    }

    public function needsDrmScan(): bool
    {
        return false;
    }

    public function drmClients(): bool
    {
        return true;
    }

    /** @return list<PciCard> */
    public function cards(): array
    {
        return array_map(static fn (array $c): PciCard => $c['card'], $this->cards);
    }

    private static function percent(string $path): float
    {
        $v = Read::int($path);

        return $v === null ? Sentinel::UNMEASURED : (float) max(0, min(100, $v));
    }

    private static function bytes(string $path): int
    {
        $v = Read::int($path);

        return $v === null || $v < 0 ? Sentinel::UNMEASURED_INT : $v;
    }

    /**
     * A pp_dpm_* table ("1: 1800Mhz *") → [current MHz, top level MHz].
     * The deep-sleep line ("S: 19Mhz *") counts as the current clock when
     * marked, but never as the max.
     *
     * @return array{0: float, 1: float}
     */
    private static function dpm(string $path): array
    {
        $current = Sentinel::UNMEASURED;
        $max = Sentinel::UNMEASURED;
        foreach (explode("\n", Read::file($path) ?? '') as $line) {
            if (preg_match('/^\s*(\d+|S):\s*(\d+)\s*Mhz(\s*\*)?/i', $line, $m) === 1) {
                $mhz = (float) $m[2];
                if (strtoupper($m[1]) !== 'S') {
                    $max = max($max, $mhz);
                }
                if (($m[3] ?? '') !== '') {
                    $current = $mhz;
                }
            }
        }

        return [$current, $max];
    }
}
