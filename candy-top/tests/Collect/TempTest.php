<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Collect\Temp;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class TempTest extends TestCase
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

    public function testHwmonCpuPackageAndBtopCoreOrdering(): void
    {
        [$snap] = Temp::new($this->tree->paths())->sample();

        $this->assertSame('coretemp/Package id 0', $snap->cpuSensor);
        $this->assertSame(55.0, $snap->cpu());
        $this->assertSame(100.0, $snap->cpuCrit());
        $this->assertSame(84.0, $snap->sensors['coretemp/Package id 0']->high);
        // Lexical then stable-by-length: Core 0, Core 1, Core 2, Core 10.
        $this->assertSame([50.0, 52.0, 51.0, 60.0], $snap->cores);
        $this->assertSame(40.0, $snap->sensors['acpitz/temp1']->temp, 'unlabeled → tempN, crit default');
        $this->assertSame(95.0, $snap->sensors['acpitz/temp1']->crit);
        $this->assertArrayNotHasKey('thermal0/x86_pkg_temp', $snap->sensors, 'thermal zones only without a cpu sensor');
    }

    public function testDiscoveryIsCachedAndInputsReread(): void
    {
        [, $temp] = Temp::new($this->tree->paths())->sample();
        $this->tree->write('sys/class/hwmon/hwmon0/temp1_input', "70500\n");
        $this->tree->remove('sys/class/hwmon/hwmon0/temp2_input');
        [$snap, $again] = $temp->sample();

        $this->assertSame(70.5, $snap->cpu());
        $this->assertSame(Sentinel::UNMEASURED, $snap->cores[0], 'vanished input → sentinel');
        $this->assertSame($temp, $again);
    }

    public function testThermalZoneFallbackWithTripPoints(): void
    {
        $this->tree->remove('sys/class/hwmon');
        $this->tree->write('sys/class/thermal/thermal_zone0/trip_point_0_type', "high\n");
        $this->tree->write('sys/class/thermal/thermal_zone0/trip_point_0_temp', "70000\n");
        $this->tree->write('sys/class/thermal/thermal_zone0/trip_point_1_type', "critical\n");
        $this->tree->write('sys/class/thermal/thermal_zone0/trip_point_1_temp', "105000\n");
        [$snap] = Temp::new($this->tree->paths())->sample();

        $this->assertSame('thermal0/x86_pkg_temp', $snap->cpuSensor);
        $this->assertSame(45.0, $snap->cpu());
        $this->assertSame(70.0, $snap->sensors['thermal0/x86_pkg_temp']->high);
        $this->assertSame(105.0, $snap->cpuCrit());
        $this->assertSame([], $snap->cores, 'cpu_temp_only');
    }

    public function testCpuFallbackPrefersCpuOrK10tempName(): void
    {
        $this->tree->remove('sys/class/hwmon/hwmon0');
        $this->tree->remove('sys/class/thermal');
        $this->tree->write('sys/class/hwmon/hwmon3/name', "k10temp\n");
        $this->tree->write('sys/class/hwmon/hwmon3/temp1_input', "61000\n");
        $this->tree->write('sys/class/hwmon/hwmon3/temp1_label', "Tctl\n");
        [$snap] = Temp::new($this->tree->paths())->sample();

        $this->assertSame('k10temp/Tctl', $snap->cpuSensor);
    }

    public function testPreferredSensorOverride(): void
    {
        [$snap] = Temp::new($this->tree->paths(), 'acpitz/temp1')->sample();

        $this->assertSame('acpitz/temp1', $snap->cpuSensor);
        $this->assertSame(40.0, $snap->cpu());
    }

    public function testDeviceSubdirAndNvmeSkip(): void
    {
        $this->tree->write('sys/class/hwmon/hwmon5/device/temp1_input', "33000\n");
        $this->tree->write('sys/class/hwmon/hwmon5/device/name', "board\n");
        // Live hwmon entries are symlinks into the device path; an nvme
        // drive's canonical path contains "nvme", which btop skips.
        $this->tree->write('sys/devices/pci0000:00/nvme/nvme0/hwmon6/temp1_input', "1000\n");
        $this->tree->write('sys/devices/pci0000:00/nvme/nvme0/hwmon6/name', "nvme\n");
        symlink($this->tree->root . '/sys/devices/pci0000:00/nvme/nvme0/hwmon6', $this->tree->root . '/sys/class/hwmon/hwmon6');
        [$snap] = Temp::new($this->tree->paths())->sample();

        $this->assertSame(33.0, $snap->sensors['board/temp1']->temp);
        $this->assertArrayNotHasKey('nvme/temp1', $snap->sensors);
    }

    public function testNoSensorsAtAll(): void
    {
        $tree = FixtureTree::empty();
        try {
            [$snap] = Temp::new($tree->paths())->sample();
            $this->assertNull($snap->cpuSensor);
            $this->assertSame(Sentinel::UNMEASURED, $snap->cpu());
            $this->assertSame(Sentinel::UNMEASURED, $snap->cpuCrit());
        } finally {
            $tree->destroy();
        }
    }
}
