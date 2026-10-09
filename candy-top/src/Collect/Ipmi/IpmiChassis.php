<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * `ipmitool chassis status`: the fault flags the box turns into badges.
 * Every flag is false when the BMC does not report it (older BMCs omit
 * whole lines).
 */
final class IpmiChassis
{
    public function __construct(
        public readonly bool $powerOn,
        public readonly bool $overload = false,
        public readonly bool $mainFault = false,
        public readonly bool $controlFault = false,
        public readonly bool $intrusion = false,
        public readonly bool $driveFault = false,
        public readonly bool $fanFault = false,
        public readonly bool $interlock = false,
        public readonly string $restorePolicy = '',
        public readonly string $lastEvent = '',
    ) {
    }

    /** Any power-path fault (overload, main power, control, interlock). */
    public function powerFault(): bool
    {
        return $this->overload || $this->mainFault || $this->controlFault || $this->interlock;
    }
}
