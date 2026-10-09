<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

/**
 * Whether the BMC can be read, and if not, why. Every state but
 * {@see Starting} and {@see Ready} puts the collector into backoff: the
 * box shows the one-line reason and stops polling until the next retry.
 */
enum IpmiState
{
    /** Nothing tried yet (the first round is in flight). */
    case Starting;
    case Ready;
    /** No `ipmitool` executable on PATH or in the sbin dirs. */
    case NoTool;
    /** No IPMI device node (/dev/ipmi0, /dev/ipmi/0, /dev/ipmidev/0): no BMC, or the ipmi_devintf/ipmi(4) driver is not loaded. */
    case NoDevice;
    /** The device node exists but this user cannot open it (it is root-only by default). */
    case NoAccess;
    /** ipmitool ran and failed: the BMC refused or did not answer. */
    case NoResponse;
    /** ipmitool was still running at its deadline and was killed (a hung BMC). */
    case TimedOut;
    /** An earlier, killed ipmitool has still not exited (stuck in the driver): no new child until it is reaped. */
    case Stuck;

    /** Lang key of the one-line reason under `ipmi.state.*`. */
    public function key(): string
    {
        return match ($this) {
            self::Starting => 'ipmi.state.starting',
            self::Ready => 'ipmi.state.ready',
            self::NoTool => 'ipmi.state.no_tool',
            self::NoDevice => 'ipmi.state.no_device',
            self::NoAccess => 'ipmi.state.no_access',
            self::NoResponse => 'ipmi.state.no_response',
            self::TimedOut => 'ipmi.state.timeout',
            self::Stuck => 'ipmi.state.stuck',
        };
    }

    public function failed(): bool
    {
        return $this !== self::Starting && $this !== self::Ready;
    }
}
