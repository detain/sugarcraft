<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\FreeBsd\Net;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

final class NetTest extends TestCase
{
    private function probe(): FixtureProbe
    {
        return new FixtureProbe('', [
            'netstat' => [FixtureProbe::fixture('netstat-ibn.txt'), FixtureProbe::fixture('netstat-ibn-next.synthetic.txt')],
            'ifconfig' => FixtureProbe::fixture('ifconfig-a.synthetic.txt'),
        ], [100.0, 102.0]);
    }

    public function testReferenceLinkRowsGiveCountersAndAddressRowsGiveIps(): void
    {
        $probe = $this->probe();
        [$snap] = Net::new($probe)->sample();

        $this->assertSame(['re0', 'lo0'], $snap->names());
        $re0 = $snap->interfaces['re0'];
        $this->assertSame(10224076134, $re0->rxTotal);
        $this->assertSame(9129830082, $re0->txTotal);
        $this->assertSame(Sentinel::UNMEASURED, $re0->rxRate, 'first sight has no interval');
        $this->assertTrue($re0->connected);
        $this->assertSame('66.45.228.251', $re0->ipv4);
        $this->assertSame('127.0.0.1', $snap->interfaces['lo0']->ipv4);
        $this->assertSame('::1', $snap->interfaces['lo0']->ipv6, 'global ::1 beats link-local fe80::1');
        $this->assertSame('re0', $snap->selectedName, 'most traffic among the connected');
        $this->assertSame([['netstat', '-i', '-b', '-n', '-W'], ['ifconfig', '-a']], $probe->runs);
    }

    public function testRatesOverTheProbeClock(): void
    {
        $probe = $this->probe();
        [, $net] = Net::new($probe)->sample();
        $probe->advance();
        [$snap] = $net->sample();

        $re0 = $snap->interfaces['re0'];
        $this->assertEqualsWithDelta(100000.0, $re0->rxRate, 1e-6, '200000 bytes over 2 s');
        $this->assertEqualsWithDelta(50000.0, $re0->txRate, 1e-6);
        $this->assertEqualsWithDelta(100000.0, $re0->rxTop, 1e-6);
        $this->assertSame(0.0, $snap->interfaces['lo0']->rxRate);
    }

    public function testConnectedIsIffRunningFromIfconfig(): void
    {
        // em0 is UP (no '*' in netstat) but has no carrier: not RUNNING.
        $netstat = str_replace('em0*', 'em0 ', FixtureProbe::fixture('netstat-ibn-down-and-macless.synthetic.txt'));
        $probe = new FixtureProbe('', ['netstat' => $netstat, 'ifconfig' => FixtureProbe::fixture('ifconfig-a-no-carrier.synthetic.txt')]);
        [$snap] = Net::new($probe)->sample();

        $this->assertFalse($snap->interfaces['em0']->connected, 'UP without RUNNING is not connected (btop IFF_RUNNING)');
        $this->assertTrue($snap->interfaces['tun0']->connected);
        $this->assertSame(['em0' => false, 'tun0' => true], Net::running(FixtureProbe::fixture('ifconfig-a-no-carrier.synthetic.txt')));
        $this->assertSame([], Net::running(null));
    }

    public function testDownMarkerFallbackWhenIfconfigFails(): void
    {
        $probe = new FixtureProbe('', ['netstat' => FixtureProbe::fixture('netstat-ibn-down-and-macless.synthetic.txt')]);
        [$snap] = Net::new($probe)->sample();

        $this->assertFalse($snap->interfaces['em0']->connected, "fallback: '*' = not UP (netstat(1))");
        $this->assertSame(50000, $snap->interfaces['em0']->rxTotal);
        $this->assertTrue($snap->interfaces['tun0']->connected);
        $this->assertSame(900000, $snap->interfaces['tun0']->rxTotal, 'columns counted from the end when Address is empty');
        $this->assertSame(800000, $snap->interfaces['tun0']->txTotal);
        $this->assertSame('2001:db8::5', $snap->interfaces['tun0']->ipv6);
        $this->assertSame('tun0', $snap->selectedName);
    }

    public function testPinnedInterfaceAndZeroing(): void
    {
        $probe = $this->probe();
        [$snap, $net] = Net::new($probe, 'lo0')->sample();
        $this->assertSame('lo0', $snap->selectedName);

        $probe->advance();
        [$snap] = $net->withInterface(null)->withZeroed('re0')->sample();
        $this->assertSame(200000, $snap->interfaces['re0']->rxTotal, 'totals restart from the zeroing point');
    }

    public function testCounterDecreaseIsAWrapBankedIntoTheTotal(): void
    {
        $row = static fn (int $rx, int $tx): string => "Name Mtu Network Address Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll\n"
            . "re0 1500 <Link#1> d0:27:88:31:70:23 1 0 0 {$rx} 1 0 {$tx} 0\n";
        $probe = new FixtureProbe('', ['netstat' => [$row(1000, 500), $row(300, 800)]], [0.0, 1.0]);
        [, $net] = Net::new($probe)->sample();
        $probe->advance();
        [$snap] = $net->sample();

        $re0 = $snap->interfaces['re0'];
        $this->assertSame(1300, $re0->rxTotal, 'the old reading is banked as rollover; the new one counts from zero');
        $this->assertEqualsWithDelta(300.0, $re0->rxRate, 1e-9, 'never a negative rate');
        $this->assertSame(800, $re0->txTotal);
        $this->assertEqualsWithDelta(300.0, $re0->txRate, 1e-9);
    }

    public function testInterfaceDisappearsAndReappearsFresh(): void
    {
        $both = FixtureProbe::fixture('netstat-ibn.txt');
        $loOnly = implode("\n", array_filter(explode("\n", $both), static fn (string $l): bool => !str_starts_with($l, 're0')));
        $probe = new FixtureProbe('', ['netstat' => [$both, $loOnly, FixtureProbe::fixture('netstat-ibn-next.synthetic.txt')]], [0.0, 2.0, 4.0]);
        [$first, $net] = Net::new($probe)->sample();
        $this->assertSame('re0', $first->selectedName);

        $probe->advance();
        [$gone, $net] = $net->sample();
        $this->assertSame(['lo0'], $gone->names());
        $this->assertSame('lo0', $gone->selectedName, 'the vanished pick is re-picked');

        $probe->advance();
        [$back] = $net->sample();
        $this->assertSame(Sentinel::UNMEASURED, $back->interfaces['re0']->rxRate, 'a returning interface starts fresh: no rate across the gap');
        $this->assertSame(10224276134, $back->interfaces['re0']->rxTotal);
        $this->assertSame('lo0', $back->selectedName, 'the pick stays sticky while it exists');
    }

    public function testFailedNetstatIsAnEmptySnapshot(): void
    {
        $net = Net::new(new FixtureProbe());
        [$snap, $next] = $net->sample();

        $this->assertSame([], $snap->interfaces);
        $this->assertNull($snap->selectedName);
        $this->assertSame($net, $next);
    }

    public function testIfconfigRidesTheRunningTtlMemo(): void
    {
        // Frames: t=100 probe, t=102 memo hit (2 s < 5 s TTL), t=106 re-probe.
        $probe = new FixtureProbe('', [
            'netstat' => [FixtureProbe::fixture('netstat-ibn.txt'), FixtureProbe::fixture('netstat-ibn-next.synthetic.txt')],
            'ifconfig' => FixtureProbe::fixture('ifconfig-a.synthetic.txt'),
        ], [100.0, 102.0, 106.0]);
        [, $net] = Net::new($probe)->sample();
        $probe->advance();
        [, $net] = $net->sample();
        $probe->advance();
        [, $net] = $net->sample();

        $this->assertSame([
            ['netstat', '-i', '-b', '-n', '-W'],
            ['ifconfig', '-a'],
            ['netstat', '-i', '-b', '-n', '-W'],
            ['netstat', '-i', '-b', '-n', '-W'],
            ['ifconfig', '-a'],
        ], $probe->runs, 'one ifconfig per TTL window, netstat every sample');
    }

    public function testFailedIfconfigIsNeverMemoized(): void
    {
        // em0 is UP without '*' in netstat but has no carrier (not RUNNING).
        // Sample 1: ifconfig fails → netstat-'*' fallback says connected.
        // Sample 2 sits inside any window a success would have opened, yet
        // re-probes: the recovered ifconfig answers on the very next sample.
        $netstat = str_replace('em0*', 'em0 ', FixtureProbe::fixture('netstat-ibn-down-and-macless.synthetic.txt'));
        $probe = new FixtureProbe('', [
            'netstat' => [$netstat],
            'ifconfig' => [null, FixtureProbe::fixture('ifconfig-a-no-carrier.synthetic.txt')],
        ], [100.0, 102.0]);
        [, $net] = Net::new($probe)->sample();
        $this->assertTrue($net->sample()[0]->interfaces['em0']->connected, 'failed probe falls back to the UP marker');
        $probe->advance();
        [$snap] = $net->sample();

        $this->assertFalse($snap->interfaces['em0']->connected, 'recovered ifconfig answers on the next sample');
        $this->assertSame([
            ['netstat', '-i', '-b', '-n', '-W'],
            ['ifconfig', '-a'],
            ['netstat', '-i', '-b', '-n', '-W'],
            ['ifconfig', '-a'],
            ['netstat', '-i', '-b', '-n', '-W'],
            ['ifconfig', '-a'],
        ], $probe->runs, 'a failure opens no TTL window');
    }
}
