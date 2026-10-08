<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect;

/**
 * Per-interface rx/tx counters and rates from /proc/net/dev.
 *
 * Mirrors aristocratos/btop Net::collect (src/linux/btop_collect.cpp):
 *  - a counter that goes backwards is a wrap, so the old value is banked
 *    into a rollover and the new reading counts from zero;
 *  - rate = Δbytes / Δseconds of the injected clock;
 *  - the per-direction top rate is the max rate ever seen;
 *  - auto-pick: a pinned interface (btop `net_iface`) when present, else
 *    interfaces sorted by rx+tx total descending, the first connected one
 *    wins, else the first at all — and the pick is sticky until that
 *    interface disappears, so the panel does not hop on every burst.
 *
 * btop reads /sys/class/net/<if>/statistics and getifaddrs(); here the
 * counters come from /proc/net/dev (one read for every interface) and
 * "connected" (btop: IFF_RUNNING) from sysfs `carrier`, falling back to
 * `operstate` up/unknown. Autoscale hysteresis is NOT here — that is
 * sugar-dash NetAutoScale's job on the rate stream.
 */
final class Net
{
    /**
     * @param \Closure(): float                    $clock monotonic seconds
     * @param array<string, array<string, int|float>> $state per-interface counter memory
     */
    private function __construct(
        private readonly Paths $paths,
        private readonly \Closure $clock,
        private readonly array $state,
        private readonly ?float $lastAt,
        private readonly ?string $selected,
        private readonly ?string $pinned,
    ) {
    }

    /**
     * @param (\Closure(): float)|null $clock defaults to hrtime-based monotonic seconds
     */
    public static function new(?Paths $paths = null, ?\Closure $clock = null, ?string $iface = null): self
    {
        return new self(
            $paths ?? Paths::system(),
            $clock ?? static fn (): float => hrtime(true) / 1e9,
            [],
            null,
            null,
            $iface === '' ? null : $iface,
        );
    }

    /**
     * Pin an interface (btop `net_iface`, or cycling with b/n). Null
     * returns to auto-pick on the next sample.
     */
    public function withInterface(?string $iface): self
    {
        return new self($this->paths, $this->clock, $this->state, $this->lastAt, $iface, $iface);
    }

    /**
     * Toggle the totals offset for an interface (btop `z`): totals restart
     * from zero, and a second toggle restores the full count.
     */
    public function withZeroed(string $iface): self
    {
        if (!isset($this->state[$iface])) {
            return $this;
        }
        $state = $this->state;
        $s = $state[$iface];
        foreach (['rx', 'tx'] as $dir) {
            $s[$dir . 'Offset'] = $s[$dir . 'Offset'] > 0 ? 0 : $s[$dir . 'Last'] + $s[$dir . 'Rollover'];
        }
        $state[$iface] = $s;

        return new self($this->paths, $this->clock, $state, $this->lastAt, $this->selected, $this->pinned);
    }

    /**
     * @return array{0: NetSnapshot, 1: self}
     */
    public function sample(): array
    {
        $now = ($this->clock)();
        $raw = Read::file($this->paths->proc('net/dev'));
        if ($raw === null) {
            return [new NetSnapshot([], null), $this];
        }

        $elapsed = $this->lastAt === null ? 0.0 : $now - $this->lastAt;
        $state = [];
        $interfaces = [];

        foreach (explode("\n", $raw) as $line) {
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue; // the two header rows
            }
            $name = trim(substr($line, 0, $colon));
            $cols = preg_split('/\s+/', trim(substr($line, $colon + 1))) ?: [];
            if ($name === '' || count($cols) < 9) {
                continue;
            }

            $s = $this->state[$name] ?? null;
            $fresh = $s === null;
            $s ??= ['rxLast' => 0, 'rxRollover' => 0, 'rxOffset' => 0, 'rxTop' => 0.0,
                'txLast' => 0, 'txRollover' => 0, 'txOffset' => 0, 'txTop' => 0.0];

            $out = [];
            foreach (['rx' => 0, 'tx' => 8] as $dir => $col) {
                $value = (int) $cols[$col];
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
                $this->connected($name),
                $out['rx'][0],
                $out['tx'][0],
                $out['rx'][1],
                $out['tx'][1],
                (float) $s['rxTop'],
                (float) $s['txTop'],
            );
        }

        $selected = $this->pick($interfaces);

        return [
            new NetSnapshot($interfaces, $selected),
            new self($this->paths, $this->clock, $state, $now, $selected, $this->pinned),
        ];
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
        // usort is stable in PHP 8, so equal totals keep /proc order.
        usort($sorted, static fn (NetInterface $a, NetInterface $b): int
            => ($b->rxTotal + $b->txTotal) <=> ($a->rxTotal + $a->txTotal));
        foreach ($sorted as $iface) {
            if ($iface->connected) {
                return $iface->name;
            }
        }

        return $sorted[0]->name;
    }

    private function connected(string $name): bool
    {
        $carrier = Read::line($this->paths->sys("class/net/{$name}/carrier"));
        if ($carrier !== null) {
            return $carrier === '1';
        }

        return in_array(Read::line($this->paths->sys("class/net/{$name}/operstate")), ['up', 'unknown'], true);
    }
}
