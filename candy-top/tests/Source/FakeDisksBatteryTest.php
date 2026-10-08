<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Source;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\BatterySnapshot;
use SugarCraft\Top\Collect\DiskIoSnapshot;
use SugarCraft\Top\Collect\Mount;
use SugarCraft\Top\Collect\MountSelection;
use SugarCraft\Top\Collect\MountsSnapshot;
use SugarCraft\Top\Source\Fake\FakeBattery;
use SugarCraft\Top\Source\Fake\FakeDiskIo;
use SugarCraft\Top\Source\Fake\FakeMounts;

final class FakeDisksBatteryTest extends TestCase
{
    /** @return list<string> */
    private static function points(FakeMounts $fake): array
    {
        [$snap] = $fake->sample();
        self::assertInstanceOf(MountsSnapshot::class, $snap);

        return array_map(static fn (Mount $m): string => $m->mountpoint, $snap->mounts);
    }

    public function testFakeMountsAreDeterministicAndRootFirst(): void
    {
        [$a, $next] = FakeMounts::new()->sample();
        [$b] = FakeMounts::new()->sample();
        $this->assertEquals($a, $b);
        $this->assertSame('/', $a->mounts[0]->mountpoint);
        $this->assertSame('root', $a->mounts[0]->name);
        foreach ($a->mounts as $m) {
            $this->assertSame($m->total, $m->used + $m->free);
        }
        [$c] = $next->sample();
        $this->assertNotEquals($a->mounts[0]->used, $c->mounts[0]->used, 'used drifts per sample');
    }

    public function testFakeMountsObeyTheSelection(): void
    {
        $this->assertSame(['/', '/boot/efi', '/home', '/mnt/backup'], self::points(FakeMounts::new()));
        $all = FakeMounts::new(MountSelection::new(false));
        $this->assertSame(['/', '/boot/efi', '/tmp', '/home', '/snap/core22/1380', '/mnt/backup'], self::points($all));
        $this->assertSame(['/home'], self::points(FakeMounts::new()->withSelection(MountSelection::new(true, false, '/home'))));
        $fstab = FakeMounts::new()->withSelection(MountSelection::new(false, true));
        $this->assertSame(FakeMounts::FSTAB, self::points($fstab));
        $this->assertTrue($fstab->selection()->useFstab);
    }

    public function testFakeDiskIoSumsPartitionsIntoDisksAndAccumulates(): void
    {
        [$a, $next] = FakeDiskIo::new(2.0)->sample();
        [$b] = FakeDiskIo::new(2.0)->sample();
        $this->assertEquals($a, $b);
        $this->assertInstanceOf(DiskIoSnapshot::class, $a);
        $d = $a->devices;
        $this->assertSame($d['nvme0n1p1']->readRate + $d['nvme0n1p2']->readRate, $d['nvme0n1']->readRate);
        $this->assertSame(0.0, $d['nvme0n1p1']->readRate, 'the EFI partition is idle');
        $this->assertGreaterThan(0.0, $d['nvme0n1p2']->readRate);
        $this->assertSame((int) ($d['sda1']->writeRate * 2.0), $d['sda1']->writeTotal);
        [$c] = $next->sample();
        $this->assertGreaterThanOrEqual($d['sda1']->readTotal, $c->devices['sda1']->readTotal);
    }

    public function testFakeBatteryDischargesDeterministically(): void
    {
        $fake = FakeBattery::new();
        $this->assertSame($fake, $fake->withSelected('BAT1'));
        [$first, $next] = $fake->sample();
        $this->assertInstanceOf(BatterySnapshot::class, $first);
        $this->assertTrue($first->present());
        $this->assertSame(['BAT0', 87, 'discharging', 87 * 120], [$first->name, $first->percent, $first->status, $first->seconds]);
        $this->assertGreaterThan(0.0, $first->watts);
        for ($i = 0; $i < 3; $i++) {
            [$snap, $next] = $next->sample();
        }
        $this->assertSame(86, $snap->percent);
        $source = FakeBattery::new();
        for ($i = 0; $i < 400; $i++) {
            [$snap, $source] = $source->sample();
        }
        $this->assertSame(5, $snap->percent, 'floors at 5 %');
    }
}
