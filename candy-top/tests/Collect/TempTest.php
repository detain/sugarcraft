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
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        [, $temp] = Temp::new($this->tree->paths(), null, $clock)->sample();
        $this->tree->write('sys/class/hwmon/hwmon0/temp1_input', "70500\n");
        $this->tree->remove('sys/class/hwmon/hwmon0/temp2_input');
        $now = Temp::SENSOR_INTERVAL;
        [$snap, $again] = $temp->sample();

        $this->assertSame(70.5, $snap->cpu());
        $this->assertSame(Sentinel::UNMEASURED, $snap->cores[0], 'vanished input → sentinel');
        $this->assertArrayNotHasKey('acpitz/temp2', $snap->sensors, 'the removed input is gone but discovery did not re-run');
    }

    /**
     * candy-top-030: a single sysfs read cost ~100 ms on the reviewer's
     * slow-bus host, so the per-tick version blew the frame budget every
     * 50 ms forever. The cadence now admits at most one re-read window.
     */
    public function testSensorReadsObeyTheCadenceWindow(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        [$first, $temp] = Temp::new($this->tree->paths(), null, $clock)->sample();
        $this->assertSame(55.0, $first->cpu());

        // Mid-window changes stay invisible until the cadence expires.
        $this->tree->write('sys/class/hwmon/hwmon0/temp1_input', "70500\n");
        $now = 3.0;
        [$stale, $temp] = $temp->sample();
        $this->assertSame(55.0, $stale->cpu(), 'inside the window: no bus touch');
        $now = 4.999;
        [$staler] = $temp->sample();
        $this->assertSame(55.0, $staler->cpu());

        // At the boundary exactly one re-read happens, and the instance
        // returned from consecutive in-window samples is the same object.
        $now = 5.0;
        [$fresh, $after] = $temp->sample();
        $this->assertSame(70.5, $fresh->cpu());
        $now = 8.0;
        [$held, $again] = $after->sample();
        $this->assertSame(70.5, $held->cpu(), 'the new window caches again');
        $this->tree->write('sys/class/hwmon/hwmon0/temp1_input', "65000\n");
        [$still] = $again->sample();
        $this->assertSame(70.5, $still->cpu());
        $now = 10.0;
        [$next] = $again->sample();
        $this->assertSame(65.0, $next->cpu());
    }

    public function testHostileSysfsNamesNeverReachTheDisplayKeys(): void
    {
        // candy-top-046: control/NUL bytes in driver name strings used to
        // flow straight into sensor keys (and thus the cpu box labels).
        $this->tree->write('sys/class/hwmon/hwmon9/name', "evil\x01chip\x00\n");
        $this->tree->write('sys/class/hwmon/hwmon9/temp1_input', "44000\n");
        $this->tree->write('sys/class/hwmon/hwmon9/temp1_label', "Tc\x07ntrol\x00\n");
        [$snap] = Temp::new($this->tree->paths())->sample();

        $this->assertArrayHasKey('evil chip/Tc ntrol', $snap->sensors);
        $this->assertSame(44.0, $snap->sensors['evil chip/Tc ntrol']->temp);
        foreach (array_keys($snap->sensors) as $key) {
            $this->assertMatchesRegularExpression('/^[\x20-\x7E]+$/', $key, $key);
        }
    }

    public function testAbsurdReadingsCollapseToTheSentinel(): void
    {
        // candy-top-046: a disconnected thermistor reports -99999 °C; it
        // must not publish as a real reading.
        $this->tree->write('sys/class/hwmon/hwmon0/temp1_input', "-99999000\n");
        [$snap] = Temp::new($this->tree->paths())->sample();
        $this->assertSame(Sentinel::UNMEASURED, $snap->cpu());

        $this->tree->write('sys/class/hwmon/hwmon0/temp1_input', "999999000\n");
        [, $temp] = Temp::new($this->tree->paths(), null, static fn (): float => 10.0)->sample();
        [$hot] = $temp->sample();
        $this->assertSame(Sentinel::UNMEASURED, $hot->sensors['coretemp/Package id 0']->temp);
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
