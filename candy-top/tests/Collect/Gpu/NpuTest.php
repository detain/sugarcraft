<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\Gpu\AmdNpu;
use SugarCraft\Top\Collect\Gpu\DrmScan;
use SugarCraft\Top\Collect\Gpu\IntelNpu;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Collect\Sentinel;

final class NpuTest extends TestCase
{
    private GpuTree $tree;

    private float $now = 10.0;

    protected function setUp(): void
    {
        $this->tree = GpuTree::of('npu');
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    public function testIntelNpuBusyTimeBecomesUtilization(): void
    {
        $npu = IntelNpu::detect($this->tree->paths(), fn (): float => $this->now);
        $this->assertNotNull($npu);
        $this->assertSame(AcceleratorKind::Npu, $npu->kind());
        $this->assertFalse($npu->needsDrmScan());

        [$snap, $npu] = $npu->poll(DrmScan::none());
        $this->assertSame([], $snap->devices, 'NPUs never land in the GPU list (#985)');
        $this->assertCount(1, $snap->npus, 'accel0 and the intel_vpu driver dir are the same slot');
        $d = $snap->npus[0];
        $this->assertSame('Meteor Lake NPU', $d->name);
        $this->assertSame(AcceleratorKind::Npu, $d->kind);
        $this->assertSame('NPU', $d->kind->label());
        $this->assertSame('RAM', $d->kind->memoryLabel());
        $this->assertSame(GpuVendor::Intel, $d->vendor);
        $this->assertSame('0000:00:0b.0', $d->busId);
        $this->assertSame(Sentinel::UNMEASURED, $d->utilization, 'cumulative counter: first read has no rate');
        $this->assertSame(268435456, $d->memUsed);
        $this->assertSame(1400.0, $d->clockGraphics);
        $this->assertSame(1400.0, $d->clockGraphicsMax);
        $this->assertFalse($snap->available());
        $this->assertTrue($snap->hasAccelerators());

        $this->now += 2.0;
        $this->tree->write('sys/class/accel/accel0/device/npu_busy_time_us', "5500000\n");
        [$snap] = $npu->poll(DrmScan::none());
        $this->assertEqualsWithDelta(25.0, $snap->npus[0]->utilization, 1e-9, '0.5 s busy over 2 s');
    }

    public function testAmdNpuIsListedWithSentinelStats(): void
    {
        $npu = AmdNpu::detect($this->tree->paths());
        $this->assertNotNull($npu);
        [$snap] = $npu->poll(DrmScan::none());

        $this->assertCount(1, $snap->npus);
        $d = $snap->npus[0];
        $this->assertSame('AMD NPU', $d->name, '1022:17f0 is not in the trimmed pci.ids');
        $this->assertSame(GpuVendor::Amd, $d->vendor);
        $this->assertSame('amdxdna', $d->driver);
        $this->assertSame('0000:c6:00.1', $d->busId);
        $this->assertSame(Sentinel::UNMEASURED, $d->utilization, 'FFI ioctl deferred');
        $this->assertSame(Sentinel::UNMEASURED_INT, $d->memUsed);
    }

    public function testNoNpuHardwareInstallsNothing(): void
    {
        $amd = GpuTree::of('amd');
        try {
            $this->assertNull(IntelNpu::detect($amd->paths()));
            $this->assertNull(AmdNpu::detect($amd->paths()));
        } finally {
            $amd->destroy();
        }
    }
}
