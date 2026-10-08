<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\FreeBsd\Memory;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

final class MemoryTest extends TestCase
{
    public function testReferenceUfsHostWithoutArc(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), ['swapinfo' => FixtureProbe::fixture('swapinfo-k.txt')]);
        [$snap, $next] = Memory::new($probe)->sample();
        $p = 4096;

        $this->assertSame(1005423 * $p, $snap->total, '#1851: v_page_count × pagesize, not hw.physmem');
        $this->assertSame((25110 + 208051 + 0) * $p, $snap->used, 'active + laundry + wired (no cache to take out)');
        $this->assertSame($snap->total - $snap->used, $snap->available);
        $this->assertSame(518559 * $p, $snap->free);
        $this->assertSame(0, $snap->cached, 'no bufspace OID, no ARC: a measured zero, not a gap');
        $this->assertSame(4194300 * 1024, $snap->swapTotal);
        $this->assertSame(0, $snap->swapUsed);
        $this->assertSame(4194300 * 1024, $snap->swapFree);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->zswap);
        $this->assertSame(23.0, $snap->percent('used'));
        $this->assertTrue($snap->measured());
        $this->assertInstanceOf(Memory::class, $next);
        $this->assertSame([['swapinfo', '-k']], $probe->runs);
    }

    public function testStaticOidsAreReadOnce(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'), ['swapinfo' => FixtureProbe::fixture('swapinfo-k.txt')]);
        [$first, $memory] = Memory::new($probe)->sample();
        [$second] = $memory->sample();

        $this->assertSame([...Memory::STATIC_OIDS, ...Memory::DYNAMIC_OIDS], $probe->sysctls[0]);
        $this->assertSame(Memory::DYNAMIC_OIDS, $probe->sysctls[1], 'pagesize / physmem / v_page_count are carried forward');
        $this->assertEquals($first, $second);
    }

    public function testZfsHostFoldsBufspaceAndReclaimableArcIntoCachedAndOutOfWired(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-zfs-host.synthetic.txt'), ['swapinfo' => FixtureProbe::fixture('swapinfo-k-two-devices.synthetic.txt')]);
        [$snap] = Memory::new($probe)->sample();
        $p = 4096;
        $cached = 104857600 + (6442450944 - 536870912);

        $this->assertSame($cached, $snap->cached, '#1728: bufspace + (arc size − c_min)');
        $this->assertSame(524288 * $p + 131072 * $p + (2097152 * $p - $cached), $snap->used, '#1851 laundry is used; #1728 cache leaves wired');
        $this->assertSame(4097280 * $p - $snap->used, $snap->available);
        $this->assertSame(4194304 * 1024, $snap->swapTotal, 'the Total row is not double-counted');
        $this->assertSame(524288 * 1024, $snap->swapUsed);
    }

    public function testArcLeftInUsedWhenZfsArcCachedIsOff(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-zfs-host.synthetic.txt'), ['swapinfo' => FixtureProbe::fixture('swapinfo-k-none.txt')]);
        [$snap] = Memory::new($probe, false)->sample();

        $this->assertSame(104857600, $snap->cached);
        $this->assertSame(0, $snap->swapTotal, 'a swapless host is 0, a measurement');
        $this->assertSame(0, $snap->swapFree);
    }

    public function testFailedSwapinfoIsUnmeasuredSwapOnly(): void
    {
        [$snap] = Memory::new(new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt')))->sample();

        $this->assertTrue($snap->measured());
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->swapTotal);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->swapUsed);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->swapFree);
        $this->assertSame(Sentinel::UNMEASURED, $snap->swapPercent('swap_used'));
    }

    public function testUnreadableCountersAreUnmeasured(): void
    {
        [$snap] = Memory::new(new FixtureProbe('hw.pagesize=4096'))->sample();

        $this->assertFalse($snap->measured());
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->used);
    }

    public function testPhysmemFallbackWhenPageCountIsAbsent(): void
    {
        $text = "hw.pagesize=4096\nhw.physmem=8589934592\nvm.stats.vm.v_free_count=1\nvm.stats.vm.v_active_count=2\nvm.stats.vm.v_wire_count=3\n";
        [$snap] = Memory::new(new FixtureProbe($text, ['swapinfo' => '']))->sample();

        $this->assertSame(8589934592, $snap->total);
        $this->assertSame(5 * 4096, $snap->used);
    }

    public function testSwapParserSkipsHeaderAndTotal(): void
    {
        $this->assertSame([4194304 * 1024, 524288 * 1024], Memory::swap(FixtureProbe::fixture('swapinfo-k-two-devices.synthetic.txt')));
        $this->assertSame([0, 0], Memory::swap(FixtureProbe::fixture('swapinfo-k-none.txt')));
        $this->assertSame([Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED_INT], Memory::swap(null));
    }
}
