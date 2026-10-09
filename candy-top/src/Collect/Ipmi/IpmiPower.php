<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * `ipmitool dcmi power reading`: the instantaneous whole-system draw plus
 * the BMC's own min / max / average over its sampling window (5 s on an
 * AMI board, 300 s on HP iLO 4). `period` is that window in seconds, 0
 * when the BMC did not say.
 */
final class IpmiPower
{
    public function __construct(
        public readonly float $watts,
        public readonly float $min,
        public readonly float $max,
        public readonly float $avg,
        public readonly int $period = 0,
        public readonly bool $active = true,
    ) {
    }
}
