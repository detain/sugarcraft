<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Panel\Gpu\GpuFunctions;
use SugarCraft\Top\View\GpuDetail;

/** btop supported_functions + gpu_b_height_offsets from one device. */
final class GpuFunctionsTest extends TestCase
{
    public function testAFullNvidiaDeviceNeedsEightStatRows(): void
    {
        $d = new GpuDevice(0, 'RTX', 40.0, 1024, 4096, 60.0, 50.0, memUtilization: 10.0, clockGraphics: 2000.0, clockMem: 10501.0, pstate: 'P2', encoderUtilization: 1.0, decoderUtilization: 0.0);
        $f = GpuFunctions::of($d);
        $this->assertTrue($f->utilization && $f->temp && $f->power && $f->pstate && $f->clock && $f->memClock && $f->memTotal && $f->memUsed && $f->memUtilization && $f->encoder && $f->decoder);
        $this->assertFalse($f->pcieTxRx, 'no collector measures PCIe throughput');
        // util + pwr + enc/dec + memory (1 + 2 + 2).
        $this->assertSame(8, $f->offset());
        $this->assertSame(2, GpuFunctions::pstate($d));
    }

    public function testPartialDevices(): void
    {
        $npu = GpuFunctions::of(new GpuDevice(0, 'NPU', 20.0, 1024, -1, -1.0, -1.0));
        $this->assertSame(2, $npu->offset(), 'util + memory (used only)');
        $this->assertSame(1, GpuFunctions::of((new GpuDevice(0, 'x', 1.0, 1, 2, 3.0, 4.0))->unmeasured())->offset(), 'a stand-in keeps only its total: one memory row');
        $this->assertNull(GpuFunctions::pstate(new GpuDevice(0, 'x', 1.0, 1, 2, 3.0, 4.0)));
        $this->assertNull(GpuFunctions::pstate(new GpuDevice(0, 'x', 1.0, 1, 2, 3.0, 4.0, pstate: 'Pfoo')));
    }

    public function testDetailGating(): void
    {
        $full = GpuFunctions::of(new GpuDevice(0, 'RTX', 40.0, 1024, 4096, 60.0, 50.0, memUtilization: 10.0, clockGraphics: 2000.0, clockMem: 1.0, pstate: 'P0', encoderUtilization: 1.0, decoderUtilization: 1.0));
        $this->assertSame($full, $full->forDetail(GpuDetail::Full));
        $compact = $full->forDetail(GpuDetail::Compact);
        $this->assertTrue($compact->utilization && $compact->temp && $compact->power && $compact->pstate && $compact->clock);
        $this->assertFalse($compact->memTotal || $compact->memUsed || $compact->memUtilization || $compact->memClock || $compact->encoder || $compact->decoder);
        $minimal = $full->forDetail(GpuDetail::Minimal);
        $this->assertFalse($minimal->power || $minimal->pstate);
        $this->assertTrue($minimal->utilization && $minimal->clock);
    }
}
