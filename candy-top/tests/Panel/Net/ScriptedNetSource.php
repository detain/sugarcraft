<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Net;

use SugarCraft\Top\Collect\NetInterface;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Source\Source;

/**
 * Plays back a fixed list of {@see NetSnapshot}s, repeating the last one.
 */
final class ScriptedNetSource implements Source
{
    /** @param list<NetSnapshot> $script */
    private function __construct(private readonly array $script, private readonly int $at)
    {
    }

    public static function of(NetSnapshot ...$script): self
    {
        return new self(array_values($script), 0);
    }

    /**
     * One connected `eth0` per tick at the given bytes/s, totals accumulating
     * one second per tick.
     *
     * @param list<array{0: float, 1: float}> $rates [download, upload] per tick
     */
    public static function rates(array $rates): self
    {
        $snaps = [];
        $rx = 0;
        $tx = 0;
        foreach ($rates as [$down, $up]) {
            $rx += (int) max(0, $down);
            $tx += (int) max(0, $up);
            $snaps[] = new NetSnapshot(['eth0' => self::iface('eth0', $down, $up, $rx, $tx)], 'eth0');
        }

        return self::of(...$snaps);
    }

    public static function iface(string $name, float $down, float $up, int $rxTotal, int $txTotal, bool $connected = true, string $ipv4 = '', string $ipv6 = ''): NetInterface
    {
        return new NetInterface($name, $connected, $down, $up, $rxTotal, $txTotal, max(0.0, $down), max(0.0, $up), $ipv4, $ipv6);
    }

    public function sample(): array
    {
        $snap = $this->script[min($this->at, count($this->script) - 1)];

        return [$snap, new self($this->script, $this->at + 1)];
    }
}
