<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use React\Promise\PromiseInterface;
use SugarCraft\Top\Collect\Ipmi\IpmiChassis;
use SugarCraft\Top\Collect\Ipmi\IpmiInfo;
use SugarCraft\Top\Collect\Ipmi\IpmiParser;
use SugarCraft\Top\Collect\Ipmi\IpmiPower;
use SugarCraft\Top\Collect\Ipmi\IpmiReader;
use SugarCraft\Top\Collect\Ipmi\IpmiSel;
use SugarCraft\Top\Collect\Ipmi\IpmiSensor;
use SugarCraft\Top\Collect\Ipmi\IpmiSnapshot;
use SugarCraft\Top\Collect\Ipmi\IpmiState;
use SugarCraft\Top\Collect\Ipmi\SensorKind;

use function React\Promise\resolve;

/**
 * A deterministic BMC for `--fake`, tests and demos: real captured
 * ipmitool output ({@see FakeIpmiCaptures}, or any capture set given to
 * {@see fromCaptures()}) parsed by the live parsers, then — when
 * `$vary` — moved gently round by round with {@see Wave}: the draw
 * swings between ~1.3 and ~2.2 kW, temperatures by a few degrees, fans
 * and voltages by a few percent. Every round is complete (no partial
 * cadence) and resolves at once.
 *
 * Mutable round counter, like the live reader: the panel's copies share
 * one BMC.
 */
final class FakeIpmi implements IpmiReader
{
    private int $step = 0;

    /**
     * @param list<IpmiSensor> $sensors
     */
    private function __construct(
        private readonly IpmiState $state,
        private readonly ?IpmiInfo $info,
        private readonly ?IpmiPower $power,
        private readonly array $sensors,
        private readonly ?IpmiChassis $chassis,
        private readonly ?IpmiSel $sel,
        private readonly bool $vary,
        private readonly string $device = '',
    ) {
    }

    /** The ASUS/AMI capture, varying. */
    public static function demo(): self
    {
        return self::fromCaptures(FakeIpmiCaptures::skynet2(), true);
    }

    /**
     * @param array<string, string> $texts ipmitool outputs keyed mc, fru, lan, dcmi, chassis, sel, sel_last, sensors
     *                                     (missing ones read as "command failed")
     */
    public static function fromCaptures(array $texts, bool $vary = false): self
    {
        $sel = IpmiParser::selInfo($texts['sel'] ?? '');

        return new self(
            IpmiState::Ready,
            IpmiParser::info($texts['mc'] ?? '', $texts['fru'] ?? '', $texts['lan'] ?? '', null),
            IpmiParser::dcmi($texts['dcmi'] ?? ''),
            IpmiParser::sensorList($texts['sensors'] ?? ''),
            IpmiParser::chassis($texts['chassis'] ?? ''),
            $sel?->withLatest(IpmiParser::selLatest($texts['sel_last'] ?? '')),
            $vary,
        );
    }

    /** A BMC that cannot be read: every round answers `$state`. */
    public static function failing(IpmiState $state, string $device = '/dev/ipmi0'): self
    {
        return new self($state, null, null, [], null, null, false, $device);
    }

    public function poll(int $slowMs): ?PromiseInterface
    {
        return resolve($this->next());
    }

    public function sample(): array
    {
        return [$this->next(), $this];
    }

    private function next(): IpmiSnapshot
    {
        $k = $this->step++;
        if ($this->state->failed()) {
            return IpmiSnapshot::failed($this->state, $this->device, $k);
        }
        if (!$this->vary) {
            return new IpmiSnapshot(IpmiState::Ready, $this->info, $this->power, $this->sensors, $this->chassis, $this->sel, '', $k);
        }
        $load = Wave::percent($k, 0.3) / 100.0;
        $power = null;
        if ($this->power !== null) {
            // A real BMC window always holds its own reading: min <= now <= max, avg between.
            $now = round(1300 + 900 * $load);
            $min = min($now, round(1180 + 300 * Wave::percent($k + 7, 1.1) / 100));
            $max = max($now, $this->power->max);
            $avg = max($min, min($max, round(1450 + 400 * Wave::percent($k + 3, 0.7) / 100)));
            $power = new IpmiPower($now, $min, $max, $avg, $this->power->period);
        }
        $ratio = $power === null ? 1.0 : $power->watts / 1950.0;
        $sensors = [];
        foreach ($this->sensors as $i => $s) {
            if ($s->value === null) {
                $sensors[] = $s;
                continue;
            }
            $w = (Wave::percent($k, $i * 0.37) - 50.0) / 50.0; // -1..1
            $v = match ($s->kind) {
                SensorKind::Temperature => round($s->value + $w * (preg_match('/inlet|ambient/i', $s->name) === 1 ? 1.0 : 4.0)),
                SensorKind::FanRpm => round($s->value * (1 + 0.05 * $w) / 10) * 10,
                SensorKind::FanDuty => round($s->value * (1 + 0.1 * $w), 2),
                SensorKind::Voltage => round($s->value * (1 + 0.006 * $w), 3),
                SensorKind::Power => preg_match('/\bPSU|\bPS\b|Power Supply/i', $s->name) === 1
                    ? round($s->value * $ratio / 16) * 16
                    : round($s->value * (1 + 0.15 * $w)),
                default => $s->value,
            };
            $sensors[] = $s->withValue($v);
        }

        return new IpmiSnapshot(IpmiState::Ready, $this->info, $power, $sensors, $this->chassis, $this->sel, '', $k);
    }
}
