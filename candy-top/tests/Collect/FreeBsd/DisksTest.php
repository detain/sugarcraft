<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\FreeBsd\DiskIo;
use SugarCraft\Top\Collect\FreeBsd\Mounts;
use SugarCraft\Top\Collect\MountSelection;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

final class DisksTest extends TestCase
{
    public function testIostatTotalsBecomeRatesAndBusy(): void
    {
        $probe = new FixtureProbe('', ['iostat' => [
            FixtureProbe::fixture('iostat-xI.synthetic.txt'),
            FixtureProbe::fixture('iostat-xI-next.synthetic.txt'),
        ]], [10.0, 12.0]);
        [$first, $io] = DiskIo::new($probe)->sample();

        $this->assertSame(['ada0', 'ada1'], array_keys($first->devices), 'pass* (SCSI passthrough) is not storage');
        $this->assertSame(1000000 * 1024, $first->devices['ada0']->readTotal);
        $this->assertSame(Sentinel::UNMEASURED, $first->devices['ada0']->readRate);
        $this->assertSame([['iostat', '-x', '-I', '-c', '1']], $probe->runs);

        $probe->advance();
        [$snap] = $io->sample();
        $this->assertEqualsWithDelta(200 * 1024 / 2.0, $snap->devices['ada0']->readRate, 1e-6);
        $this->assertEqualsWithDelta(400 * 1024 / 2.0, $snap->devices['ada0']->writeRate, 1e-6);
        $this->assertEqualsWithDelta(50.0, $snap->devices['ada0']->busy, 1e-6, '1 busy second of 2');
        $this->assertEqualsWithDelta(25.0, $snap->devices['ada1']->busy, 1e-6);
        $this->assertEqualsWithDelta(0.0, $snap->devices['ada1']->readRate, 1e-6);
    }

    public function testPhysicalFilterOffKeepsPassDevices(): void
    {
        $probe = new FixtureProbe('', ['iostat' => FixtureProbe::fixture('iostat-xI.synthetic.txt')]);
        [$snap] = DiskIo::new($probe, false)->sample();

        $this->assertSame(['ada0', 'ada1', 'pass0', 'pass1'], array_keys($snap->devices));
    }

    public function testRateHeaderWithoutTotalsYieldsNoDevices(): void
    {
        $reference = "device       r/s     w/s     kr/s     kw/s  ms/r  ms/w  ms/o  ms/t qlen  %b\nada0           0       1      0.2     20.7     4     3     0     3    0   0\n";
        $this->assertSame([], DiskIo::parse($reference), 'kr/s rates are not totals; never mistake one for the other');
    }

    public function testFailedIostatIsEmpty(): void
    {
        [$snap] = DiskIo::new(new FixtureProbe())->sample();

        $this->assertSame([], $snap->devices);
        $this->assertSame(Sentinel::UNMEASURED, $snap->readRate());
    }

    public function testReferenceMountTableKeepsOnlyStorage(): void
    {
        $probe = new FixtureProbe('', ['mount' => FixtureProbe::fixture('mount-p.txt')], spaces: [
            '/' => [942033884 * 1024, 488713596 * 1024],
            '/dev' => [1024, 1024],
        ]);
        [$snap] = Mounts::new($probe)->sample();

        $this->assertCount(1, $snap->mounts, 'devfs/procfs/fdescfs are in-memory (btop skip list)');
        $root = $snap->mounts[0];
        $this->assertSame('/dev/mirror/root', $root->device);
        $this->assertSame('root', $root->name);
        $this->assertSame('ufs', $root->fstype);
        $this->assertSame((942033884 - 488713596) * 1024, $root->used);
        $this->assertSame([['mount', '-p']], $probe->runs);
    }

    public function testZfsLayoutEscapesRootFirstAndSelection(): void
    {
        $spaces = ['/' => [100, 40], '/mnt/My Data' => [200, 50], '/usr/home' => [300, 60], '/tmp' => [10, 5], '/jails/www/home' => [300, 60]];
        $probe = new FixtureProbe('', ['mount' => FixtureProbe::fixture('mount-p-zfs.synthetic.txt')], spaces: $spaces);
        [$snap, $mounts] = Mounts::new($probe)->sample();

        $this->assertSame(['/', '/mnt/My Data', '/usr/home'], array_map(static fn ($m) => $m->mountpoint, $snap->mounts), 'tmpfs and nullfs are not physical; \\040 decoded');
        $this->assertSame('My Data', $snap->mounts[1]->name);

        [$hidden] = $mounts->withSelection(MountSelection::new(true, false, '', true))->sample();
        $this->assertSame(['/mnt/My Data'], array_map(static fn ($m) => $m->mountpoint, $hidden->mounts), 'zfs_hide_datasets drops pool/dataset mounts');

        [$all] = $mounts->withSelection(MountSelection::new(false))->sample();
        $this->assertCount(5, $all->mounts, 'only_physical off keeps every statvfs-able mount (devfs has no statvfs answer here)');
        $this->assertSame(false, $mounts->withSelection(MountSelection::new(false))->selection()->physicalOnly);
    }

    public function testFstabSelectionAndFailedStatvfsIsIgnoredThenRetried(): void
    {
        $probe = new FixtureProbe('', ['mount' => FixtureProbe::fixture('mount-p-zfs.synthetic.txt')], [0.0, 10.0, 100.0], spaces: ['/' => [100, 40]], files: [
            '/etc/fstab' => "# Device Mountpoint FStype Options Dump Pass\n/dev/ada1p1 /mnt/My\\040Data ufs rw 2 2\n",
        ]);
        [$snap] = Mounts::new($probe)->withSelection(MountSelection::new(true, true))->sample();
        $this->assertSame([], $snap->mounts, 'the fstab mount has no statvfs answer: ignored, not fatal');

        $probe = new FixtureProbe('', ['mount' => "/dev/ada1p1 /data ufs rw 2 2\n"], [0.0, 10.0, 100.0]);
        [, $m] = Mounts::new($probe)->sample();
        $probe->advance();
        [, $m] = $m->sample();
        $this->assertSame(['/data'], $probe->spaceCalls, 'within RETRY_AFTER the dead mount is not probed again');
        $probe->advance();
        $m->sample();
        $this->assertSame(['/data', '/data'], $probe->spaceCalls, 'after RETRY_AFTER it is retried');
    }

    public function testFailedMountIsEmpty(): void
    {
        [$snap] = Mounts::new(new FixtureProbe())->sample();

        $this->assertSame([], $snap->mounts);
    }
}
