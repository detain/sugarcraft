<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Memory;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class MemoryTest extends TestCase
{
    private FixtureTree $tree;

    protected function setUp(): void
    {
        $this->tree = FixtureTree::copy();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    public function testBtopBucketsFromMeminfo(): void
    {
        $memory = Memory::new($this->tree->paths());
        [$snap, $next] = $memory->sample();
        $k = 1024;

        $this->assertSame(16000000 * $k, $snap->total);
        $this->assertSame(9000000 * $k, $snap->available);
        $this->assertSame(6000000 * $k, $snap->cached, 'Buffers are not folded into cached');
        $this->assertSame(2000000 * $k, $snap->free);
        $this->assertSame(7000000 * $k, $snap->used, 'used = total - available');
        $this->assertSame(1000000 * $k, $snap->swapUsed);
        $this->assertSame(3000000 * $k, $snap->swapFree);
        $this->assertSame(44.0, $snap->percent('used'));
        $this->assertSame(25.0, $snap->swapPercent('swap_used'));
        $this->assertSame($memory, $next);
    }

    public function testPre314KernelFallsBackToFreePlusCached(): void
    {
        $this->tree->write('proc/meminfo', "MemTotal: 1000 kB\nMemFree: 100 kB\nCached: 200 kB\n");
        [$snap] = Memory::new($this->tree->paths())->sample();

        $this->assertSame(300 * 1024, $snap->available);
        $this->assertSame(700 * 1024, $snap->used);
        $this->assertSame(0, $snap->swapTotal);
        $this->assertSame(Sentinel::UNMEASURED, $snap->swapPercent('swap_used'));
    }

    public function testZfsArcAddsToCachedAndAvailableAboveCMin(): void
    {
        $this->tree->write('proc/meminfo', "MemTotal: 1000 kB\nMemFree: 100 kB\nMemAvailable: 400 kB\nCached: 200 kB\n");
        $this->tree->write('proc/spl/kstat/zfs/arcstats', "13 1 0x01 1 2 3\nname type data\nc_min 4 102400\nsize 4 307200\n");
        [$snap] = Memory::new($this->tree->paths())->sample();

        $this->assertSame(500 * 1024, $snap->cached);
        $this->assertSame(600 * 1024, $snap->available);
        $this->assertSame(400 * 1024, $snap->used);

        [$off] = Memory::new($this->tree->paths(), zfsArcCached: false)->sample();
        $this->assertSame(200 * 1024, $off->cached);
    }

    public function testAvailableOvershootingTotalFallsBackToFree(): void
    {
        $this->tree->write('proc/meminfo', "MemTotal: 1000 kB\nMemFree: 100 kB\nMemAvailable: 900 kB\nCached: 200 kB\n");
        $this->tree->write('proc/spl/kstat/zfs/arcstats', "c_min 4 0\nsize 4 512000\n");
        [$snap] = Memory::new($this->tree->paths())->sample();

        $this->assertSame(900 * 1024, $snap->used, 'used = total - free when available > total');
    }

    public function testMissingMeminfoIsUnmeasured(): void
    {
        $this->tree->remove('proc/meminfo');
        [$snap] = Memory::new($this->tree->paths())->sample();

        $this->assertFalse($snap->measured());
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->used);
        $this->assertSame(Sentinel::UNMEASURED, $snap->percent('used'));
    }

    public function testZswapFieldsAndOnDiskUsed(): void
    {
        [$snap] = Memory::new($this->tree->paths())->sample();
        $k = 1024;

        $this->assertTrue($snap->hasZswap());
        $this->assertSame(200000 * $k, $snap->zswap);
        $this->assertSame(600000 * $k, $snap->zswapped);
        $this->assertSame(1000000 * $k, $snap->swapUsed, 'swapUsed stays SwapTotal − SwapFree');
        $this->assertSame(400000 * $k, $snap->swapUsedOnDisk(), 'btop show_zswap Used = swap_used − Zswapped');
        $this->assertSame(10.0, $snap->swapPercent('swap_used_disk'));
        $this->assertSame(5.0, $snap->swapPercent('zswap'));
    }

    public function testPre519KernelHasNoZswap(): void
    {
        $this->tree->write('proc/meminfo', "MemTotal: 1000 kB\nMemFree: 100 kB\nMemAvailable: 400 kB\nCached: 200 kB\nSwapTotal: 100 kB\nSwapFree: 40 kB\n");
        [$snap] = Memory::new($this->tree->paths())->sample();

        $this->assertFalse($snap->hasZswap());
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->zswap);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->zswapped);
        $this->assertSame(60 * 1024, $snap->swapUsedOnDisk(), 'falls back to plain swap_used');
        $this->assertSame(Sentinel::UNMEASURED, $snap->swapPercent('zswap'));
    }

    public function testZswappedRacingAheadOfSwapUsedClampsToZero(): void
    {
        $this->tree->write('proc/meminfo', "MemTotal: 1000 kB\nMemFree: 100 kB\nSwapTotal: 100 kB\nSwapFree: 90 kB\nZswap: 5 kB\nZswapped: 30 kB\n");
        [$snap] = Memory::new($this->tree->paths())->sample();

        $this->assertSame(0, $snap->swapUsedOnDisk());
        $this->assertSame(0.0, $snap->swapPercent('swap_used_disk'));
    }

    public function testUnreadableMeminfoLeavesZswapUnmeasured(): void
    {
        $this->tree->remove('proc/meminfo');
        [$snap] = Memory::new($this->tree->paths())->sample();

        $this->assertFalse($snap->hasZswap());
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->swapUsedOnDisk());
    }
}
