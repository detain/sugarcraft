<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * A `sensor list` sorted into what the box draws: temperatures, fans,
 * voltages, power supplies, other power rails, currents and the fan
 * redundancy state. Sensors without a reading are dropped (disabled CPU2
 * on a one-socket board, empty DIMM slots, an absent PSU's watts), and
 * vendor duplicates are folded:
 *  - HP iLO prints every fan three times (`Fan 1` %, `Fan 1 DutyCycle` %,
 *    `Fan 1 Presence` discrete) — only `Fan 1` is kept;
 *  - HP's `Power Meter` / `PwrMeter Output` are the same figure.
 */
final class IpmiReadings
{
    /** Names that make a power / discrete sensor part of a numbered supply. */
    private const PSU = '/\b(?:PSU|PS|PWS|Power\s*Supply)\s*[_ -]?(\d+)\b/i';

    /**
     * @param list<IpmiSensor> $temps
     * @param list<IpmiSensor> $fans
     * @param list<IpmiSensor> $volts
     * @param list<IpmiPsu>    $psus
     * @param list<IpmiSensor> $rails
     * @param list<IpmiSensor> $currents
     */
    public function __construct(
        public readonly array $temps = [],
        public readonly array $fans = [],
        public readonly array $volts = [],
        public readonly array $psus = [],
        public readonly array $rails = [],
        public readonly array $currents = [],
        public readonly ?Redundancy $redundancy = null,
    ) {
    }

    /** @param list<IpmiSensor> $sensors */
    public static function of(array $sensors): self
    {
        $temps = $fans = $volts = $rails = $currents = [];
        /** @var array<int, IpmiPsu> $psus */
        $psus = [];
        $redundancy = null;
        $fanNames = [];
        foreach ($sensors as $s) {
            if ($s->kind->isFan() && $s->value !== null) {
                $fanNames[strtolower($s->name)] = true;
            }
        }
        $railValues = [];
        foreach ($sensors as $s) {
            $psu = preg_match(self::PSU, $s->name, $m) === 1 ? (int) $m[1] : null;
            if ($psu !== null && ($s->kind === SensorKind::Power || $s->kind === SensorKind::Discrete || $s->kind === SensorKind::Other)) {
                $psus[$psu] ??= new IpmiPsu($psu);
                if ($s->kind === SensorKind::Power && $s->value !== null) {
                    $psus[$psu] = preg_match('/\bout(put)?\b/i', $s->name) === 1 ? $psus[$psu]->withOut($s) : $psus[$psu]->withIn($s);
                } elseif ($s->kind === SensorKind::Discrete && preg_match('/presen/i', $s->name) === 1) {
                    if (preg_match('/absent|removed/i', $s->text) === 1) {
                        $psus[$psu] = $psus[$psu]->withPresent(false);
                    } elseif (preg_match('/present|detected|inserted/i', $s->text) === 1) {
                        $psus[$psu] = $psus[$psu]->withPresent(true);
                    } elseif ($s->state !== null) {
                        // Generic "device present" offsets: bit 1 = present, bit 0 = absent.
                        $psus[$psu] = $psus[$psu]->withPresent(($s->state & 0x02) !== 0);
                    }
                }
                continue;
            }
            if ($s->kind === SensorKind::Discrete) {
                if ($redundancy === null && preg_match('/^fans?$|redundan/i', $s->name) === 1) {
                    $redundancy = $s->text !== '' ? Redundancy::fromText($s->text) : Redundancy::fromState($s->state);
                }
                continue;
            }
            if (!$s->reads()) {
                continue;
            }
            switch ($s->kind) {
                case SensorKind::Temperature:
                    $temps[] = $s;
                    break;
                case SensorKind::FanRpm:
                case SensorKind::FanDuty:
                    if (preg_match('/^(.*?)\s*(duty\s*cycle|duty|pwm)$/i', $s->name, $d) === 1 && isset($fanNames[strtolower($d[1])])) {
                        break;
                    }
                    $fans[] = $s;
                    break;
                case SensorKind::Voltage:
                    $volts[] = $s;
                    break;
                case SensorKind::Power:
                    $key = (string) $s->value;
                    if (preg_match('/out(put)?/i', $s->name) === 1 && isset($railValues[$key])) {
                        break;
                    }
                    $railValues[$key] = true;
                    $rails[] = $s;
                    break;
                case SensorKind::Current:
                    $currents[] = $s;
                    break;
                default:
                    break;
            }
        }
        ksort($psus);

        return new self($temps, $fans, $volts, array_values($psus), $rails, $currents, $redundancy);
    }

    /** Supplies that read or are present (an absent bay is listed separately by {@see absentPsus()}). */
    public function livePsus(): array
    {
        return array_values(array_filter($this->psus, static fn (IpmiPsu $p): bool => $p->live()));
    }

    /** @return list<IpmiPsu> bays a PSU-named sensor exists for that read nothing */
    public function absentPsus(): array
    {
        return array_values(array_filter($this->psus, static fn (IpmiPsu $p): bool => !$p->live()));
    }

    /** Sum of the supplies' rated output; null unless every live supply states one. */
    public function capacity(): ?float
    {
        $sum = 0.0;
        $live = $this->livePsus();
        foreach ($live as $p) {
            $cap = $p->capacity();
            if ($cap === null) {
                return null;
            }
            $sum += $cap;
        }

        return $live === [] ? null : $sum;
    }

    /** Sum of the supplies' input watts (the wall draw), null when none reads. */
    public function psuInput(): ?float
    {
        $sum = null;
        foreach ($this->psus as $p) {
            if (($w = $p->inWatts()) !== null) {
                $sum = ($sum ?? 0.0) + $w;
            }
        }

        return $sum;
    }

    /** The worst state over every reading sensor and the redundancy. */
    public function worst(): SensorStatus
    {
        $s = $this->redundancy?->severity() ?? SensorStatus::Ok;
        foreach ([...$this->temps, ...$this->fans, ...$this->volts, ...$this->rails, ...$this->currents] as $sensor) {
            $s = SensorStatus::worst($s, $sensor->severity());
        }
        foreach ($this->psus as $p) {
            $s = SensorStatus::worst($s, $p->severity());
        }

        return $s;
    }

    /** How many reading sensors sit at nc or worse. */
    public function alarms(): int
    {
        $n = 0;
        foreach ([...$this->temps, ...$this->fans, ...$this->volts, ...$this->rails, ...$this->currents] as $sensor) {
            if ($sensor->severity()->severity() > 0) {
                $n++;
            }
        }

        return $n;
    }
}
