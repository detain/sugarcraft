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
 * Intel GPUs (i915 and xe) from sysfs + DRM fdinfo — btop #1888's
 * multi-device idea without its mechanism: btop vendors intel_gpu_top and
 * reads the i915 PMU through perf_event_open (one card only before
 * #1888), which PHP cannot call.
 *
 * Discovery: EVERY `/sys/class/drm/cardN` with vendor 0x8086 and driver
 * i915 or xe (iGPU and discrete Arc side by side). Names from pci.ids,
 * else btop's "Intel GPU".
 *
 * Per sample:
 *  - utilization: the busiest engine class of the card's DRM fdinfo
 *    clients (matched on `drm-pdev`), the same max-over-engines btop's
 *    Intel::collect takes over PMU engines ({@see DrmScan::utilization()});
 *  - graphics clock: i915 `gt_act_freq_mhz` (else `gt_cur_freq_mhz`),
 *    max `gt_RP0_freq_mhz` (else `gt_max_freq_mhz`); xe
 *    `device/tile0/gt0/freq0/act_freq` (else `cur_freq`), max `rp0_freq`
 *    (else `max_freq`);
 *  - power (discrete cards; i915/xe register hwmon only there): the
 *    `energy1_input` µJ delta over the sample interval, else
 *    `power1_input`; limit `power1_max` (PL1) else `power1_cap`, else the
 *    observed peak (btop's Intel law starts at 10 W and grows);
 *  - temperature: hwmon "pkg" else the first temp input;
 *  - PCIe gen/width from the PCI device.
 * VRAM is not read: neither driver exposes used/total device memory in
 * sysfs (deferred; fdinfo per-client resident memory is attributed to
 * processes instead).
 */
final class IntelSysfs implements Backend
{
    /** btop Intel::collect seeds pwr_max_usage at 10 W. */
    private const float POWER_FLOOR = 10.0;

    /**
     * @param \Closure(): float $clock
     * @param list<array{card: PciCard, name: string, cardDir: string, hwmon: ?string, temp: ?string, energy: ?array{0: float, 1: float}, peak: float}> $cards
     */
    private function __construct(
        private readonly \Closure $clock,
        private readonly array $cards,
    ) {
    }

    /**
     * @param (\Closure(): float)|null $clock monotonic seconds (energy → watts)
     */
    public static function detect(?Paths $paths = null, ?\Closure $clock = null, ?PciIds $pci = null): ?self
    {
        $paths ??= Paths::system();
        $cards = [];
        foreach (PciCards::drm($paths) as $card) {
            if ($card->vendorId !== 0x8086 || !in_array($card->driver, ['i915', 'xe'], true)) {
                continue;
            }
            $pci ??= PciIds::locate($paths);
            $hwmon = $card->hwmon();
            $cards[] = [
                'card' => $card,
                'name' => $pci->name(0x8086, $card->deviceId) ?? 'Intel GPU',
                'cardDir' => $paths->sys('class/drm/' . $card->name),
                'hwmon' => $hwmon,
                'temp' => Hwmon::temp($hwmon, ['pkg']),
                'energy' => null,
                'peak' => self::POWER_FLOOR,
            ];
        }

        return $cards === [] ? null : new self($clock ?? static fn (): float => hrtime(true) / 1e9, $cards);
    }

    public function poll(DrmScan $scan): array
    {
        $now = ($this->clock)();
        $devices = [];
        $cards = $this->cards;
        foreach ($cards as $i => $c) {
            $card = $c['card'];
            [$clock, $clockMax] = self::clocks($card, $c['cardDir']);

            $watts = Sentinel::UNMEASURED;
            $energyPath = Hwmon::first($c['hwmon'], 'energy1_input');
            $energy = Hwmon::micro($energyPath);
            $cards[$i]['energy'] = $energy >= 0 ? [$energy, $now] : null;
            if ($energy >= 0 && $c['energy'] !== null && $now > $c['energy'][1] && $energy >= $c['energy'][0]) {
                $watts = ($energy - $c['energy'][0]) / ($now - $c['energy'][1]);
            } elseif ($energyPath === null) {
                $watts = Hwmon::micro(Hwmon::first($c['hwmon'], 'power1_input'));
            }
            $limit = Hwmon::micro(Hwmon::first($c['hwmon'], 'power1_max', 'power1_cap'));
            $peak = max($c['peak'], $watts);
            $cards[$i]['peak'] = $peak;
            $hasPower = $energyPath !== null || Hwmon::first($c['hwmon'], 'power1_input') !== null;

            $util = $scan->utilization($card->busId);
            $devices[] = new GpuDevice(
                $i,
                $c['name'],
                $util,
                Sentinel::UNMEASURED_INT,
                Sentinel::UNMEASURED_INT,
                Hwmon::celsius($c['temp']),
                $watts,
                Sentinel::UNMEASURED,
                $limit > 0 ? $limit : ($hasPower ? $peak : Sentinel::UNMEASURED),
                $clock,
                Sentinel::UNMEASURED,
                $clockMax,
                Sentinel::UNMEASURED,
                Sentinel::UNMEASURED,
                Sentinel::UNAVAILABLE,
                $card->pcieGen(),
                $card->pcieWidth(),
                Sentinel::UNMEASURED,
                Sentinel::UNMEASURED,
                Sentinel::UNMEASURED,
                Sentinel::UNAVAILABLE,
                GpuVendor::Intel,
                AcceleratorKind::Gpu,
                $card->busId,
                $card->driver,
                $util >= 0 && $scan->lowerBound(),
            );
        }

        return [new GpuSnapshot($devices), new self($this->clock, $cards)];
    }

    public function vendor(): GpuVendor
    {
        return GpuVendor::Intel;
    }

    public function kind(): AcceleratorKind
    {
        return AcceleratorKind::Gpu;
    }

    public function needsDrmScan(): bool
    {
        return true;
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

    /**
     * @return array{0: float, 1: float} [current MHz, max MHz]
     */
    private static function clocks(PciCard $card, string $cardDir): array
    {
        $mhz = static function (string ...$paths): float {
            foreach ($paths as $path) {
                $v = Read::int($path);
                if ($v !== null && $v >= 0) {
                    return (float) $v;
                }
            }

            return Sentinel::UNMEASURED;
        };
        if ($card->driver === 'xe') {
            $freq = "{$card->device}/tile0/gt0/freq0";

            return [$mhz("$freq/act_freq", "$freq/cur_freq"), $mhz("$freq/rp0_freq", "$freq/max_freq")];
        }

        return [
            $mhz("$cardDir/gt_act_freq_mhz", "$cardDir/gt_cur_freq_mhz"),
            $mhz("$cardDir/gt_RP0_freq_mhz", "$cardDir/gt_max_freq_mhz"),
        ];
    }
}
