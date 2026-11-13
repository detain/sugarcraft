<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * CPU and board temperatures from /sys/class/hwmon, falling back to
 * /sys/class/thermal.
 *
 * Mirrors aristocratos/btop Cpu::get_sensors / update_sensors
 * (src/linux/btop_collect.cpp):
 *  - hwmon chips exposing temp*_input (directly or under device/) are
 *    searched, plus /sys/devices/platform/coretemp.0/hwmon when no
 *    coretemp chip was listed; anything under an nvme path is skipped
 *    (drive temps are not CPU sensors);
 *  - a sensor is "<chip name>/<label>", label from tempN_label else tempN;
 *    millidegrees ÷ 1000; crit from tempN_crit else 95;
 *  - the cpu sensor is the first "Package id*"/"Tdie*"/"SoC Temperature*"
 *    label; "Core*"/"Tccd*" labels are per-core sensors, sorted
 *    lexically then (stably) by length so Core 10 follows Core 9;
 *  - without a cpu sensor the thermal zones are added (type as label,
 *    high/critical trip points, defaults 80/95), and the cpu sensor falls
 *    back to the first name containing "cpu"/"k10temp", then to any;
 *  - discovery happens once; later samples re-read the inputs at most
 *    once per {@see SENSOR_INTERVAL} window (candy-top-030: one sysfs read
 *    measured ~100 ms on a slow-bus bnxt_en host, so the per-tick version
 *    broke the frame budget forever), and hostile `name`/`label` bytes
 *    never reach the display keys (candy-top-046).
 */
final class Temp
{
    private const array CPU_LABELS = ['Package id', 'Tdie', 'SoC Temperature'];
    private const array CORE_LABELS = ['Core', 'Tccd'];

    /** Seconds between input re-reads (the proven Gpu interval idiom, low end). */
    public const SENSOR_INTERVAL = 5.0;

    /**
     * Plausible hwmon window in millidegrees. A disconnected thermistor
     * reads -99999000 here and btop's own driver quirks show whole-tree
     * garbage; outside ±(-50..250) °C nothing on a board is real (046).
     */
    private const MILLI_MIN = -50_000;
    private const MILLI_MAX = 250_000;

    /**
     * @param array<string, array{input: string, high: float, crit: float}>|null $found null until discovered
     * @param list<string> $coreSensors
     * @param ?array<string, Sensor> $cached sensor values from the last read window
     * @param \Closure(): float $clock monotonic seconds
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly ?string $preferred,
        private readonly ?array $found,
        private readonly ?string $cpuSensor,
        private readonly array $coreSensors,
        private readonly \Closure $clock,
        private readonly ?array $cached = null,
        private readonly ?float $nextSensorReadAt = null,
    ) {
    }

    /**
     * @param string|null $cpuSensor btop `cpu_sensor` override ("chip/label"), used when present
     * @param (\Closure(): float)|null $clock monotonic seconds; null = the live hrtime clock
     */
    public static function new(?Paths $paths = null, ?string $cpuSensor = null, ?\Closure $clock = null): self
    {
        return new self(
            $paths ?? Paths::system(),
            $cpuSensor === '' ? null : $cpuSensor,
            null,
            null,
            [],
            $clock ?? static fn (): float => hrtime(true) / 1e9,
        );
    }

    /**
     * @return array{0: TempSnapshot, 1: self}
     */
    public function sample(): array
    {
        $self = $this->found === null ? $this->discover() : $this;

        $now = ($self->clock)();
        if ($self->cached !== null && $now < (float) $self->nextSensorReadAt) {
            // Inside the throttle window: hand back the last values, never
            // touch the (possibly 100 ms-per-read) slow bus.
            $sensors = $self->cached;
        } else {
            $sensors = [];
            foreach ($self->found ?? [] as $name => $s) {
                $milli = Read::int($s['input']);
                $real = $milli !== null && $milli >= self::MILLI_MIN && $milli <= self::MILLI_MAX;
                $sensors[$name] = new Sensor($name, $real ? $milli / 1000.0 : Sentinel::UNMEASURED, $s['high'], $s['crit']);
            }
            $self = new self($self->paths, $self->preferred, $self->found, $self->cpuSensor, $self->coreSensors, $self->clock, $sensors, $now + self::SENSOR_INTERVAL);
        }

        $cpu = $self->preferred !== null && isset($sensors[$self->preferred]) ? $self->preferred : $self->cpuSensor;
        $cores = array_map(static fn (string $n): float => $sensors[$n]->temp, $self->coreSensors);

        return [new TempSnapshot($sensors, $cpu, $cores), $self];
    }

    private function discover(): self
    {
        $found = [];
        $cpuSensor = null;
        $coreSensors = [];
        $gotCoretemp = false;

        $searchPaths = [];
        $hwmon = $this->paths->sys('class/hwmon');
        foreach (Read::entries($hwmon) as $entry) {
            $path = realpath($hwmon . '/' . $entry) ?: $hwmon . '/' . $entry;
            if (in_array($path, $searchPaths, true) || in_array($path . '/device', $searchPaths, true)) {
                continue;
            }
            if (str_contains($path, 'coretemp')) {
                $gotCoretemp = true;
            }
            if (self::hasTempInput($path)) {
                $searchPaths[] = $path;
            } elseif (self::hasTempInput($path . '/device')) {
                $searchPaths[] = $path . '/device';
            }
        }
        if (!$gotCoretemp) {
            $platform = $this->paths->sys('devices/platform/coretemp.0/hwmon');
            foreach (Read::entries($platform) as $entry) {
                $path = realpath($platform . '/' . $entry) ?: $platform . '/' . $entry;
                if (!in_array($path, $searchPaths, true) && self::hasTempInput($path)) {
                    $searchPaths[] = $path;
                    $gotCoretemp = true;
                }
            }
        }

        foreach ($searchPaths as $path) {
            if (str_contains($path, 'nvme')) {
                continue;
            }
            $chip = self::visible(Read::line($path . '/name') ?? '', basename($path));
            foreach (Read::entries($path) as $file) {
                if (preg_match('/^temp(\d+)_input$/', $file, $m) !== 1) {
                    continue;
                }
                $base = $path . '/temp' . $m[1] . '_';
                $label = self::visible(Read::line($base . 'label') ?? '', 'temp' . $m[1]);
                $name = $chip . '/' . $label;
                $high = Read::int($base . 'max');
                $crit = Read::int($base . 'crit');
                $found[$name] = [
                    'input' => $base . 'input',
                    'high' => $high !== null && $high > 0 ? $high / 1000.0 : 80.0,
                    'crit' => $crit !== null && $crit > 0 ? $crit / 1000.0 : 95.0,
                ];

                if ($cpuSensor === null && self::startsWithAny($label, self::CPU_LABELS)) {
                    $cpuSensor = $name;
                } elseif (self::startsWithAny($label, self::CORE_LABELS)) {
                    $gotCoretemp = true;
                    if (!in_array($name, $coreSensors, true)) {
                        $coreSensors[] = $name;
                    }
                }
            }
        }

        if ($cpuSensor === null) {
            $found += $this->thermalZones();
        }

        if (!$gotCoretemp || $coreSensors === []) {
            $coreSensors = []; // btop cpu_temp_only
        } else {
            sort($coreSensors, SORT_STRING);
            usort($coreSensors, static fn (string $a, string $b): int => strlen($a) <=> strlen($b));
        }

        if ($cpuSensor === null && $found !== []) {
            foreach (array_keys($found) as $name) {
                $lower = strtolower($name);
                if (str_contains($lower, 'cpu') || str_contains($lower, 'k10temp')) {
                    $cpuSensor = $name;
                    break;
                }
            }
            $cpuSensor ??= array_key_first($found);
        }

        return new self($this->paths, $this->preferred, $found, $cpuSensor, $coreSensors, $this->clock);
    }

    /**
     * sysfs `name`/`label`/`type` strings arrive verbatim from kernel
     * drivers — NUL and control bytes included on hostile or buggy chips
     * (candy-top-046; the container-tag and BMC paths already whitelist
     * the same way). Collapse to printable text; blank means the fallback.
     */
    private static function visible(string $raw, string $fallback): string
    {
        $clean = trim(preg_replace('/[^\x20-\x7E]+/', ' ', $raw) ?? '');

        return $clean === '' ? $fallback : $clean;
    }

    /**
     * @return array<string, array{input: string, high: float, crit: float}>
     */
    private function thermalZones(): array
    {
        $zones = [];
        for ($i = 0; is_dir($base = $this->paths->sys("class/thermal/thermal_zone{$i}")); $i++) {
            if (!is_file($base . '/temp')) {
                continue;
            }
            $label = self::visible(Read::line($base . '/type') ?? '', 'temp' . $i);
            $high = 0.0;
            $crit = 0.0;
            for ($t = 0; is_file($base . "/trip_point_{$t}_temp"); $t++) {
                $type = Read::line($base . "/trip_point_{$t}_type");
                if ($type === 'high' || $type === 'critical') {
                    $value = (Read::int($base . "/trip_point_{$t}_temp") ?? 0) / 1000.0;
                    if ($type === 'high') {
                        $high = $value;
                    } else {
                        $crit = $value;
                    }
                }
            }
            $zones["thermal{$i}/{$label}"] = [
                'input' => $base . '/temp',
                'high' => $high < 1.0 ? 80.0 : $high,
                'crit' => $crit < 1.0 ? 95.0 : $crit,
            ];
        }

        return $zones;
    }

    private static function hasTempInput(string $dir): bool
    {
        foreach (Read::entries($dir) as $file) {
            if (str_starts_with($file, 'temp') && str_ends_with($file, '_input')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $prefixes */
    private static function startsWithAny(string $label, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($label, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
