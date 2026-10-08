<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\DiskIo;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class DiskIoTest extends TestCase
{
    private FixtureTree $tree;
    private float $now = 10.0;

    protected function setUp(): void
    {
        $this->tree = FixtureTree::copy();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    public function testPhysicalFilterKeepsWholeNonVirtualDisks(): void
    {
        [$snap] = DiskIo::new($this->tree->paths(), fn (): float => $this->now)->sample();

        $this->assertSame(['sda', 'nvme0n1'], array_keys($snap->devices));
        $this->assertSame(2000 * 512, $snap->devices['sda']->readTotal);
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices['sda']->readRate);
        $this->assertSame(Sentinel::UNMEASURED, $snap->readRate());
    }

    public function testUnfilteredListsEveryRow(): void
    {
        [$snap] = DiskIo::new($this->tree->paths(), fn (): float => $this->now, physicalOnly: false)->sample();

        $this->assertSame(['sda', 'sda1', 'nvme0n1', 'loop0', 'dm-0'], array_keys($snap->devices));
    }

    public function testDeltaBytesPerSecondAndBusyPercent(): void
    {
        [, $io] = DiskIo::new($this->tree->paths(), fn (): float => $this->now)->sample();
        $this->now += 2.0;
        // sda: +400 sectors read, +800 written, io_ticks +500ms over 2s.
        $this->tree->write('proc/diskstats', "   8 0 sda 100 0 2400 50 200 0 4800 80 0 1500 130\n 259 0 nvme0n1 10 0 200 5 20 0 400 8 0 100 13\n");
        [$snap] = $io->sample();

        $sda = $snap->devices['sda'];
        $this->assertSame(400 * 512 / 2.0, $sda->readRate);
        $this->assertSame(800 * 512 / 2.0, $sda->writeRate);
        $this->assertSame(25.0, $sda->busy);
        $this->assertSame(0.0, $snap->devices['nvme0n1']->readRate);
        $this->assertSame(102400.0, $snap->readRate());
        $this->assertSame(204800.0, $snap->writeRate());
    }

    public function testCounterResetNeverGoesNegative(): void
    {
        [, $io] = DiskIo::new($this->tree->paths(), fn (): float => $this->now)->sample();
        $this->now += 1.0;
        $this->tree->write('proc/diskstats', "8 0 sda 0 0 0 0 0 0 0 0 0 0 0\n");
        [$snap] = $io->sample();

        $this->assertSame(0.0, $snap->devices['sda']->readRate);
        $this->assertSame(0.0, $snap->devices['sda']->busy);
    }

    public function testMissingDiskstatsIsEmpty(): void
    {
        $this->tree->remove('proc/diskstats');
        [$snap] = DiskIo::new($this->tree->paths())->sample();

        $this->assertSame([], $snap->devices);
        $this->assertSame(Sentinel::UNMEASURED, $snap->writeRate());
    }
}
