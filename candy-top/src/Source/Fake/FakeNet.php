<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

use SugarCraft\Top\Collect\NetInterface;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Deterministic {@see NetSnapshot}s for one connected interface `eth0`:
 * rates in bytes/s, totals accumulating per `$intervalSec`.
 */
final class FakeNet implements Source
{
    private function __construct(
        private readonly int $step,
        private readonly int $rxTotal,
        private readonly int $txTotal,
        private readonly float $rxTop,
        private readonly float $txTop,
        private readonly float $intervalSec,
    ) {
    }

    public static function new(float $intervalSec = 2.0): self
    {
        return new self(0, 0, 0, 0.0, 0.0, $intervalSec);
    }

    public function sample(): array
    {
        $rx = Wave::percent($this->step, 0.2) * 20_480.0;
        $tx = Wave::percent($this->step, 1.7) * 4_096.0;
        $rxTotal = $this->rxTotal + (int) ($rx * $this->intervalSec);
        $txTotal = $this->txTotal + (int) ($tx * $this->intervalSec);
        $rxTop = max($this->rxTop, $rx);
        $txTop = max($this->txTop, $tx);
        $iface = new NetInterface('eth0', true, $rx, $tx, $rxTotal, $txTotal, $rxTop, $txTop);

        return [
            new NetSnapshot(['eth0' => $iface], 'eth0'),
            new self($this->step + 1, $rxTotal, $txTotal, $rxTop, $txTop, $this->intervalSec),
        ];
    }
}
