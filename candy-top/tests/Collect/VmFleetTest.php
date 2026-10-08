<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\VmFleet;
use SugarCraft\Top\Collect\VmFleetSnapshot;
use SugarCraft\Top\Collect\VmGuest;

/**
 * The VM dashboard collector against a fixture tree laid out like the
 * kvm521 host: two machined scopes, one guest with libvirt's status XML
 * (root view), one without (its facts from the qemu command line, its tap
 * by MAC, its disk from io.stat).
 */
final class VmFleetTest extends TestCase
{
    private const WEB = '/machine.slice/machine-qemu\x2d12\x2dweb01.scope';

    private const DB = '/machine.slice/machine-qemu\x2d13\x2ddb\x2d1.scope';

    private string $root = '';

    private int $now = 0;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/candy-top-vmfleet-' . getmypid() . '-' . bin2hex(random_bytes(4));
        $this->now = 1_000_000;
        $cg = 'sys/fs/cgroup';
        // web01: libvirt XML, qemu pid 4242 with /proc io, tap vnet3.
        $this->put($cg . self::WEB . '/libvirt/cgroup.procs', "4242\n");
        $this->put('run/libvirt/qemu/web01.xml', VmDomainTest::STATUS_XML);
        $this->put('run/libvirt/qemu/web01.pid', '4242');
        $this->put('run/libvirt/qemu/driver.pid', '1');
        // db-1: no XML; command line facts, tap by MAC, io.stat disk.
        $this->put($cg . self::DB . '/libvirt/cgroup.procs', "5151\n");
        $this->put('proc/5151/cmdline', implode("\0", ['/usr/bin/kvm', '-name', 'guest=db-1,debug-threads=on', '-smp', '2', '-m', '2048', '-device', 'virtio-net-pci,netdev=hostnet0,id=net0,mac=52:54:00:aa:bb:cc']) . "\0");
        $this->put('sys/class/net/vnet7/address', "fe:54:00:aa:bb:cc\n");
        $this->put('sys/class/net/br0/address', "fe:54:00:aa:bb:cc\n");
        $this->put('sys/class/net/br0/bridge/forward_delay', "0\n");
        // Unrelated cgroup entries are not guests.
        $this->put($cg . '/machine.slice/machine-arch.scope/cgroup.procs', "1\n");
        $this->put($cg . '/machine.slice/cgroup.procs', "\n");
        $this->put('proc/meminfo', "MemTotal:       16384000 kB\nMemFree: 1 kB\n");
        $this->counters(0);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function testFirstSampleHasFactsButNoRates(): void
    {
        [$snap] = $this->fleet()->sample();
        $this->assertInstanceOf(VmFleetSnapshot::class, $snap);
        $this->assertTrue($snap->baseline);
        $this->assertFalse($snap->limited, 'one XML was readable');
        $this->assertSame(16384000 * 1024, $snap->hostMem);
        $this->assertSame([self::WEB, self::DB], array_map(static fn (VmGuest $g): string => $g->path, $snap->guests));

        $web = $snap->find(self::WEB);
        $this->assertNotNull($web);
        $this->assertSame('web01', $web->name);
        $this->assertSame(12, $web->domainId);
        $this->assertSame(4, $web->vcpus);
        $this->assertSame(4 * 1024 ** 3, $web->memBytes);
        $this->assertSame(Sentinel::UNMEASURED, $web->cpu);
        $this->assertSame(Sentinel::UNMEASURED, $web->diskRead);
        $this->assertSame(Sentinel::UNMEASURED, $web->netRx);
        $this->assertSame(1000 - 200, $web->memUsed, 'memory.current minus inactive_file');
        $this->assertSame(1.5, $web->psiCpu);
        $this->assertSame(0.0, $web->psiMem);
        $this->assertSame(12.25, $web->psiIo);
        $this->assertSame(['io', 12.25], $web->pressure());

        $db = $snap->find(self::DB);
        $this->assertNotNull($db);
        $this->assertSame('db-1', $db->name);
        $this->assertSame(13, $db->domainId);
        $this->assertSame(2, $db->vcpus);
        $this->assertSame(2048 * 1024 ** 2, $db->memBytes);
        $this->assertSame(6, $snap->vcpus());
    }

    public function testRatesOverTheWindow(): void
    {
        [, $fleet] = $this->fleet()->sample();
        $this->now += 2_000_000;
        $this->counters(1);
        [$snap] = $fleet->sample();
        $this->assertFalse($snap->baseline);

        $web = $snap->find(self::WEB);
        $this->assertNotNull($web);
        // 4 vCPU-seconds of usage over 2 s on 4 vCPUs = 50 % of its allocation.
        $this->assertEqualsWithDelta(50.0, $web->cpu, 1e-9);
        // /proc/4242/io read_bytes / write_bytes, not io.stat.
        $this->assertEqualsWithDelta(1_000_000.0, $web->diskRead, 1e-6);
        $this->assertEqualsWithDelta(500_000.0, $web->diskWrite, 1e-6);
        // The tap's tx is the guest's download.
        $this->assertEqualsWithDelta(300_000.0, $web->netRx, 1e-6);
        $this->assertEqualsWithDelta(100_000.0, $web->netTx, 1e-6);

        $db = $snap->find(self::DB);
        $this->assertNotNull($db);
        // 1 vCPU-second over 2 s on 2 vCPUs.
        $this->assertEqualsWithDelta(25.0, $db->cpu, 1e-9);
        // No readable /proc/5151/io: the scope's io.stat summed over both devices.
        $this->assertEqualsWithDelta(2 * 4096 / 2, $db->diskRead, 1e-6);
        $this->assertEqualsWithDelta(2 * 8192 / 2, $db->diskWrite, 1e-6);
        // vnet7 found by MAC (fe:… = the guest's 52:… with the first octet 0xfe), not the bridge.
        $this->assertEqualsWithDelta(1024.0, $db->netRx, 1e-6);
        $this->assertEqualsWithDelta(512.0, $db->netTx, 1e-6);
    }

    public function testACounterThatWentBackwardsIsUnmeasured(): void
    {
        $this->counters(1);
        [, $fleet] = $this->fleet()->sample();
        $this->now += 2_000_000;
        $this->counters(0);
        [$snap] = $fleet->sample();
        $this->assertSame(Sentinel::UNMEASURED, $snap->find(self::WEB)?->cpu);
        $this->assertSame(Sentinel::UNMEASURED, $snap->find(self::WEB)?->netRx);
    }

    public function testAGapLongerThanTheMaxWindowIsAFreshBaseline(): void
    {
        [, $fleet] = $this->fleet()->withMaxWindow(5_000_000)->sample();
        $this->now += 60_000_000;
        $this->counters(1);
        [$snap, $fleet] = $fleet->sample();
        $this->assertTrue($snap->baseline);
        $this->assertSame(Sentinel::UNMEASURED, $snap->find(self::WEB)?->cpu, 'no minute-long average');
        $this->now += 2_000_000;
        $this->counters(2);
        [$snap] = $fleet->sample();
        $this->assertFalse($snap->baseline);
        $this->assertEqualsWithDelta(50.0, (float) $snap->find(self::WEB)?->cpu, 1e-9);
    }

    public function testWithoutAnyXmlTheFleetIsLimited(): void
    {
        unlink($this->root . '/run/libvirt/qemu/web01.xml');
        $this->put('proc/4242/cmdline', implode("\0", ['/usr/bin/kvm', '-name', 'guest=web01', '-smp', '4', '-m', '4096']) . "\0");
        [$snap] = $this->fleet()->sample();
        $this->assertTrue($snap->limited);
        $web = $snap->find(self::WEB);
        $this->assertSame('web01', $web?->name);
        $this->assertSame(4, $web?->vcpus);
        $this->assertSame(4096 * 1024 ** 2, $web?->memBytes);
    }

    public function testAVanishedGuestLeavesNoState(): void
    {
        [, $fleet] = $this->fleet()->sample();
        exec('rm -rf ' . escapeshellarg($this->root . '/sys/fs/cgroup' . self::DB));
        $this->now += 2_000_000;
        [$snap] = $fleet->sample();
        $this->assertSame(1, $snap->count());
        $this->assertNull($snap->find(self::DB));
    }

    public function testNoGuestsAndDisabled(): void
    {
        [$snap] = VmFleet::new(Paths::under($this->root . '/nowhere'), fn (): int => $this->now)->sample();
        $this->assertSame(0, $snap->count());
        $this->assertFalse($snap->limited);

        $off = VmFleet::disabled();
        $this->assertFalse($off->enabled());
        [$snap, $next] = $off->sample();
        $this->assertSame(0, $snap->count());
        $this->assertSame($off, $next);
        $this->assertTrue($this->fleet()->enabled());
    }

    public function testPresence(): void
    {
        $this->assertTrue(VmFleet::present(Paths::under($this->root)));
        $this->assertFalse(VmFleet::present(Paths::under($this->root . '/nowhere')));
        exec('rm -rf ' . escapeshellarg($this->root . '/run'));
        $this->assertTrue(VmFleet::present(Paths::under($this->root)), 'a guest scope is enough');
    }

    public function testScopesDecodeTheKvm521Listing(): void
    {
        $root = $this->root . '/kvm521';
        $listing = (string) file_get_contents(__DIR__ . '/../fixtures/cgroup/kvm521/machine.slice.ls');
        $names = array_values(array_filter(array_map('trim', explode("\n", $listing)), static fn (string $l): bool => $l !== ''));
        foreach ($names as $name) {
            @mkdir($root . '/sys/fs/cgroup/machine.slice/' . $name, 0777, true);
        }
        $scopes = VmFleet::scopes(Paths::under($root));
        $this->assertCount(55, $scopes);
        foreach ($scopes as $path => [$id, $name]) {
            $this->assertMatchesRegularExpression('/^vps\d+$/', $name, $path);
            $this->assertGreaterThan(0, $id);
        }
    }

    public function testMacFallbackCoversFaTapsMacvtapAndLateTaps(): void
    {
        // db-1 gains two more NICs: guest MAC fe:… (libvirt names its tap fa:…) and a macvtap (exact MAC).
        $this->put('proc/5151/cmdline', implode("\0", ['/usr/bin/kvm', '-name', 'guest=db-1', '-smp', '2', '-m', '2048',
            '-device', 'virtio-net-pci,mac=52:54:00:aa:bb:cc', '-device', 'virtio-net-pci,mac=fe:54:00:01:02:03', '-device', 'virtio-net-pci,mac=52:54:00:0d:0e:0f']) . "\0");
        $this->put('sys/class/net/vnet8/address', "fa:54:00:01:02:03\n");
        $this->put('sys/class/net/vnet8/statistics/rx_bytes', '0');
        $this->put('sys/class/net/vnet8/statistics/tx_bytes', '0');
        [, $fleet] = $this->fleet()->sample();
        // The macvtap appears only now: unmatched NICs are retried every sample.
        $this->put('sys/class/net/macvtap0/address', "52:54:00:0d:0e:0f\n");
        $this->put('sys/class/net/macvtap0/statistics/rx_bytes', '0');
        $this->put('sys/class/net/macvtap0/statistics/tx_bytes', '9000000000');
        $this->now += 2_000_000;
        $this->counters(1);
        $this->put('sys/class/net/vnet8/statistics/tx_bytes', '4000');
        [$joined, $fleet] = $fleet->sample();
        // The new tap's lifetime total must not land in one window: the net baseline restarts.
        $this->assertSame(Sentinel::UNMEASURED, $joined->find(self::DB)?->netRx);
        $this->assertSame(Sentinel::UNMEASURED, $joined->find(self::DB)?->netTx);
        $this->assertEqualsWithDelta(25.0, (float) $joined->find(self::DB)?->cpu, 1e-9, 'only net restarts');
        $this->now += 2_000_000;
        $this->put('sys/class/net/vnet8/statistics/tx_bytes', '8000');
        $this->put('sys/class/net/macvtap0/statistics/tx_bytes', '9000002000');
        [$snap] = $fleet->sample();
        // Guest download over 2 s: vnet7 unchanged, vnet8 +4000, macvtap0 +2000.
        $this->assertEqualsWithDelta(3000.0, (float) $snap->find(self::DB)?->netRx, 1e-6);
    }

    public function testXmlIsReparsedWhenItsSizeChangesAndCappedAt1MiB(): void
    {
        $xml = $this->root . '/run/libvirt/qemu/web01.xml';
        [, $fleet] = $this->fleet()->sample();
        $mtime = (int) filemtime($xml);
        file_put_contents($xml, str_replace("<vcpu placement='static'>4</vcpu>", "<vcpu placement='static'>16</vcpu>", VmDomainTest::STATUS_XML));
        touch($xml, $mtime);
        [$snap, $fleet] = $fleet->sample();
        $this->assertSame(16, $snap->find(self::WEB)?->vcpus, 'same mtime, new size: re-read');
        $this->put('proc/4242/cmdline', implode("\0", ['/usr/bin/kvm', '-name', 'guest=web01', '-smp', '4', '-m', '4096']) . "\0");
        file_put_contents($xml, str_repeat(' ', VmFleet::XML_MAX_BYTES + 1));
        [$snap] = $fleet->sample();
        $this->assertTrue($snap->limited, 'an oversized file is not read');
        $this->assertSame(4, $snap->find(self::WEB)?->vcpus, 'the command line answers instead');
    }

    public function testParsers(): void
    {
        $this->assertSame(4.5, VmFleet::pressure("some avg10=4.50 avg60=1.00 avg300=0.00 total=1\nfull avg10=9.00 avg60=0 avg300=0 total=0\n"));
        $this->assertSame(Sentinel::UNMEASURED, VmFleet::pressure(null));
        $this->assertSame(Sentinel::UNMEASURED, VmFleet::pressure('garbage'));
        $this->assertSame([null, null], VmFleet::netBytes(Paths::under($this->root), null));
        $this->assertSame([0, 0], VmFleet::netBytes(Paths::under($this->root), []), 'no NIC reads as zero traffic');
        $this->assertSame([null, null], VmFleet::diskBytes(Paths::under($this->root), $this->root . '/missing', 0));
    }

    private function fleet(): VmFleet
    {
        return VmFleet::new(Paths::under($this->root), fn (): int => $this->now);
    }

    /** Counters at step `$n`: every one grows linearly. */
    private function counters(int $n): void
    {
        $cg = 'sys/fs/cgroup';
        $this->put($cg . self::WEB . '/cpu.stat', 'usage_usec ' . (10_000_000 + $n * 4_000_000) . "\nuser_usec 1\n");
        $this->put($cg . self::WEB . '/memory.current', "1000\n");
        $this->put($cg . self::WEB . '/memory.stat', "anon 700\nfile 300\ninactive_file 200\n");
        $this->put($cg . self::WEB . '/io.stat', '259:0 rbytes=' . (99 + $n) . " wbytes=99 rios=1 wios=1 dbytes=0 dios=0\n");
        $this->put($cg . self::WEB . '/cpu.pressure', "some avg10=1.50 avg60=0.00 avg300=0.00 total=1\nfull avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");
        $this->put($cg . self::WEB . '/memory.pressure', "some avg10=0.00 avg60=0.00 avg300=0.00 total=0\n");
        $this->put($cg . self::WEB . '/io.pressure', "some avg10=12.25 avg60=3.00 avg300=1.00 total=9\n");
        $this->put('proc/4242/io', "rchar: 1\nwchar: 1\nsyscr: 1\nsyscw: 1\nread_bytes: " . (5_000_000 + $n * 2_000_000) . "\nwrite_bytes: " . ($n * 1_000_000) . "\ncancelled_write_bytes: 0\n");
        $this->put('sys/class/net/vnet3/statistics/rx_bytes', (string) (1000 + $n * 200_000));
        $this->put('sys/class/net/vnet3/statistics/tx_bytes', (string) (2000 + $n * 600_000));

        $this->put($cg . self::DB . '/cpu.stat', 'usage_usec ' . (5_000_000 + $n * 1_000_000) . "\n");
        $this->put($cg . self::DB . '/memory.current', "500\n");
        $this->put($cg . self::DB . '/memory.stat', "inactive_file 0\n");
        $this->put($cg . self::DB . '/io.stat', '9:0 rbytes=' . ($n * 4096) . ' wbytes=' . ($n * 8192) . " rios=0 wios=0\n259:0 rbytes=" . ($n * 4096) . ' wbytes=' . ($n * 8192) . "\n");
        $this->put('sys/class/net/vnet7/statistics/rx_bytes', (string) ($n * 1024));
        $this->put('sys/class/net/vnet7/statistics/tx_bytes', (string) ($n * 2048));
    }

    private function put(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }
}
