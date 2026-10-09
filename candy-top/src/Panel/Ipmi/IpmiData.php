<?php

declare(strict_types=1);

namespace SugarCraft\Top\Panel\Ipmi;

use SugarCraft\Top\Collect\Ipmi\IpmiChassis;
use SugarCraft\Top\Collect\Ipmi\IpmiInfo;
use SugarCraft\Top\Collect\Ipmi\IpmiPower;
use SugarCraft\Top\Collect\Ipmi\IpmiReadings;
use SugarCraft\Top\Collect\Ipmi\IpmiSel;
use SugarCraft\Top\Collect\Ipmi\IpmiSensor;
use SugarCraft\Top\Collect\Ipmi\IpmiSnapshot;
use SugarCraft\Top\Collect\Ipmi\IpmiState;
use SugarCraft\Top\Collect\Ipmi\SensorKind;

/**
 * What the ipmi box shows, held across partial rounds: every part of a
 * {@see IpmiSnapshot} replaces the held one only when the round read it
 * (#1008: a round without the SDR walk keeps the last sensors), plus the
 * box's own history — the instantaneous watts per round (the gauge's
 * sparkline and its fallback min/avg/max) and the highest RPM seen per
 * fan (the fan bars' scale when the BMC defines no upper threshold).
 * A failed round keeps the data and only changes {@see $state}.
 */
final class IpmiData
{
    /** Watts samples kept when the box width is not known yet. */
    public const HISTORY = 240;

    /** Samples kept per temperature / fan / voltage sensor (one per SDR read: ~42 min at the default cadence). */
    public const SERIES = 256;

    /**
     * @param list<IpmiSensor> $sensors
     * @param list<float> $watts newest last
     * @param array<string, float> $fanPeak fan name → highest reading seen
     * @param array<string, list<float>> $series sensor name → readings, newest last
     * @param array<string, string> $labels sensor name → unique display label
     */
    private function __construct(
        public readonly IpmiState $state,
        public readonly string $device,
        public readonly ?IpmiInfo $info,
        public readonly ?IpmiPower $power,
        public readonly array $sensors,
        public readonly bool $sensorsRead,
        public readonly IpmiReadings $readings,
        public readonly ?IpmiChassis $chassis,
        public readonly ?IpmiSel $sel,
        public readonly array $watts,
        public readonly array $fanPeak,
        public readonly int $round,
        public readonly array $series = [],
        public readonly bool $thresholdsNext = false,
        private readonly array $labels = [],
    ) {
    }

    /** The sensor's display label, unique within its group ({@see IpmiNames::unique()}). */
    public function label(IpmiSensor $sensor): string
    {
        return $this->labels[$sensor->name] ?? IpmiNames::label($sensor);
    }

    public static function new(): self
    {
        return new self(IpmiState::Starting, '', null, null, [], false, new IpmiReadings(), null, null, [], [], -1);
    }

    /** Fold one round in; `$cap` bounds the watts history. */
    public function merged(IpmiSnapshot $s, int $cap = self::HISTORY): self
    {
        if ($s->state->failed()) {
            return new self($s->state, $s->device, $this->info, $this->power, $this->sensors, $this->sensorsRead, $this->readings, $this->chassis, $this->sel, $this->watts, $this->fanPeak, $s->round, $this->series, false, $this->labels);
        }
        if ($s->state === IpmiState::Starting) {
            return $this;
        }
        $sensors = $this->sensors;
        $readings = $this->readings;
        $peak = $this->fanPeak;
        $read = $this->sensorsRead;
        $series = $this->series;
        $labels = $this->labels;
        if ($s->sensors !== null) {
            $sensors = $s->sensors;
            $readings = IpmiReadings::of($sensors);
            $read = true;
            $labels = [
                ...IpmiNames::unique($readings->temps),
                ...IpmiNames::unique($readings->fans),
                ...IpmiNames::unique($readings->volts),
                ...IpmiNames::unique($readings->rails),
                ...IpmiNames::unique($readings->currents),
            ];
            foreach ($readings->fans as $fan) {
                if ($fan->kind === SensorKind::FanRpm && $fan->value !== null) {
                    $peak[$fan->name] = max($peak[$fan->name] ?? 0.0, $fan->value);
                }
            }
            if ($s->round !== $this->round) {
                $next = [];
                foreach ([...$readings->temps, ...$readings->fans, ...$readings->volts] as $t) {
                    $next[$t->name] = \array_slice([...($series[$t->name] ?? []), (float) $t->value], -self::SERIES);
                }
                $series = $next; // a sensor that stopped reading drops its series
            }
        }
        $watts = $this->watts;
        $now = $s->power?->watts ?? ($s->sensors !== null ? self::fallbackWatts($readings) : null);
        if ($now !== null && $s->round !== $this->round) {
            $watts[] = $now;
            if (\count($watts) > max(1, $cap)) {
                $watts = \array_slice($watts, -max(1, $cap));
            }
        }

        return new self(
            IpmiState::Ready,
            '',
            $s->info ?? $this->info,
            $s->power ?? $this->power,
            $sensors,
            $read,
            $readings,
            $s->chassis ?? $this->chassis,
            $s->sel ?? $this->sel,
            $watts,
            $peak,
            $s->round,
            $series,
            $s->thresholdsNext,
            $labels,
        );
    }

    /** True once anything at all was read (the box then draws data, not just a message). */
    public function any(): bool
    {
        return $this->info !== null || $this->power !== null || $this->sensorsRead || $this->chassis !== null;
    }

    /**
     * The whole-system draw: DCMI's instantaneous reading, else the
     * supplies' summed input, else a BMC power meter rail.
     */
    public function watts(): ?float
    {
        return $this->power?->watts ?? self::fallbackWatts($this->readings);
    }

    /** [min, avg, max] over the BMC's window, else over the box's own history; null without data. */
    public function window(): ?array
    {
        if ($this->power !== null) {
            return [$this->power->min, $this->power->avg, $this->power->max];
        }
        if ($this->watts === []) {
            return null;
        }

        return [min($this->watts), array_sum($this->watts) / \count($this->watts), max($this->watts)];
    }

    /**
     * The gauge's full scale: the supplies' rated output when the BMC
     * states it, else the highest draw seen rounded up to a friendly
     * number (so the needle never pins).
     */
    public function scale(): float
    {
        $cap = $this->readings->capacity();
        if ($cap !== null) {
            return $cap;
        }
        $seen = max([0.0, ...$this->watts, $this->power?->max ?? 0.0, $this->watts() ?? 0.0]);

        return self::nice(max(100.0, $seen * 1.15));
    }

    /** The bar scale of a fan: its upper threshold, else the highest RPM any fan reached (rounded up), 100 for duty. */
    public function fanScale(IpmiSensor $fan): float
    {
        if ($fan->kind === SensorKind::FanDuty) {
            return 100.0;
        }
        $upper = $fan->upper();
        if ($upper !== null && $upper > 0) {
            return $upper;
        }

        return self::nice(max(1000.0, ...array_values($this->fanPeak ?: [1000.0])) * 1.1);
    }

    /** 1, 1.25, 1.5, 2, 2.5, 3, 4, 5, 6, 8 × 10^n at or above `$v`. */
    public static function nice(float $v): float
    {
        if ($v <= 0) {
            return 1.0;
        }
        $exp = 10 ** floor(log10($v));
        foreach ([1, 1.25, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10] as $step) {
            if ($step * $exp >= $v - 1e-9) {
                return $step * $exp;
            }
        }

        return 10 * $exp;
    }

    private static function fallbackWatts(IpmiReadings $r): ?float
    {
        $in = $r->psuInput();
        if ($in !== null) {
            return $in;
        }
        foreach ($r->rails as $rail) {
            if (preg_match('/meter|total|system|sys[ _]?pwr|platform/i', $rail->name) === 1) {
                return $rail->value;
            }
        }

        return null;
    }
}
