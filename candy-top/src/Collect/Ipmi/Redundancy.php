<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * The state of a discrete redundancy sensor (IPMI sensor-specific offsets
 * for reading type 0x0B), e.g. HP's `Fans` aggregate: `sensor list`
 * prints only its bitmask (`0x1` = offset 0 = Fully Redundant), so the
 * offsets are decoded here. The lowest set bit wins.
 */
enum Redundancy
{
    case Full;
    case Lost;
    case Degraded;
    case NonRedundantSufficient;
    case NonRedundantInsufficient;

    public static function fromState(?int $state): ?self
    {
        if ($state === null || $state === 0) {
            return null;
        }

        return match (true) {
            ($state & 0x01) !== 0 => self::Full,
            ($state & 0x02) !== 0 => self::Lost,
            ($state & 0x04) !== 0 => self::Degraded,
            ($state & 0x18) !== 0 => self::NonRedundantSufficient,
            ($state & 0x20) !== 0 => self::NonRedundantInsufficient,
            ($state & 0xC0) !== 0 => self::Degraded,
            default => null,
        };
    }

    /** Decode `sdr elist`'s text form ("Fully Redundant", "Redundancy Lost", ...). */
    public static function fromText(string $text): ?self
    {
        $t = strtolower($text);

        return match (true) {
            str_contains($t, 'fully redundant') => self::Full,
            str_contains($t, 'redundancy lost') => self::Lost,
            str_contains($t, 'degraded') => self::Degraded,
            str_contains($t, 'insufficient resources') && !str_contains($t, 'from insufficient') => self::NonRedundantInsufficient,
            str_contains($t, 'non-redundant') || str_contains($t, 'sufficient') => self::NonRedundantSufficient,
            default => null,
        };
    }

    /** Lang key under `ipmi.redundancy.*`. */
    public function key(): string
    {
        return match ($this) {
            self::Full => 'ipmi.redundancy.full',
            self::Lost => 'ipmi.redundancy.lost',
            self::Degraded => 'ipmi.redundancy.degraded',
            self::NonRedundantSufficient => 'ipmi.redundancy.sufficient',
            self::NonRedundantInsufficient => 'ipmi.redundancy.insufficient',
        };
    }

    public function severity(): SensorStatus
    {
        return match ($this) {
            self::Full => SensorStatus::Ok,
            self::Degraded, self::NonRedundantSufficient => SensorStatus::NonCritical,
            self::Lost, self::NonRedundantInsufficient => SensorStatus::Critical,
        };
    }
}
