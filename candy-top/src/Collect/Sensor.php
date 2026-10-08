<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One temperature sensor reading in °C. `temp` is Sentinel::UNMEASURED
 * when the input vanished since discovery; `high`/`crit` are the
 * thresholds btop colours against (defaults 80/95 when the hardware
 * publishes none).
 */
final class Sensor
{
    public function __construct(
        public readonly string $name,
        public readonly float $temp,
        public readonly float $high,
        public readonly float $crit,
    ) {
    }
}
