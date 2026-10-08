<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu\DrmFdinfo;
use SugarCraft\Top\Collect\Gpu\DrmScan;
use SugarCraft\Top\Collect\Gpu\IntelSysfs;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Collect\Sentinel;

final class IntelSysfsTest extends TestCase
{
    private GpuTree $tree;

    private float $now = 50.0;

    protected function setUp(): void
    {
        $this->tree = GpuTree::of('intel');
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    private function intel(): IntelSysfs
    {
        $intel = IntelSysfs::detect($this->tree->paths(), fn (): float => $this->now);
        $this->assertNotNull($intel);

        return $intel;
    }

    public function testEveryIntelCardIsEnumerated(): void
    {
        $intel = $this->intel();

        $this->assertSame(['card5', 'card6'], array_map(static fn ($c): string => $c->name, $intel->cards()), '#1888: iGPU + discrete side by side');
        $this->assertTrue($intel->needsDrmScan());
        $this->assertTrue($intel->drmClients());
        $this->assertSame(GpuVendor::Intel, $intel->vendor());
    }

    public function testI915IgpuClocksAndNames(): void
    {
        [$snap] = $this->intel()->poll(DrmScan::none());
        $d = $snap->devices[0];

        $this->assertSame('Raptor Lake-P [Iris Xe Graphics]', $d->name, 'pci.ids; vendor 8087 block not consulted');
        $this->assertSame('i915', $d->driver);
        $this->assertSame('0000:00:02.0', $d->busId);
        $this->assertSame(1300.0, $d->clockGraphics, 'gt_act_freq_mhz before gt_cur_freq_mhz');
        $this->assertSame(1500.0, $d->clockGraphicsMax, 'gt_RP0_freq_mhz');
        $this->assertSame(Sentinel::UNMEASURED, $d->utilization, 'no scan → unmeasured');
        $this->assertSame(Sentinel::UNMEASURED, $d->watts, 'iGPU: no hwmon');
        $this->assertSame(Sentinel::UNMEASURED, $d->powerLimit);
        $this->assertSame(Sentinel::UNMEASURED_INT, $d->memTotal, 'VRAM deferred');
        $this->assertSame(Sentinel::UNMEASURED_INT, $d->pcieGen);
    }

    public function testXeDiscreteEnergyBecomesWattsAcrossSamples(): void
    {
        $intel = $this->intel();
        [$snap, $intel] = $intel->poll(DrmScan::none());
        $d = $snap->devices[1];
        $this->assertSame('DG2 [Arc A770]', $d->name);
        $this->assertSame('xe', $d->driver);
        $this->assertSame(2000.0, $d->clockGraphics, 'tile0/gt0/freq0/act_freq');
        $this->assertSame(2400.0, $d->clockGraphicsMax, 'rp0_freq');
        $this->assertSame(55.0, $d->temp, 'pkg label');
        $this->assertSame(190.0, $d->powerLimit, 'power1_max');
        $this->assertSame(Sentinel::UNMEASURED, $d->watts, 'energy needs two reads');
        $this->assertSame(1, $d->pcieGen);
        $this->assertSame(1, $d->pcieWidth);

        $this->now += 2.0;
        $this->tree->write('sys/class/drm/card6/device/hwmon/hwmon7/energy1_input', "1090000000\n");
        [$snap, $intel] = $intel->poll(DrmScan::none());
        $this->assertEqualsWithDelta(45.0, $snap->devices[1]->watts, 1e-9, '90 J over 2 s');

        $this->now += 1.0;
        $this->tree->write('sys/class/drm/card6/device/hwmon/hwmon7/energy1_input', "5\n");
        [$snap] = $intel->poll(DrmScan::none());
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[1]->watts, 'counter wrap is not a negative watt');
    }

    public function testUtilizationComesFromTheScanPerCard(): void
    {
        $this->tree->fd(1001, 5, 'i915-t0.txt');
        $this->tree->fd(2002, 9, 'xe-t0.txt');
        $scanner = DrmFdinfo::new($this->tree->paths(), fn (): float => $this->now);
        $intel = $this->intel();
        [$scan, $scanner] = $scanner->sample();
        [, $intel] = $intel->poll($scan);
        $this->now += 2.0;
        $this->tree->fd(1001, 5, 'i915-t1.txt');
        $this->tree->fd(2002, 9, 'xe-t1.txt');
        [$scan] = $scanner->sample();
        [$snap] = $intel->poll($scan);

        $this->assertEqualsWithDelta(50.0, $snap->devices[0]->utilization, 1e-9, 'i915 render');
        $this->assertEqualsWithDelta(40.0, $snap->devices[1]->utilization, 1e-9, 'xe vcs');
    }

    public function testNoIntelHardwareInstallsNothing(): void
    {
        $amd = GpuTree::of('amd');
        try {
            $this->assertNull(IntelSysfs::detect($amd->paths()));
        } finally {
            $amd->destroy();
        }
    }

    /** busy(85) → the client exits under a partial scan → 0 % (a lower bound), never a frozen 85. */
    public function testExitedClientUnderAPartialScanReadsZeroLowerBound(): void
    {
        $this->tree->write('proc/500/status', "Name:\tXorg\n"); // another uid: fd dir unreadable
        $t0 = GpuTree::sample('i915-t0.txt');
        $this->tree->fd(1001, 5, $t0);
        $scanner = DrmFdinfo::new($this->tree->paths(), fn (): float => $this->now);
        $intel = $this->intel();
        [$scan, $scanner] = $scanner->sample();
        [, $intel] = $intel->poll($scan);

        $this->now += 2.0;
        $this->tree->fd(1001, 5, str_replace('9000000000 ns', '10700000000 ns', $t0));
        [$scan, $scanner] = $scanner->sample();
        [$busy, $intel] = $intel->poll($scan);
        $this->assertEqualsWithDelta(85.0, $busy->devices[0]->utilization, 1e-9);
        $this->assertTrue($busy->devices[0]->utilizationLowerBound, 'partial scan: a floor');

        $this->now += 2.0;
        unlink($this->tree->root . '/proc/1001/fdinfo/5');
        [$scan] = $scanner->sample();
        [$idle] = $intel->poll($scan);
        $d = $idle->devices[0]->heldFrom($busy->devices[0]);

        $this->assertSame(0.0, $d->utilization, 'CpuPanel::holdDevice() result: not frozen at 85');
        $this->assertTrue($d->utilizationLowerBound);
    }
}
