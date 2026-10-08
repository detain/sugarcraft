<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\FreeBsd\Battery;
use SugarCraft\Top\Collect\FreeBsd\Freq;
use SugarCraft\Top\Collect\FreeBsd\Temp;
use SugarCraft\Top\Collect\FreqMode;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

/** freq / temp / battery: present (synthetic) and absent (the reference host). */
final class SensorsTest extends TestCase
{
    public function testFreqAbsentOnTheReferenceHostIsASentinel(): void
    {
        [$snap] = Freq::new(new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt')))->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->mhz);
        $this->assertSame('', $snap->label);
        $this->assertSame([], $snap->cores);
        $this->assertSame(Sentinel::UNMEASURED, $snap->maxMhz);
        $this->assertNull($snap->boost);
    }

    public function testFreqDiscoversOnceThenAsksOnlyPublishedFreqOids(): void
    {
        $bare = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'));
        [, $freq] = Freq::new($bare)->sample();
        $freq->sample();
        $this->assertSame([['hw.ncpu', 'dev.cpu']], $bare->sysctls, 'no cpufreq: no sysctl child after discovery');

        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-cpufreq.synthetic.txt'));
        [, $freq] = Freq::new($probe)->sample();
        [$snap, $freq] = $freq->sample();
        $this->assertSame(['dev.cpu.0.freq'], $probe->sysctls[1]);
        $this->assertSame(800.0, $snap->minMhz, 'freq_levels cached from discovery');
        [$perCore] = $freq->withPerCore(true)->sample();
        $this->assertSame(['dev.cpu.0.freq', 'dev.cpu.1.freq'], $probe->sysctls[2]);
        $this->assertSame(3400.0, $perCore->perCore[1]);
        $this->assertSame(Sentinel::UNMEASURED, $perCore->perCore[2]);

        $failed = new FixtureProbe();
        [, $retry] = Freq::new($failed)->sample();
        $retry->sample();
        $this->assertSame([['hw.ncpu', 'dev.cpu'], ['hw.ncpu', 'dev.cpu']], $failed->sysctls, 'an empty discovery is retried');
    }

    public function testFreqFromCpu0AndLevels(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-cpufreq.synthetic.txt'));
        [$snap] = Freq::new($probe)->sample();

        $this->assertSame(2800.0, $snap->mhz);
        $this->assertSame('2.8 GHz', $snap->label, 'btop #1792 format, shared with Linux');
        $this->assertSame(800.0, $snap->minMhz);
        $this->assertSame(3400.0, $snap->maxMhz);
        $this->assertSame([], $snap->perCore);
    }

    public function testFreqPerCoreAndModes(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-cpufreq.synthetic.txt'));
        [$snap] = Freq::new($probe, FreqMode::Highest)->withPerCore(true)->sample();

        $this->assertSame([0 => 2800.0, 1 => 3400.0, 2 => Sentinel::UNMEASURED, 3 => Sentinel::UNMEASURED], $snap->perCore);
        $this->assertSame(3400.0, $snap->mhz);
        $this->assertSame(800.0, $snap->perCoreMin[3], 'cpu0 levels stand in for cores without their own');

        [$range] = Freq::new($probe, FreqMode::Range, true)->sample();
        $this->assertSame('2.8 GHz - 3.4 GHz', $range->label);
    }

    public function testTempFromTheReferenceAcpiZone(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'));
        [$snap, $temp] = Temp::new($probe)->sample();

        $this->assertSame(['acpi/tz0'], array_keys($snap->sensors));
        $this->assertSame(40.1, $snap->cpu());
        $this->assertSame(60.1, $snap->cpuCrit(), '_CRT');
        $this->assertSame(80.0, $snap->sensors['acpi/tz0']->high, '_HOT/_PSV are -1 (unset)');
        $this->assertSame([], $snap->cores, 'no coretemp loaded');

        [$again] = $temp->sample();
        $this->assertSame(40.1, $again->cpu());
        $this->assertSame([['hw.acpi.thermal', 'dev.cpu'], ['hw.acpi.thermal.tz0.temperature']], $probe->sysctls, 'discovery once, then only the found OIDs');
    }

    public function testTempCoretempAverageAndOverride(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-cpufreq.synthetic.txt'));
        [$snap] = Temp::new($probe)->sample();

        $this->assertSame([52.0, 55.0, 50.0, Sentinel::UNMEASURED], $snap->cores, 'cpu3 reads -1: unmeasured');
        $this->assertSame(Temp::AVERAGE, $snap->cpuSensor, 'no ACPI zone: btop averages the cores');
        $this->assertSame(52.3, $snap->cpu());
        $this->assertSame(100.0, $snap->sensors['coretemp/Core 1']->crit, "cpu0's tjmax for every core");

        [$pinned] = Temp::new($probe, 'coretemp/Core 1')->sample();
        $this->assertSame(55.0, $pinned->cpu());
    }

    public function testNoSensorsAtAll(): void
    {
        $probe = new FixtureProbe();
        [$snap, $temp] = Temp::new($probe)->sample();

        $this->assertSame([], $snap->sensors);
        $this->assertNull($snap->cpuSensor);
        $this->assertSame(Sentinel::UNMEASURED, $snap->cpu());
        $temp->sample();
        $this->assertCount(2, $probe->sysctls, 'a failed discovery is retried, not memoised as "no sensors"');
    }

    public function testBatteryAbsentOnTheReferenceHost(): void
    {
        [$snap] = Battery::new(new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt')))->sample();

        $this->assertFalse($snap->present());
    }

    public function testBatteryDischargingInMinutesAndMilliwatts(): void
    {
        [$snap] = Battery::new(new FixtureProbe(FixtureProbe::fixture('sysctl-battery-discharging.synthetic.txt')))->sample();

        $this->assertSame('acpi', $snap->name);
        $this->assertSame(74, $snap->percent);
        $this->assertSame('discharging', $snap->status);
        $this->assertSame(306 * 60, $snap->seconds, '#1830/#1787: time is int minutes');
        $this->assertEqualsWithDelta(9.757, $snap->watts, 1e-9, '#1830/#1787: rate is int mW (the PR author saw 9.76W)');
    }

    public function testBatteryGarbageAndChargingAreSentinels(): void
    {
        [$snap] = Battery::new(new FixtureProbe(FixtureProbe::fixture('sysctl-battery-garbage.synthetic.txt')))->sample();

        $this->assertSame('charging', $snap->status);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->seconds);
        $this->assertSame(Sentinel::UNMEASURED, $snap->watts, 'rate 0 = not discharging (#1830), never 0.00W');

        $text = "hw.acpi.battery.life=50\nhw.acpi.battery.time=99999\nhw.acpi.battery.rate=5\nhw.acpi.battery.state=1\n";
        [$garbage] = Battery::new(new FixtureProbe($text))->sample();
        $this->assertSame(Sentinel::UNMEASURED_INT, $garbage->seconds, '#1787: > 10000 min is mid-plug garbage');
        $this->assertSame(Sentinel::UNMEASURED, $garbage->watts, '#1787: < 20 mW is garbage');

        [$ac] = Battery::new(new FixtureProbe("hw.acpi.battery.life=60\nhw.acpi.battery.state=0\nhw.acpi.acline=1\n"))->sample();
        $this->assertSame('charging', $ac->status, 'state 0 on AC below 100 %');
        [$full] = Battery::new(new FixtureProbe("hw.acpi.battery.life=100\nhw.acpi.battery.state=0\n"))->sample();
        $this->assertSame('full', $full->status);
        [$unknown] = Battery::new(new FixtureProbe("hw.acpi.battery.life=60\n"))->sample();
        $this->assertSame('unknown', $unknown->status);

        $b = Battery::new(new FixtureProbe(), 'Auto');
        $this->assertNull($b->selected());
        $this->assertSame('BAT1', $b->withSelected('BAT1')->selected());
    }
}
