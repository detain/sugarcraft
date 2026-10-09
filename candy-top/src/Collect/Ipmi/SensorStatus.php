<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * ipmitool's threshold status word (`sensor list` column 4): ok, the
 * non-critical / critical / non-recoverable crossings, `ns` (no sensor,
 * disabled) and anything else (`na`, a discrete sensor's hex state).
 */
enum SensorStatus: int
{
    case Ok = 0;
    case NonCritical = 1;
    case Critical = 2;
    case NonRecoverable = 3;
    case NoSensor = -1;
    case Unknown = -2;

    public static function parse(string $word): self
    {
        return match (strtolower(trim($word))) {
            'ok' => self::Ok,
            'nc', 'lnc', 'unc' => self::NonCritical,
            'cr', 'lcr', 'ucr' => self::Critical,
            'nr', 'lnr', 'unr' => self::NonRecoverable,
            'ns' => self::NoSensor,
            default => self::Unknown,
        };
    }

    /** 0 ok / unknown, 1 nc, 2 cr, 3 nr: how loud the box should be. */
    public function severity(): int
    {
        return max(0, $this->value);
    }

    /** The short word drawn next to a reading (ipmitool's own vocabulary). */
    public function word(): string
    {
        return match ($this) {
            self::Ok => 'ok',
            self::NonCritical => 'nc',
            self::Critical => 'cr',
            self::NonRecoverable => 'nr',
            self::NoSensor => 'ns',
            self::Unknown => 'na',
        };
    }

    public static function worst(self $a, self $b): self
    {
        return $b->severity() > $a->severity() ? $b : $a;
    }
}
