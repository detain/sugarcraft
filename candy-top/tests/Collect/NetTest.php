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

    public function testAddressesFromInjectedNetGetInterfaces(): void
    {
        $calls = 0;
        $source = static function () use (&$calls): array {
            $calls++;

            return [
                'lo' => ['unicast' => [['flags' => 1, 'family' => 17], ['family' => 2, 'address' => '127.0.0.1'], ['family' => 10, 'address' => '::1']], 'up' => true],
                'eth0' => ['unicast' => [
                    ['family' => 17],
                    ['family' => 10, 'address' => 'fe80::216:3eff:fe27:59b2%eth0'],
                    ['family' => 2, 'address' => '192.0.2.10'],
                    ['family' => 2, 'address' => '192.0.2.11'],
                    ['family' => 10, 'address' => '2001:db8::10'],
                ]],
                'wlan0' => ['unicast' => [['family' => 10, 'address' => 'fe80::1%wlan0'], ['family' => 30, 'address' => 'garbage']]],
                'ghost0' => ['unicast' => [['family' => 2, 'address' => '10.0.0.1']]],
                7 => 'not an interface',
            ];
        };
        [$snap, $net] = Net::new($this->tree->paths(), fn (): float => $this->now, addresses: $source)->sample();

        $eth = $snap->interfaces['eth0'];
        $this->assertSame('192.0.2.10', $eth->ipv4, 'first IPv4 wins');
        $this->assertSame('2001:db8::10', $eth->ipv6, 'global IPv6 preferred over link-local');
        $this->assertSame('192.0.2.10', $eth->ip());
        $wlan = $snap->interfaces['wlan0'];
        $this->assertSame('', $wlan->ipv4);
        $this->assertSame('fe80::1', $wlan->ipv6, 'link-local only as a last resort, zone dropped');
        $this->assertSame('fe80::1', $wlan->ip(), 'btop falls back to IPv6');
        $this->assertSame('127.0.0.1', $snap->interfaces['lo']->ip());
        $this->assertArrayNotHasKey('ghost0', $snap->interfaces, 'addresses never invent interfaces');

        $this->now += 1.0;
        $net->withInterface('eth0')->sample();
        $this->assertSame(2, $calls, 're-read every sample (DHCP/VPN change them)');
    }

    public function testFixtureRootGetsNoHostAddressesByDefault(): void
    {
        [$snap] = $this->net()->sample();

        foreach ($snap->interfaces as $iface) {
            $this->assertSame('', $iface->ip(), $iface->name);
        }
    }

    public function testLiveHostAddressesSmoke(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('net_get_interfaces') || !is_readable('/proc/net/dev')) {
            $this->markTestSkipped('needs Linux /proc and net_get_interfaces()');
        }
        [$snap] = Net::new()->sample();

        $this->assertSame('127.0.0.1', $snap->interfaces['lo']->ipv4 ?? null);
    }
}
