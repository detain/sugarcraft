<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\Sensor;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\TempSnapshot;

/**
 * CPU and board temperatures from coretemp(4)/amdtemp(4) and ACPI thermal
 * zones.
 *
 * Mirrors aristocratos/btop Cpu::get_sensors / update_sensors
 * (src/freebsd/btop_collect.cpp):
 *  - per-core `dev.cpu.N.temperature` (coretemp/amdtemp), sensor
 *    "coretemp/Core N", crit from `dev.cpu.N.coretemp.tjmax` (cpu0's when
 *    a core has none), else 95; high 80;
 *  - the package reading is `hw.acpi.thermal.tz0.temperature` when
 *    present, else the average of the cores (btop p_temp /= found), here
 *    a synthetic "coretemp/Average" sensor so the cpu box has one figure;
 *  - every ACPI zone is a sensor "acpi/tzN"; crit from `_CRT`, high from
 *    `_HOT`, else `_PSV`, else the 80/95 defaults; sysctl prints "40.1C",
 *    and -1 means a trip point is not set.
 *
 * Deviation: btop only enables temperatures when dev.cpu.0.temperature
 * exists (coretemp loaded), so a host with ACPI zones alone — the
 * reference Atom D525 — shows none; here the zones count on their own.
 * The `cpu_sensor` override picks any listed sensor by name.
 *
 * Discovery reads the `hw.acpi.thermal` + `dev.cpu` subtrees once; later
 * samples ask only the discovered OIDs (one sysctl child each). No
 * sensor at all → an empty TempSnapshot (cpu() UNMEASURED), never a
 * failure.
 */
final class Temp
{
    private const float HIGH = 80.0;
    private const float CRIT = 95.0;
    public const string AVERAGE = 'coretemp/Average';

    /**
     * @param array<string, array{oid: string, high: float, crit: float}>|null $found null until discovered
     * @param list<string> $coreSensors
     */
    private function __construct(
        private readonly Probe $probe,
        private readonly ?string $preferred,
        private readonly ?array $found,
        private readonly array $coreSensors,
    ) {
    }

    public static function new(?Probe $probe = null, ?string $cpuSensor = null): self
    {
        return new self($probe ?? LiveProbe::new(), $cpuSensor === '' ? null : $cpuSensor, null, []);
    }

    /**
     * @return array{0: TempSnapshot, 1: self}
     */
    public function sample(): array
    {
        $self = $this;
        if ($this->found === null) {
            $values = $this->probe->sysctl(['hw.acpi.thermal', 'dev.cpu']);
            // A failed run (no sysctl child at all) is retried next sample.
            $self = $values === [] ? $this : $this->discover($values);
        } else {
            $values = $this->found === [] ? [] : $this->probe->sysctl(array_values(array_column($this->found, 'oid')));
        }

        $sensors = [];
        foreach ($self->found ?? [] as $name => $s) {
            $sensors[$name] = new Sensor($name, Sysctl::celsius($values, $s['oid']) ?? Sentinel::UNMEASURED, $s['high'], $s['crit']);
        }
        $cores = array_map(static fn (string $n): float => $sensors[$n]->temp, $self->coreSensors);

        $cpu = null;
        if (isset($sensors['acpi/tz0'])) {
            $cpu = 'acpi/tz0';
        } elseif ($cores !== []) {
            $measured = array_filter($cores, static fn (float $t): bool => $t > Sentinel::UNMEASURED);
            $first = $sensors[$self->coreSensors[0]];
            $sensors[self::AVERAGE] = new Sensor(
                self::AVERAGE,
                $measured === [] ? Sentinel::UNMEASURED : round(array_sum($measured) / count($measured), 1),
                $first->high,
                $first->crit,
            );
            $cpu = self::AVERAGE;
        }
        if ($self->preferred !== null && isset($sensors[$self->preferred])) {
            $cpu = $self->preferred;
        }

        return [new TempSnapshot($sensors, $cpu, $cores), $self];
    }

    /**
     * @param array<string, string> $values
     */
    private function discover(array $values): self
    {
        $found = [];
        $zones = [];
        $cpus = [];
        foreach (array_keys($values) as $oid) {
            if (preg_match('/^hw\.acpi\.thermal\.tz(\d+)\.temperature$/', $oid, $m) === 1) {
                $zones[] = (int) $m[1];
            } elseif (preg_match('/^dev\.cpu\.(\d+)\.temperature$/', $oid, $m) === 1) {
                $cpus[] = (int) $m[1];
            }
        }
        sort($zones);
        sort($cpus);
        $tjmax0 = Sysctl::celsius($values, 'dev.cpu.0.coretemp.tjmax');
        $core = [];
        foreach ($cpus as $i) {
            $name = "coretemp/Core {$i}";
            $found[$name] = ['oid' => "dev.cpu.{$i}.temperature", 'high' => self::HIGH,
                'crit' => Sysctl::celsius($values, "dev.cpu.{$i}.coretemp.tjmax") ?? $tjmax0 ?? self::CRIT];
            $core[] = $name;
        }
        foreach ($zones as $z) {
            $p = "hw.acpi.thermal.tz{$z}.";
            $found["acpi/tz{$z}"] = [
                'oid' => $p . 'temperature',
                'high' => Sysctl::celsius($values, $p . '_HOT') ?? Sysctl::celsius($values, $p . '_PSV') ?? self::HIGH,
                'crit' => Sysctl::celsius($values, $p . '_CRT') ?? self::CRIT,
            ];
        }

        return new self($this->probe, $this->preferred, $found, $core);
    }
}
