<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Net;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class NetTest extends TestCase
{
    private FixtureTree $tree;
    private float $now = 100.0;

    protected function setUp(): void
    {
        $this->tree = FixtureTree::copy();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    private function net(?string $iface = null): Net
    {
        return Net::new($this->tree->paths(), fn (): float => $this->now, $iface);
    }

    private function writeDev(int $ethRx, int $ethTx, int $wlanRx = 90000000): void
    {
        $this->tree->write('proc/net/dev', "Inter-| Receive | Transmit\n face |bytes ...\n"
            . "    lo: 1000 10 0 0 0 0 0 0 1000 10 0 0 0 0 0 0\n"
            . "  eth0: {$ethRx} 1 0 0 0 0 0 0 {$ethTx} 1 0 0 0 0 0 0\n"
            . " wlan0: {$wlanRx} 1 0 0 0 0 0 0 9000000 1 0 0 0 0 0 0\n");
    }

    public function testFirstSampleHasTotalsButNoRates(): void
    {
        [$snap] = $this->net()->sample();

        $this->assertSame(['lo', 'eth0', 'wlan0'], $snap->names());
        $eth = $snap->interfaces['eth0'];
        $this->assertTrue($eth->connected);
        $this->assertSame(5000000, $eth->rxTotal);
        $this->assertSame(1000000, $eth->txTotal);
        $this->assertSame(Sentinel::UNMEASURED, $eth->rxRate);
        $this->assertFalse($snap->interfaces['wlan0']->connected, 'operstate down');
    }

    public function testAutoPickPrefersConnectedOverHighestTotal(): void
    {
        [$snap] = $this->net()->sample();

        // wlan0 has the biggest total but is down; eth0 outranks lo.
        $this->assertSame('eth0', $snap->selectedName);
        $this->assertSame('eth0', $snap->selected()?->name);
    }

    public function testNoConnectedInterfaceFallsBackToHighestTotal(): void
    {
        $this->tree->remove('sys/class/net');
        [$snap] = $this->net()->sample();

        $this->assertSame('wlan0', $snap->selectedName);
    }

    public function testPinnedInterfaceWinsAndPickIsStickyUntilItVanishes(): void
    {
        [$snap, $net] = $this->net('lo')->sample();
        $this->assertSame('lo', $snap->selectedName);

        [$snap, $net] = $net->withInterface(null)->sample();
        $this->assertSame('eth0', $snap->selectedName);

        // eth0 now has the smallest total, but the pick is sticky.
        $this->writeDev(1, 1);
        [$snap, $net] = $net->sample();
        $this->assertSame('eth0', $snap->selectedName);

        $this->tree->write('proc/net/dev', "h\nh\n    lo: 1 0 0 0 0 0 0 0 1 0 0 0 0 0 0 0\n");
        [$snap] = $net->sample();
        $this->assertSame('lo', $snap->selectedName);
    }

    public function testRatesUseInjectedClockAndTrackTop(): void
    {
        [, $net] = $this->net()->sample();
        $this->now += 2.0;
        $this->writeDev(5000000 + 4000, 1000000 + 1000);
        [$snap, $net] = $net->sample();

        $eth = $snap->interfaces['eth0'];
        $this->assertSame(2000.0, $eth->rxRate);
        $this->assertSame(500.0, $eth->txRate);
        $this->assertSame(2000.0, $eth->rxTop);

        $this->now += 1.0;
        $this->writeDev(5000000 + 4500, 1000000 + 1000);
        [$snap] = $net->sample();
        $this->assertSame(500.0, $snap->interfaces['eth0']->rxRate);
        $this->assertSame(2000.0, $snap->interfaces['eth0']->rxTop, 'top is the max ever seen');
    }

    public function testCounterWrapBanksRolloverAndKeepsTotalsMonotonic(): void
    {
        [, $net] = $this->net()->sample();
        $this->now += 1.0;
        $this->writeDev(300, 1000000);
        [$snap] = $net->sample();

        $eth = $snap->interfaces['eth0'];
        $this->assertSame(300.0, $eth->rxRate);
        $this->assertSame(5000300, $eth->rxTotal);
    }

    public function testZeroingTogglesTotalsOffset(): void
    {
        [, $net] = $this->net()->sample();
        $net = $net->withZeroed('eth0');
        $this->writeDev(5000100, 1000000);
        [$snap, $net] = $net->sample();
        $this->assertSame(100, $snap->interfaces['eth0']->rxTotal);

        [$snap] = $net->withZeroed('eth0')->sample();
        $this->assertSame(5000100, $snap->interfaces['eth0']->rxTotal);
        $this->assertSame($net, $net->withZeroed('nope'));
    }

    public function testZeroElapsedTimeIsUnmeasured(): void
    {
        [, $net] = $this->net()->sample();
        [$snap] = $net->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->interfaces['eth0']->rxRate);
    }

    public function testMissingNetDevIsEmpty(): void
    {
        $this->tree->remove('proc/net/dev');
        $net = $this->net();
        [$snap, $next] = $net->sample();

        $this->assertSame([], $snap->interfaces);
        $this->assertNull($snap->selected());
        $this->assertSame($net, $next);
    }
}
