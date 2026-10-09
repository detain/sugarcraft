<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * What an IPMI sensor measures, classified by the unit ipmitool prints
 * (`sensor list` column 3). Names differ wildly between BMC vendors
 * (`CPU1 Temperature`, `02-CPU 1`), units do not.
 */
enum SensorKind
{
    case Temperature;
    case Voltage;
    /** A fan reported in RPM (AMI, Supermicro, Dell). */
    case FanRpm;
    /** A fan reported in percent duty cycle (HP iLO). */
    case FanDuty;
    case Power;
    case Current;
    /** A percent reading that is not a fan (utilisation sensors). */
    case Percent;
    /** A state sensor: hex bitmask in the value column, `discrete` unit. */
    case Discrete;
    case Other;

    /**
     * Classify by ipmitool's unit text; `$name` only separates a fan's
     * percent duty from any other percent reading.
     */
    public static function classify(string $unit, string $name): self
    {
        $u = strtolower(trim($unit));

        return match (true) {
            $u === 'discrete' => self::Discrete,
            str_starts_with($u, 'degrees') => self::Temperature,
            $u === 'volts' => self::Voltage,
            $u === 'rpm' => self::FanRpm,
            $u === 'percent' && preg_match('/\bfan/i', $name) === 1 => self::FanDuty,
            $u === 'percent' => self::Percent,
            $u === 'watts' => self::Power,
            $u === 'amps' => self::Current,
            default => self::Other,
        };
    }

    public function isFan(): bool
    {
        return $this === self::FanRpm || $this === self::FanDuty;
    }
}
