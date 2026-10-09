<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Ipmi;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Ipmi\IpmiParser;
use SugarCraft\Top\Collect\Ipmi\IpmiReadings;
use SugarCraft\Top\Collect\Ipmi\IpmiSensor;
use SugarCraft\Top\Collect\Ipmi\Redundancy;
use SugarCraft\Top\Collect\Ipmi\SensorKind;
use SugarCraft\Top\Collect\Ipmi\SensorStatus;

/**
 * Grouping a sensor list into what the box draws, across vendors: no-
 * reading rows dropped, HP's DutyCycle / Presence duplicates folded,
 * supplies assembled from AMI `PSUn Power In/Out` and HP `Power Supply n`
 * / `PS n Output` / `PS n Presence`, the fan redundancy decoded from a
 * bitmask (`sensor list`) or text (`sdr elist`).
 */
final class IpmiReadingsTest extends TestCase
{
    private static function readings(string $host, string $file = 'sensor-list.txt'): IpmiReadings
    {
        $text = (string) file_get_contents(\dirname(__DIR__, 2) . "/fixtures/ipmi/$host/$file");

        return IpmiReadings::of($file === 'sensor-list.txt' ? IpmiParser::sensorList($text) : IpmiParser::sdrElist($text));
    }

    /** @param list<IpmiSensor> $s @return list<string> */
    private static function names(array $s): array
    {
        return array_map(static fn (IpmiSensor $x): string => $x->name, $s);
    }

    public function testAmiGroups(): void
    {
        $r = self::readings('skynet2');
        $this->assertCount(14, $r->temps, '15 reading temps minus none: CPU1, TR2-5, 9 DIMMs, Inlet');
        $this->assertContains('Inlet Temp', self::names($r->temps));
        $this->assertNotContains('CPU2 Temperature', self::names($r->temps));
        $this->assertCount(11, $r->fans);
        $this->assertCount(10, $r->volts);
        $this->assertSame(['CPU_Power', 'Memory_Power'], self::names($r->rails));
        $this->assertSame([], $r->currents);
        $this->assertNull($r->redundancy);

        $this->assertCount(4, $r->psus);
        $this->assertCount(4, $r->livePsus());
        $psu1 = $r->psus[0];
        $this->assertSame(1, $psu1->index);
        $this->assertSame(480.0, $psu1->inWatts());
        $this->assertSame(448.0, $psu1->outWatts());
        $this->assertEqualsWithDelta(0.9333, $psu1->efficiency(), 0.001);
        $this->assertSame(2592.0, $psu1->capacity());
        $this->assertNull($r->psus[2]->efficiency(), 'out == in is no efficiency');
        $this->assertSame(4 * 2592.0, $r->capacity());
        $this->assertSame(480.0 + 496 + 480 + 512, $r->psuInput());
        $this->assertSame(SensorStatus::Ok, $r->worst());
        $this->assertSame(0, $r->alarms());
    }

    public function testHpGroupsFoldDuplicatesAndFindTheAbsentSupply(): void
    {
        $r = self::readings('kvm521');
        $this->assertCount(24, $r->temps);
        $this->assertSame(['Fan 1', 'Fan 2', 'Fan 3', 'Fan 4', 'Fan 5', 'Fan 6', 'Fan 7'], self::names($r->fans), 'DutyCycle duplicates folded');
        $this->assertSame(SensorKind::FanDuty, $r->fans[0]->kind);
        $this->assertSame([], $r->volts);
        $this->assertSame(['Power Meter'], self::names($r->rails), 'PwrMeter Output repeats the meter');
        $this->assertSame(Redundancy::Full, $r->redundancy);

        $this->assertCount(2, $r->psus);
        $this->assertCount(1, $r->livePsus());
        $this->assertSame(225.0, $r->psus[0]->inWatts());
        $this->assertTrue($r->psus[0]->present);
        $this->assertFalse($r->psus[1]->present);
        $this->assertSame([2], array_map(static fn ($p) => $p->index, $r->absentPsus()));
        $this->assertNull($r->capacity(), 'HP states no PSU thresholds');
    }

    public function testTheSdrTextFormGroupsTheSame(): void
    {
        $r = self::readings('kvm521', 'sdr-elist.txt');
        $this->assertSame(Redundancy::Full, $r->redundancy);
        $this->assertCount(7, $r->fans);
        $this->assertFalse($r->psus[1]->present, '"Device Absent"');
        $this->assertTrue($r->psus[0]->present);
        $this->assertCount(24, $r->temps);

        $ami = self::readings('skynet2', 'sdr-elist.txt');
        $this->assertCount(14, $ami->temps);
        $this->assertCount(4, $ami->livePsus());
    }

    public function testAThresholdCrossingRaisesTheWorstState(): void
    {
        $hot = new IpmiSensor('CPU1 Temp', 94.0, 'degrees C', SensorKind::Temperature, SensorStatus::Ok, unc: 92.0, ucr: 93.0, unr: 95.0);
        $this->assertSame(SensorStatus::Critical, $hot->severity());
        $stopped = new IpmiSensor('FAN1', 0.0, 'RPM', SensorKind::FanRpm, SensorStatus::Ok, lnr: 0.0, lcr: 520.0);
        $this->assertSame(SensorStatus::NonRecoverable, $stopped->severity());
        $warm = new IpmiSensor('X', 50.0, 'degrees C', SensorKind::Temperature, SensorStatus::NonCritical);
        $this->assertSame(SensorStatus::NonCritical, $warm->severity(), 'the BMC\'s louder word wins');

        $r = IpmiReadings::of([$hot, $stopped]);
        $this->assertSame(SensorStatus::NonRecoverable, $r->worst());
        $this->assertSame(2, $r->alarms());
    }

    public function testRedundancyStates(): void
    {
        $this->assertSame(Redundancy::Full, Redundancy::fromState(0x01));
        $this->assertSame(Redundancy::Lost, Redundancy::fromState(0x02));
        $this->assertSame(Redundancy::Degraded, Redundancy::fromState(0x04));
        $this->assertSame(Redundancy::NonRedundantSufficient, Redundancy::fromState(0x08));
        $this->assertSame(Redundancy::NonRedundantInsufficient, Redundancy::fromState(0x20));
        $this->assertNull(Redundancy::fromState(0));
        $this->assertNull(Redundancy::fromState(null));
        $this->assertSame(Redundancy::Lost, Redundancy::fromText('Redundancy Lost'));
        $this->assertSame(Redundancy::NonRedundantInsufficient, Redundancy::fromText('Non-Redundant: Insufficient Resources'));
        $this->assertSame(Redundancy::NonRedundantSufficient, Redundancy::fromText('Non-Redundant: Sufficient from Insufficient'));
        $this->assertNull(Redundancy::fromText('Device Present'));
        $this->assertSame(SensorStatus::Critical, Redundancy::Lost->severity());
        $this->assertSame('ipmi.redundancy.full', Redundancy::Full->key());
    }
}
