<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Battery state for the cpu box's border embed. `seconds` is time to empty
 * while discharging and time to full while charging; it, `percent` and
 * `watts` take the sentinels when the hardware does not publish them.
 * `status` is lower-case ("charging", "discharging", "full", ...).
 */
final class BatterySnapshot
{
    public function __construct(
        public readonly string $name,
        public readonly int $percent,
        public readonly string $status,
        public readonly int $seconds,
        public readonly float $watts,
    ) {
    }

    public static function absent(): self
    {
        return new self(Sentinel::UNAVAILABLE, Sentinel::UNMEASURED_INT, Sentinel::UNAVAILABLE, Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED);
    }

    public function present(): bool
    {
        return $this->percent >= 0;
    }
}
