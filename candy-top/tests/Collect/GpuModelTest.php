<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Collect\GpuProcess;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Collect\Sentinel;

final class GpuModelTest extends TestCase
{
    private static function amd(int $index = 2, float $util = 40.0, int $used = 100): GpuDevice
    {
        return new GpuDevice($index, 'RX', $util, $used, 1000, 50.0, 120.0, powerLimit: 200.0, clockGraphicsMax: 2500.0, uuid: 'u', vendor: GpuVendor::Amd, busId: '0000:03:00.0', driver: 'amdgpu');
    }

    public function testDefaultsKeepPrePiConstructorsMeaningAnNvidiaGpu(): void
    {
        $d = new GpuDevice(0, 'RTX', 1.0, 1, 2, 3.0, 4.0);

        $this->assertSame(GpuVendor::Nvidia, $d->vendor);
        $this->assertSame(AcceleratorKind::Gpu, $d->kind);
        $this->assertSame(Sentinel::UNAVAILABLE, $d->busId);
        $this->assertSame(Sentinel::UNAVAILABLE, $d->driver);
    }

    public function testWithIndexChangesOnlyTheIndex(): void
    {
        $d = self::amd();
        $moved = $d->withIndex(7);

        $this->assertSame(7, $moved->index);
        $this->assertSame(2, $d->index, 'immutable');
        $this->assertEquals(get_object_vars($d), ['index' => 2] + get_object_vars($moved));
    }

    public function testUnmeasuredKeepsIdentityOnly(): void
    {
        $u = self::amd()->unmeasured();

        $this->assertSame([2, 'RX', 'u', GpuVendor::Amd, '0000:03:00.0', 'amdgpu'], [$u->index, $u->name, $u->uuid, $u->vendor, $u->busId, $u->driver]);
        $this->assertSame([1000, 200.0, 2500.0], [$u->memTotal, $u->powerLimit, $u->clockGraphicsMax], 'static capacities kept');
        $this->assertSame(Sentinel::UNMEASURED, $u->utilization);
        $this->assertSame(Sentinel::UNMEASURED_INT, $u->memUsed);
        $this->assertSame(Sentinel::UNMEASURED, $u->watts);
        $this->assertSame(Sentinel::UNMEASURED, $u->temp);
    }

    public function testHeldFromFillsUnmeasuredColumnsFromTheSameDevice(): void
    {
        $prev = self::amd();
        $now = self::amd(2, -1.0, -1);
        $held = $now->heldFrom($prev);

        $this->assertSame(40.0, $held->utilization);
        $this->assertSame(100, $held->memUsed);
        $this->assertSame(GpuVendor::Amd, $held->vendor, 'identity survives the merge');
        $this->assertSame('0000:03:00.0', $held->busId);

        $this->assertSame($now, $now->heldFrom(null));
        $this->assertSame($now, $now->heldFrom(self::amd(3)), 'other index');
        $other = new GpuDevice(2, 'RX', 9.0, 9, 9, 9.0, 9.0, vendor: GpuVendor::Amd, busId: '0000:04:00.0');
        $this->assertSame($now, $now->heldFrom($other), 'other bus id');
    }

    public function testSnapshotKeepsNpusOutOfTheGpuList(): void
    {
        $npu = new GpuDevice(0, 'NPU', 5.0, 1, -1, -1.0, -1.0, kind: AcceleratorKind::Npu, vendor: GpuVendor::Intel);
        $onlyNpu = new GpuSnapshot([], null, [$npu]);

        $this->assertFalse($onlyNpu->available());
        $this->assertTrue($onlyNpu->hasAccelerators());
        $this->assertSame([$npu], $onlyNpu->accelerators());
        $gpu = self::amd(0);
        $this->assertSame([$gpu, $npu], (new GpuSnapshot([$gpu], null, [$npu]))->accelerators());
        $this->assertFalse((new GpuSnapshot([]))->hasAccelerators());
    }

    public function testUtilizationByPidSumsAcrossGpus(): void
    {
        $s = new GpuSnapshot([], [
            new GpuProcess(10, 0, 'a', 5, 60.0),
            new GpuProcess(10, 1, 'b', 5, 70.0),
            new GpuProcess(11, 0, 'a', 5),
            new GpuProcess(12, 0, 'a', 5, Sentinel::UNMEASURED),
            new GpuProcess(12, 1, 'b', 5, 3.0),
        ]);

        $this->assertSame([10 => 130.0, 11 => Sentinel::UNMEASURED, 12 => 3.0], $s->utilizationByPid());
        $this->assertSame([], (new GpuSnapshot([]))->utilizationByPid());
    }

    public function testProcessWithGpuIndex(): void
    {
        $p = new GpuProcess(1, 0, 'x', 2, 3.0, 4.0, 5.0, 6.0);
        $q = $p->withGpuIndex(9);

        $this->assertSame([1, 9, 'x', 2, 3.0, 4.0, 5.0, 6.0], array_values(get_object_vars($q)));
        $this->assertSame(0, $p->gpuIndex);
    }

    public function testKindAndVendorVocabulary(): void
    {
        $this->assertSame(['GPU', 'VRAM'], [AcceleratorKind::Gpu->label(), AcceleratorKind::Gpu->memoryLabel()]);
        $this->assertSame(['NPU', 'RAM'], [AcceleratorKind::Npu->label(), AcceleratorKind::Npu->memoryLabel()]);
        $this->assertSame(GpuVendor::Amd, GpuVendor::fromPciId(0x1022), 'XDNA NPUs use the CPU vendor id');
        $this->assertSame(GpuVendor::Amd, GpuVendor::fromPciId(GpuVendor::Amd->pciId()));
        $this->assertSame(GpuVendor::Intel, GpuVendor::fromPciId(0x8086));
        $this->assertSame(GpuVendor::Nvidia, GpuVendor::fromPciId(0x10de));
        $this->assertNull(GpuVendor::fromPciId(0x1234));
        $this->assertSame(['nvidia', 'amd', 'intel'], array_map(static fn (GpuVendor $v): string => $v->value, GpuVendor::cases()), 'btop shown_gpus tokens');
    }

    public function testLowerBoundFlagTravelsWithTheUtilizationValue(): void
    {
        $floor = new GpuDevice(0, 'Arc', 30.0, -1, -1, -1.0, -1.0, vendor: GpuVendor::Intel, busId: 'b', utilizationLowerBound: true);
        $exact = new GpuDevice(0, 'Arc', 10.0, -1, -1, -1.0, -1.0, vendor: GpuVendor::Intel, busId: 'b');
        $na = new GpuDevice(0, 'Arc', -1.0, -1, -1, -1.0, -1.0, vendor: GpuVendor::Intel, busId: 'b');

        $this->assertFalse((new GpuDevice(0, 'x', 1.0, 1, 1, 1.0, 1.0))->utilizationLowerBound, 'default: exact');
        $this->assertTrue($na->heldFrom($floor)->utilizationLowerBound, 'held value keeps its flag');
        $this->assertSame(30.0, $na->heldFrom($floor)->utilization);
        $this->assertFalse($exact->heldFrom($floor)->utilizationLowerBound, 'a fresh exact reading clears it');
        $this->assertFalse($floor->unmeasured()->utilizationLowerBound);
        $this->assertTrue($floor->withIndex(3)->utilizationLowerBound);
    }
}
