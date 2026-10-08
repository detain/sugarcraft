<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * One interface's counters for a sample. Rates are bytes/second over the
 * interval since the previous sample (Sentinel::UNMEASURED on the first
 * sample); totals are bytes since the collector started watching, minus
 * any user zeroing, and survive 32-bit counter wraps.
 *
 * `ipv4` / `ipv6` (btop #1573 prereq): the interface's first IPv4 address
 * and its first global IPv6 address (link-local fe80::/10 only when there
 * is nothing else), from getifaddrs via net_get_interfaces(); '' when the
 * interface has none. btop's net box shows ipv4, falling back to ipv6 —
 * that is ip(). Whether to show it at all (net_hide_ip) is the view's call.
 */
final class NetInterface
{
    public function __construct(
        public readonly string $name,
        public readonly bool $connected,
        public readonly float $rxRate,
        public readonly float $txRate,
        public readonly int $rxTotal,
        public readonly int $txTotal,
        public readonly float $rxTop,
        public readonly float $txTop,
        public readonly string $ipv4 = '',
        public readonly string $ipv6 = '',
    ) {
    }

    /** The address btop's net box prints: IPv4, else IPv6, else ''. */
    public function ip(): string
    {
        return $this->ipv4 !== '' ? $this->ipv4 : $this->ipv6;
    }
}
