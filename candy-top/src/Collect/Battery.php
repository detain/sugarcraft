<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Battery percent/status/time/power from /sys/class/power_supply.
 *
 * Mirrors aristocratos/btop Cpu::get_battery (src/linux/btop_collect.cpp):
 *  - candidates are supplies with `present` = 1 and `type` Battery or
 *    UPS, needing energy_now+energy_full, charge_now+charge_full, or
 *    capacity; auto-select prefers type Battery (name order — btop's
 *    unordered_map order is unspecified, ours is deterministic);
 *  - percent: capacity, else energy_now/energy_full, else
 *    charge_now/charge_full;
 *  - status "unknown" is resolved from an AC `online` flag (btop checks
 *    <bat>/AC0/online and <bat>/AC/online; we additionally accept any
 *    sibling supply of type Mains, which is where most laptops put it):
 *    online and <100% → charging, online → full, else discharging;
 *  - seconds to empty = energy_now/power_now×3600 (µWh/µW) or
 *    charge_now/current_now×3600 (µAh/µA), else time_to_empty×60;
 *    seconds to full uses (full − now) over the same rates, clamped ≥ 0
 *    (worn cells report now > full while topping off; btop shows negative);
 *  - watts = power_now/10⁶, else current_now×voltage_now/10¹².
 *
 * Deviation: btop discovers batteries once and gives up forever; we
 * re-scan the (tiny) directory each sample so a hot-plugged battery or a
 * docked/undocked UPS appears without a restart. Stateless.
 */
final class Battery implements SelectableBattery
{
    private function __construct(
        private readonly Paths $paths,
        private readonly ?string $selected,
    ) {
    }

    /**
     * @param string|null $battery btop `selected_battery`; null or "Auto" = auto-select
     */
    public static function new(?Paths $paths = null, ?string $battery = null): self
    {
        return new self($paths ?? Paths::system(), $battery === null || $battery === 'Auto' ? null : $battery);
    }

    /** The same reader for another `selected_battery` (null or "Auto" = auto-select). */
    public function withSelected(?string $battery): self
    {
        return new self($this->paths, $battery === null || $battery === 'Auto' ? null : $battery);
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
        $root = $this->paths->sys('class/power_supply');
        $candidates = [];
        $mainsOnline = null;

        foreach (Read::entries($root) as $name) {
            $dir = $root . '/' . $name;
            $type = Read::line($dir . '/type');
            if ($type === 'Mains') {
                $online = Read::int($dir . '/online');
                $mainsOnline = $online === null ? $mainsOnline : ($mainsOnline === true || $online === 1);
                continue;
            }
            if (Read::int($dir . '/present') !== 1 || !in_array($type, ['Battery', 'UPS'], true)) {
                continue;
            }
            $energy = is_file($dir . '/energy_now') && is_file($dir . '/energy_full');
            $charge = is_file($dir . '/charge_now') && is_file($dir . '/charge_full');
            if (!$energy && !$charge && !is_file($dir . '/capacity')) {
                continue;
            }
            $candidates[$name] = $type;
        }

        if ($candidates === []) {
            return [BatterySnapshot::absent(), $this];
        }

        $pick = $this->selected !== null && isset($candidates[$this->selected])
            ? $this->selected
            : (array_search('Battery', $candidates, true) ?: array_key_first($candidates));

        return [$this->read((string) $pick, $root . '/' . $pick, $mainsOnline), $this];
    }

    private function read(string $name, string $dir, ?bool $mainsOnline): BatterySnapshot
    {
        $num = static fn (string $file): ?float => Read::float($dir . '/' . $file);

        $percent = Read::int($dir . '/capacity');
        foreach ([['energy_now', 'energy_full'], ['charge_now', 'charge_full']] as [$now, $full]) {
            if ($percent === null && ($n = $num($now)) !== null && ($f = $num($full)) !== null && $f > 0.0) {
                $percent = (int) round(100.0 * $n / $f);
            }
        }
        if ($percent === null || $percent < 0) {
            return BatterySnapshot::absent();
        }

        $status = strtolower(Read::line($dir . '/status') ?? 'unknown');
        if ($status === 'unknown') {
            $online = Read::int($dir . '/AC0/online') ?? Read::int($dir . '/AC/online');
            $online = $online === null ? $mainsOnline : $online === 1;
            if ($online !== null) {
                $status = $online ? ($percent < 100 ? 'charging' : 'full') : 'discharging';
            }
        }

        $seconds = Sentinel::UNMEASURED_INT;
        $power = $num('power_now');
        $current = $num('current_now');
        if ($status !== 'charging' && $status !== 'full') {
            if (($e = $num('energy_now')) !== null && $power !== null && $power !== 0.0) {
                $seconds = (int) abs(round($e / $power * 3600));
            } elseif (($c = $num('charge_now')) !== null && $current !== null && $current !== 0.0) {
                $seconds = (int) abs(round($c / $current * 3600));
            } elseif (($minutes = Read::int($dir . '/time_to_empty')) !== null) {
                $seconds = $minutes * 60;
            }
        } elseif ($status === 'charging') {
            if (($e = $num('energy_now')) !== null && ($ef = $num('energy_full')) !== null && $power !== null && $power !== 0.0) {
                $seconds = max(0, (int) round(($ef - $e) / abs($power) * 3600));
            } elseif (($c = $num('charge_now')) !== null && ($cf = $num('charge_full')) !== null && $current !== null && $current !== 0.0) {
                $seconds = max(0, (int) round(($cf - $c) / abs($current) * 3600));
            }
        }

        $watts = Sentinel::UNMEASURED;
        if ($power !== null) {
            $watts = $power / 1e6;
        } elseif ($current !== null && ($voltage = $num('voltage_now')) !== null) {
            $watts = $current / 1e6 * $voltage / 1e6;
        }

        return new BatterySnapshot($name, $percent, $status, $seconds, $watts);
    }
}
