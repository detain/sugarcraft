<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\BatterySnapshot;
use SugarCraft\Top\Collect\SelectableBattery;
use SugarCraft\Top\Collect\Sentinel;

/**
 * Battery percent/status/time/power from the acpi_battery(4) sysctls.
 *
 * Mirrors aristocratos/btop Cpu::get_battery (src/freebsd/btop_collect.cpp)
 * with the two open FreeBSD battery PRs folded in:
 *  - `hw.acpi.battery.life` (percent; -1 or absent → no battery, the
 *    reference host has no hw.acpi.battery tree at all);
 *  - #1830 + #1787: `time` and `rate` are C ints in MINUTES and mW (btop
 *    read them into a long / a float and printed garbage). time < 0 is
 *    "unknown"; time > 10000 min (~7 days) and rate < 20 mW or > 1000 W
 *    are the garbage the kernel returns mid-plug (#1787's guards); rate 0
 *    means "not discharging" (#1830) — all of those read as the
 *    sentinels (UNMEASURED_INT seconds, UNMEASURED watts), never 0;
 *  - `state` is the ACPI_BATT_STAT_* bitmask: bit 1 (2) charging, bit 0
 *    (1) discharging; 100 % reads "full" (btop). A state of 0 is resolved
 *    with `hw.acpi.acline` like the Linux collector resolves "unknown":
 *    on AC → charging below 100 %, else discharging; an unreadable state
 *    → "unknown".
 *  - time is time-to-empty only; while charging seconds is UNMEASURED.
 *
 * The kernel aggregates every unit into hw.acpi.battery, so there is one
 * battery named "acpi"; `selected_battery` is accepted (withSelected) and
 * has nothing to choose between. One sysctl child per sample. Stateless.
 */
final class Battery implements SelectableBattery
{
    public const string NAME = 'acpi';

    private function __construct(
        private readonly Probe $probe,
        private readonly ?string $selected,
    ) {
    }

    public static function new(?Probe $probe = null, ?string $battery = null): self
    {
        return new self($probe ?? LiveProbe::new(), $battery === null || $battery === 'Auto' ? null : $battery);
    }

    public function withSelected(?string $battery): self
    {
        return new self($this->probe, $battery === null || $battery === 'Auto' ? null : $battery);
    }

    public function selected(): ?string
    {
        return $this->selected;
    }

    /**
     * @return array{0: BatterySnapshot, 1: self}
     */
    public function sample(): array
    {
        $v = $this->probe->sysctl(['hw.acpi.battery', 'hw.acpi.acline']);
        $percent = Sysctl::int($v, 'hw.acpi.battery.life');
        if ($percent === null || $percent < 0) {
            return [BatterySnapshot::absent(), $this];
        }
        $percent = min(100, $percent);

        $state = Sysctl::int($v, 'hw.acpi.battery.state');
        $acline = Sysctl::int($v, 'hw.acpi.acline');
        $status = match (true) {
            $percent >= 100 => 'full',
            $state === null => 'unknown',
            ($state & 2) !== 0 => 'charging',
            ($state & 1) !== 0 => 'discharging',
            $acline === 1 => 'charging',
            default => 'discharging',
        };

        $minutes = Sysctl::int($v, 'hw.acpi.battery.time');
        $seconds = $status === 'discharging' && $minutes !== null && $minutes >= 0 && $minutes <= 10000
            ? $minutes * 60
            : Sentinel::UNMEASURED_INT;
        $rate = Sysctl::int($v, 'hw.acpi.battery.rate');
        $watts = $rate !== null && $rate >= 20 && $rate <= 1000000 ? $rate / 1000.0 : Sentinel::UNMEASURED;

        return [new BatterySnapshot(self::NAME, $percent, $status, $seconds, $watts), $this];
    }
}
