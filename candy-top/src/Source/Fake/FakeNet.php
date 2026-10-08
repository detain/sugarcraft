<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\NetInterface;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see NetSnapshot}s, in /proc/net/dev order: a quiet
 * loopback `lo`, the busy connected `eth0` (192.0.2.10, a documentation
 * address) that the auto-pick selects, and a disconnected idle `wlan0` —
 * enough for the net panel's `b`/`n` cycling and address readout. Rates are
 * bytes/s, totals accumulate per `$intervalSec`.
 */
final class FakeNet implements Source
{
    /**
     * @param array<string, array{0: int, 1: int, 2: float, 3: float}> $acc per interface [rxTotal, txTotal, rxTop, txTop]
     */
    private function __construct(
        private readonly int $step,
        private readonly array $acc,
        private readonly float $intervalSec,
    ) {
    }

    public static function new(float $intervalSec = 2.0): self
    {
        return new self(0, [], $intervalSec);
    }

    public function sample(): array
    {
        $specs = [
            // name => [connected, rx bytes/s, tx bytes/s, ipv4, ipv6]
            'lo' => [true, Wave::percent($this->step, 3.1) * 64.0, Wave::percent($this->step, 3.1) * 64.0, '127.0.0.1', '::1'],
            'eth0' => [true, Wave::percent($this->step, 0.2) * 20_480.0, Wave::percent($this->step, 1.7) * 4_096.0, '192.0.2.10', '2001:db8::10'],
            'wlan0' => [false, 0.0, 0.0, '', ''],
        ];
        $acc = [];
        $interfaces = [];
        foreach ($specs as $name => [$connected, $rx, $tx, $v4, $v6]) {
            [$rxTotal, $txTotal, $rxTop, $txTop] = $this->acc[$name] ?? [0, 0, 0.0, 0.0];
            $rxTotal += (int) ($rx * $this->intervalSec);
            $txTotal += (int) ($tx * $this->intervalSec);
            $rxTop = max($rxTop, $rx);
            $txTop = max($txTop, $tx);
            $acc[$name] = [$rxTotal, $txTotal, $rxTop, $txTop];
            $interfaces[$name] = new NetInterface($name, $connected, $rx, $tx, $rxTotal, $txTotal, $rxTop, $txTop, $v4, $v6);
        }

        return [
            new NetSnapshot($interfaces, 'eth0'),
            new self($this->step + 1, $acc, $this->intervalSec),
        ];
    }
}
