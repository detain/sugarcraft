<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\FreeBsd;

use SugarCraft\Top\Collect\NetInterface;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Collect\Sentinel;

/**
 * Per-interface rx/tx counters and rates from `netstat -i -b -n -W`.
 *
 * Mirrors aristocratos/btop Net::collect (src/freebsd/btop_collect.cpp),
 * which walks getifaddrs() AF_LINK entries for if_data ifi_ibytes /
 * ifi_obytes and IFF_RUNNING. netstat -ib prints exactly those counters:
 *  - the `<Link#N>` row of each interface carries the byte counters
 *    (Ibytes / Obytes, located from the header and counted from the END
 *    of the row, because an interface without a MAC has no Address
 *    column);
 *  - connected = IFF_RUNNING, as btop: one `ifconfig -a` per sample,
 *    flags=<...RUNNING...> (UP alone is not enough — a cable-less NIC is
 *    UP but not RUNNING). When ifconfig fails or does not list an
 *    interface, netstat's trailing '*' (= not IFF_UP, netstat(1)) is the
 *    fallback: '*' → disconnected, else connected;
 *  - the per-address rows that follow give the IPs (btop #1573): the
 *    first IPv4, else the first global IPv6, else a link-local one,
 *    zone suffix ("%lo0") dropped.
 *
 * Rates, the wrap rollover, per-direction tops, user zeroing and the
 * sticky auto-pick (pinned `net_iface`, else most traffic among the
 * connected) behave exactly as {@see \SugarCraft\Top\Collect\Net}. Two
 * children per sample (netstat, ifconfig).
 */
final class Net
{
    /**
     * @param array<string, array<string, int|float>> $state per-interface counter memory
     */
    private function __construct(
        private readonly Probe $probe,
        private readonly array $state,
        private readonly ?float $lastAt,
        private readonly ?string $selected,
        private readonly ?string $pinned,
    ) {
    }

    public static function new(?Probe $probe = null, ?string $iface = null): self
    {
        return new self($probe ?? LiveProbe::new(), [], null, null, $iface === '' ? null : $iface);
    }

    /** Pin an interface (btop `net_iface`); null returns to auto-pick. */
    public function withInterface(?string $iface): self
    {
        return new self($this->probe, $this->state, $this->lastAt, $iface, $iface);
    }

    /** Toggle the totals offset for an interface (btop `z`). */
    public function withZeroed(string $iface): self
    {
        if (!isset($this->state[$iface])) {
            return $this;
        }
        $state = $this->state;
        foreach (['rx', 'tx'] as $dir) {
            $s = $state[$iface];
            $state[$iface][$dir . 'Offset'] = $s[$dir . 'Offset'] > 0 ? 0 : $s[$dir . 'Last'] + $s[$dir . 'Rollover'];
        }

        return new self($this->probe, $state, $this->lastAt, $this->selected, $this->pinned);
    }

    /**
     * @return array{0: NetSnapshot, 1: self}
     */
    public function sample(): array
    {
        $now = $this->probe->monotonic();
        $raw = $this->probe->run(['netstat', '-i', '-b', '-n', '-W']);
        $rows = $raw === null ? null : self::parse($raw);
        if ($rows === null) {
            return [new NetSnapshot([], null), $this];
        }

        $running = self::running($this->probe->run(['ifconfig', '-a']));
        $elapsed = $this->lastAt === null ? 0.0 : $now - $this->lastAt;
        $state = [];
        $interfaces = [];
        foreach ($rows as $name => $row) {
            $s = $this->state[$name] ?? null;
            $fresh = $s === null;
            $s ??= ['rxLast' => 0, 'rxRollover' => 0, 'rxOffset' => 0, 'rxTop' => 0.0,
                'txLast' => 0, 'txRollover' => 0, 'txOffset' => 0, 'txTop' => 0.0];

            $out = [];
            foreach (['rx' => $row['rx'], 'tx' => $row['tx']] as $dir => $value) {
                $last = $s[$dir . 'Last'];
                if ($value < $last) {
                    $s[$dir . 'Rollover'] += $last;
                    $last = 0;
                }
                $rate = !$fresh && $elapsed > 0.0 ? ($value - $last) / $elapsed : Sentinel::UNMEASURED;
                if ($rate > $s[$dir . 'Top']) {
                    $s[$dir . 'Top'] = $rate;
                }
                if ($s[$dir . 'Offset'] > $value + $s[$dir . 'Rollover']) {
                    $s[$dir . 'Offset'] = 0;
                }
                $s[$dir . 'Last'] = $value;
                $out[$dir] = [$rate, $value + $s[$dir . 'Rollover'] - $s[$dir . 'Offset']];
            }
            $state[$name] = $s;

            $interfaces[$name] = new NetInterface(
                $name,
                $running[$name] ?? $row['up'],
                $out['rx'][0],
                $out['tx'][0],
                $out['rx'][1],
                $out['tx'][1],
                (float) $s['rxTop'],
                (float) $s['txTop'],
                $row['ipv4'],
                $row['ipv6'],
            );
        }

        $selected = $this->pick($interfaces);

        return [
            new NetSnapshot($interfaces, $selected),
            new self($this->probe, $state, $now, $selected, $this->pinned),
        ];
    }

    /**
     * @return array<string, array{rx: int, tx: int, up: bool, ipv4: string, ipv6: string}>|null null when the header is missing
     */
    public static function parse(string $text): ?array
    {
        $lines = explode("\n", $text);
        $header = null;
        $out = [];
        $linkLocal = [];
        foreach ($lines as $line) {
            $cols = preg_split('/\s+/', trim($line), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
            if ($cols === []) {
                continue;
            }
            if ($cols[0] === 'Name') {
                $header = $cols;
                continue;
            }
            if ($header === null || count($cols) < 4) {
                continue;
            }
            $ib = array_search('Ibytes', $header, true);
            $ob = array_search('Obytes', $header, true);
            if ($ib === false || $ob === false) {
                return null;
            }
            $raw = $cols[0];
            $name = rtrim($raw, '*');
            if (str_starts_with($cols[2], '<Link#')) {
                $rx = $cols[count($cols) - (count($header) - $ib)] ?? '';
                $tx = $cols[count($cols) - (count($header) - $ob)] ?? '';
                if (!ctype_digit($rx) || !ctype_digit($tx)) {
                    continue;
                }
                $out[$name] = ['rx' => (int) $rx, 'tx' => (int) $tx, 'up' => !str_ends_with($raw, '*'), 'ipv4' => '', 'ipv6' => ''];
                continue;
            }
            if (!isset($out[$name])) {
                continue;
            }
            $address = explode('%', $cols[3], 2)[0];
            if ($out[$name]['ipv4'] === '' && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $out[$name]['ipv4'] = $address;
            } elseif (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                if (preg_match('/^fe[89ab]/i', $address) === 1) {
                    $linkLocal[$name] ??= $address;
                } elseif ($out[$name]['ipv6'] === '') {
                    $out[$name]['ipv6'] = $address;
                }
            }
        }
        if ($header === null) {
            return null;
        }
        foreach ($linkLocal as $name => $address) {
            if ($out[$name]['ipv6'] === '') {
                $out[$name]['ipv6'] = $address;
            }
        }

        return $out;
    }

    /**
     * ifconfig -a header lines: "re0: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST> metric 0 mtu 1500".
     *
     * @return array<string, bool> interface => IFF_RUNNING; [] when ifconfig failed
     */
    public static function running(?string $text): array
    {
        $out = [];
        foreach (explode("\n", $text ?? '') as $line) {
            if (preg_match('/^(\S+):\s+flags=[0-9a-fA-F]+<([^>]*)>/', $line, $m) === 1) {
                $out[$m[1]] = in_array('RUNNING', explode(',', $m[2]), true);
            }
        }

        return $out;
    }

    /**
     * @param array<string, NetInterface> $interfaces
     */
    private function pick(array $interfaces): ?string
    {
        if ($this->pinned !== null && isset($interfaces[$this->pinned])) {
            return $this->pinned;
        }
        if ($this->selected !== null && isset($interfaces[$this->selected])) {
            return $this->selected;
        }
        if ($interfaces === []) {
            return null;
        }
        $sorted = array_values($interfaces);
        usort($sorted, static fn (NetInterface $a, NetInterface $b): int
            => ($b->rxTotal + $b->txTotal) <=> ($a->rxTotal + $a->txTotal));
        foreach ($sorted as $iface) {
            if ($iface->connected) {
                return $iface->name;
            }
        }

        return $sorted[0]->name;
    }
}
