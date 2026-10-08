<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\Gpu\AmdSysfs;
use SugarCraft\Top\Collect\Gpu\DrmScan;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\Sentinel;

final class AmdSysfsTest extends TestCase
{
    private GpuTree $tree;

    protected function setUp(): void
    {
        $this->tree = GpuTree::of('amd');
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    public function testEnumeratesOnlyAmdgpuCardsWithMetrics(): void
    {
        $amd = AmdSysfs::detect($this->tree->paths());
        $this->assertNotNull($amd);
        // card0 (Navi 21), card1 (Strix Halo), card4 (busy only). Skipped:
        // card0-DP-1 / renderD128 (not card nodes), card2 (radeon driver),
        // card3 (no readable metrics).
        $this->assertSame(['card0', 'card1', 'card4'], array_map(static fn ($c): string => $c->name, $amd->cards()));
        $this->assertSame(GpuVendor::Amd, $amd->vendor());
        $this->assertSame(AcceleratorKind::Gpu, $amd->kind());
        $this->assertFalse($amd->needsDrmScan());
        $this->assertTrue($amd->drmClients());
    }

    public function testDiscreteCardReadsEverySysfsNode(): void
    {
        [$snap] = AmdSysfs::detect($this->tree->paths())->poll(DrmScan::none());
        $d = $snap->devices[0];

        $this->assertSame(0, $d->index);
        $this->assertSame('AMD Radeon RX 6800 XT', $d->name, 'amdgpu.ids (73BF, C1)');
        $this->assertSame(37.0, $d->utilization);
        $this->assertSame(12.0, $d->memUtilization);
        $this->assertSame(4294967296, $d->memUsed);
        $this->assertSame(17163091968, $d->memTotal);
        $this->assertSame(52.0, $d->temp, 'edge label');
        $this->assertSame(70.0, $d->tempMem, 'mem label');
        $this->assertSame(152.0, $d->watts, 'power1_average µW → W');
        $this->assertSame(255.0, $d->powerLimit, 'power1_cap');
        $this->assertSame(2310.0, $d->clockGraphics, 'hwmon freq1_input Hz → MHz');
        $this->assertSame(1000.0, $d->clockMem, 'hwmon freq2_input');
        $this->assertSame(2575.0, $d->clockGraphicsMax, 'top pp_dpm_sclk level');
        $this->assertSame(1000.0, $d->clockMemMax);
        $this->assertEqualsWithDelta(40.0, $d->fanSpeed, 0.001, 'pwm1 102 / 255');
        $this->assertSame(4, $d->pcieGen);
        $this->assertSame(16, $d->pcieWidth);
        $this->assertSame(Sentinel::UNAVAILABLE, $d->pstate);
        $this->assertSame('0000:03:00.0', $d->busId);
        $this->assertSame('amdgpu', $d->driver);
        $this->assertSame(GpuVendor::Amd, $d->vendor);
        $this->assertSame([], $snap->npus);
        $this->assertNull($snap->processes);
    }

    public function testApuUsesAmdgpuIdsRevisionDpmClockAndObservedPeakAsLimit(): void
    {
        $amd = AmdSysfs::detect($this->tree->paths());
        [$snap, $amd] = $amd->poll(DrmScan::none());
        $d = $snap->devices[1];

        $this->assertSame('Radeon 8050S Graphics', $d->name, '#1854: (1586, C2) — pci.ids cannot tell 8050S from 8060S');
        $this->assertSame(100.0, $d->utilization, 'gpu_busy_percent clamped to 0..100');
        $this->assertSame(600.0, $d->clockGraphics, 'pp_dpm_sclk * level (no freq1_input)');
        $this->assertSame(2900.0, $d->clockGraphicsMax);
        $this->assertSame(Sentinel::UNMEASURED, $d->clockMem, 'no mclk table');
        $this->assertSame(23.0, $d->watts, 'power1_input when no power1_average');
        $this->assertSame(23.0, $d->powerLimit, 'no power1_cap: btop observed-peak scale');
        $this->assertSame(Sentinel::UNMEASURED_INT, $d->pcieGen, 'iGPU has no link');
        $this->assertSame(Sentinel::UNMEASURED, $d->fanSpeed);

        $this->tree->write('sys/class/drm/card1/device/hwmon/hwmon5/power1_input', "31000000\n");
        [$snap, $amd] = $amd->poll(DrmScan::none());
        $this->assertSame(31.0, $snap->devices[1]->powerLimit, 'peak grows');
        $this->tree->write('sys/class/drm/card1/device/hwmon/hwmon5/power1_input', "12000000\n");
        [$snap] = $amd->poll(DrmScan::none());
        $this->assertSame(12.0, $snap->devices[1]->watts);
        $this->assertSame(31.0, $snap->devices[1]->powerLimit, 'peak holds');
    }

    public function testUnknownDeviceFallsBackToBtopName(): void
    {
        [$snap] = AmdSysfs::detect($this->tree->paths())->poll(DrmScan::none());

        $this->assertSame('AMD GPU (1002:7ffe)', $snap->devices[2]->name);
        $this->assertSame(0.0, $snap->devices[2]->utilization);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->devices[2]->memTotal);
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[2]->temp, 'no hwmon');
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[2]->powerLimit);
    }

    public function testPciIdsIsTheSecondNameSource(): void
    {
        unlink($this->tree->root . '/usr/share/libdrm/amdgpu.ids');
        [$snap] = AmdSysfs::detect($this->tree->paths())->poll(DrmScan::none());

        $this->assertSame('Navi 21 [Radeon RX 6800/6800 XT / 6900 XT]', $snap->devices[0]->name);
        $this->assertSame('AMD GPU (1002:1586)', $snap->devices[1]->name, 'absent from the trimmed pci.ids too');
    }

    public function testVanishedNodesBecomeSentinels(): void
    {
        $amd = AmdSysfs::detect($this->tree->paths());
        unlink($this->tree->root . '/sys/class/drm/card0/device/gpu_busy_percent');
        unlink($this->tree->root . '/sys/class/drm/card0/device/hwmon/hwmon2/temp1_input');
        [$snap] = $amd->poll(DrmScan::none());

        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[0]->utilization);
        $this->assertSame(Sentinel::UNMEASURED, $snap->devices[0]->temp);
        $this->assertSame(12.0, $snap->devices[0]->memUtilization, 'the rest still reads');
    }

    public function testNoAmdHardwareInstallsNothing(): void
    {
        $this->assertNull(AmdSysfs::detect(Paths::under($this->tree->root . '/nonexistent')));
        $intel = GpuTree::of('intel');
        try {
            $this->assertNull(AmdSysfs::detect($intel->paths()), 'Intel cards only');
        } finally {
            $intel->destroy();
        }
    }

    public function testRuntimeSuspendedCardIsNotWoken(): void
    {
        $amd = AmdSysfs::detect($this->tree->paths());
        $this->tree->write('sys/class/drm/card0/device/power/runtime_status', "suspended\n");
        // A node read would show up as this value; the suspended path must not read it.
        $this->tree->write('sys/class/drm/card0/device/gpu_busy_percent', "99\n");
        [$snap] = $amd->poll(DrmScan::none());
        $d = $snap->devices[0];

        $this->assertSame([0, 'AMD Radeon RX 6800 XT', '0000:03:00.0'], [$d->index, $d->name, $d->busId], 'still listed');
        $this->assertSame(0.0, $d->utilization, 'suspended = idle: a real 0, not 99 (never read) nor a sentinel');
        $this->assertSame(0.0, $d->memUtilization);
        $this->assertSame(Sentinel::UNMEASURED_INT, $d->memUsed);
        $this->assertSame(Sentinel::UNMEASURED, $d->watts);
        $this->assertSame(Sentinel::UNMEASURED, $d->temp);
        $this->assertSame(Sentinel::UNMEASURED_INT, $d->memTotal);
        $this->assertSame(Sentinel::UNMEASURED, $d->clockGraphics);

        $this->tree->write('sys/class/drm/card0/device/power/runtime_status', "active\n");
        [$snap] = $amd->poll(DrmScan::none());
        $this->assertSame(99.0, $snap->devices[0]->utilization, 'awake again: read normally');
    }

    public function testDeepSleepDpmLineIsCurrentButNeverMax(): void
    {
        $amd = AmdSysfs::detect($this->tree->paths());
        $this->tree->write('sys/class/drm/card1/device/pp_dpm_sclk', "S: 19Mhz *\n0: 600Mhz \n1: 2900Mhz \n");
        [$snap] = $amd->poll(DrmScan::none());
        $this->assertSame(19.0, $snap->devices[1]->clockGraphics);
        $this->assertSame(2900.0, $snap->devices[1]->clockGraphicsMax);

        $this->tree->write('sys/class/drm/card1/device/pp_dpm_sclk', "S: 9999Mhz \n0: 600Mhz \n1: 2900Mhz *\n");
        [$snap] = $amd->poll(DrmScan::none());
        $this->assertSame(2900.0, $snap->devices[1]->clockGraphics);
        $this->assertSame(2900.0, $snap->devices[1]->clockGraphicsMax, 'an unmarked S line is ignored for max too');
    }

    /** Busy → runtime-suspended must read 0 %, so a #1008 hold cannot freeze the busy value. */
    public function testBusyThenSuspendedShowsZeroThroughTheHold(): void
    {
        $amd = AmdSysfs::detect($this->tree->paths());
        $this->tree->write('sys/class/drm/card0/device/gpu_busy_percent', "90\n");
        [$busy, $amd] = $amd->poll(DrmScan::none());
        $this->assertSame(90.0, $busy->devices[0]->utilization);

        $this->tree->write('sys/class/drm/card0/device/power/runtime_status', "suspended\n");
        [$idle] = $amd->poll(DrmScan::none());
        $held = $idle->devices[0]->heldFrom($busy->devices[0]);

        $this->assertSame(0.0, $held->utilization, 'CpuPanel::holdDevice() result');
        $this->assertSame(0.0, $held->memUtilization);
        $this->assertSame(17163091968, $held->memTotal, 'unread columns keep their last value');
    }
}
