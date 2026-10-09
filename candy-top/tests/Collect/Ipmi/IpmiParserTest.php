<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\Ipmi;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Ipmi\IpmiParser;
use SugarCraft\Top\Collect\Ipmi\IpmiSensor;
use SugarCraft\Top\Collect\Ipmi\SensorKind;
use SugarCraft\Top\Collect\Ipmi\SensorStatus;

/**
 * The ipmitool parsers against real (sanitized) captures from two very
 * different BMCs: ASUS/AMI (skynet2: RPM fans, six thresholds on the
 * rails, four PSUs) and HP iLO 4 (kvm521: percent fans with DutyCycle /
 * Presence duplicates, 37 numbered zones, LAN on channel 2, SEL full).
 */
final class IpmiParserTest extends TestCase
{
    private static function fixture(string $host, string $file): string
    {
        $path = \dirname(__DIR__, 2) . "/fixtures/ipmi/$host/$file";
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @param list<IpmiSensor> $sensors */
    private static function named(array $sensors, string $name): IpmiSensor
    {
        foreach ($sensors as $s) {
            if ($s->name === $name) {
                return $s;
            }
        }
        self::fail("no sensor $name");
    }

    public function testAmiSensorListValuesUnitsAndAllSixThresholds(): void
    {
        $sensors = IpmiParser::sensorList(self::fixture('skynet2', 'sensor-list.txt'));
        $this->assertCount(107, $sensors);

        $cpu = self::named($sensors, 'CPU1 Temperature');
        $this->assertSame(45.0, $cpu->value);
        $this->assertSame(SensorKind::Temperature, $cpu->kind);
        $this->assertSame(SensorStatus::Ok, $cpu->status);
        $this->assertNull($cpu->lnr);
        $this->assertSame(92.0, $cpu->unc);
        $this->assertSame(93.0, $cpu->ucr);
        $this->assertSame(95.0, $cpu->unr);
        $this->assertSame(93.0, $cpu->upper());

        $v12 = self::named($sensors, '+12V');
        $this->assertSame(SensorKind::Voltage, $v12->kind);
        $this->assertSame([9.628, 10.208, 10.788, 13.224, 13.804, 14.384], [$v12->lnr, $v12->lcr, $v12->lnc, $v12->unc, $v12->ucr, $v12->unr]);
        $this->assertSame(10.208, $v12->lower());

        $fan = self::named($sensors, 'GPU_FAN1');
        $this->assertSame(SensorKind::FanRpm, $fan->kind);
        $this->assertSame(16510.0, $fan->value);
        $this->assertNull($fan->upper(), 'AMI fans have lower thresholds only');

        $this->assertSame(SensorKind::Power, self::named($sensors, 'PSU1 Power Out')->kind);
        $this->assertSame(2592.0, self::named($sensors, 'PSU1 Power Out')->ucr);
    }

    public function testDisabledAndAbsentSensorsHaveNoReading(): void
    {
        $sensors = IpmiParser::sensorList(self::fixture('skynet2', 'sensor-list.txt'));
        $cpu2 = self::named($sensors, 'CPU2 Temperature');
        $this->assertNull($cpu2->value);
        $this->assertFalse($cpu2->reads());
        $this->assertSame(SensorStatus::Unknown, $cpu2->status, '`na` status');
        $this->assertSame(92.0, $cpu2->unc, 'thresholds still parse');
        $this->assertSame(SensorKind::Other, $cpu2->kind, 'blank unit');
    }

    public function testDiscreteSensorsKeepTheirStateBitmask(): void
    {
        $sensors = IpmiParser::sensorList(self::fixture('kvm521', 'sensor-list.txt'));
        $fans = self::named($sensors, 'Fans');
        $this->assertSame(SensorKind::Discrete, $fans->kind);
        $this->assertSame(0x1, $fans->state);
        $this->assertNull($fans->value);
        $this->assertSame(0x40, self::named($sensors, 'Memory Status')->state);
        $this->assertNull(self::named($sensors, 'Megacell Status')->state, '`na` discrete');
    }

    public function testHpFansAreDutyCycleAndNumberedZonesTemperatures(): void
    {
        $sensors = IpmiParser::sensorList(self::fixture('kvm521', 'sensor-list.txt'));
        $this->assertCount(71, $sensors);
        $this->assertSame(SensorKind::FanDuty, self::named($sensors, 'Fan 1')->kind);
        $this->assertSame(19.6, self::named($sensors, 'Fan 1')->value);
        $this->assertSame(SensorKind::FanDuty, self::named($sensors, 'Fan 1 DutyCycle')->kind);
        $inlet = self::named($sensors, '01-Inlet Ambient');
        $this->assertSame(17.0, $inlet->value);
        $this->assertNull($inlet->unc);
        $this->assertSame(42.0, $inlet->ucr);
        $this->assertSame(225.0, self::named($sensors, 'Power Supply 1')->value, 'integer watts');
    }

    public function testSdrElistReadingsAndDiscreteText(): void
    {
        $sensors = IpmiParser::sdrElist(self::fixture('kvm521', 'sdr-elist.txt'));
        $fan = self::named($sensors, 'Fan 1');
        $this->assertSame(19.6, $fan->value);
        $this->assertSame('percent', $fan->unit);
        $this->assertSame(SensorKind::FanDuty, $fan->kind);
        $this->assertSame('Transition to Running', $fan->text);

        $fans = self::named($sensors, 'Fans');
        $this->assertSame(SensorKind::Discrete, $fans->kind);
        $this->assertSame('Fully Redundant', $fans->text);
        $this->assertSame('Device Absent', self::named($sensors, 'PS 2 Presence')->text);

        $off = self::named($sensors, '08-HD Max');
        $this->assertNull($off->value);
        $this->assertSame(SensorStatus::NoSensor, $off->status);

        $ami = IpmiParser::sdrElist(self::fixture('skynet2', 'sdr-elist.txt'));
        $this->assertSame(45.0, self::named($ami, 'CPU1 Temperature')->value);
        $this->assertSame(16510.0, self::named($ami, 'GPU_FAN1')->value);
        $this->assertSame(SensorKind::FanRpm, self::named($ami, 'GPU_FAN1')->kind);
        $this->assertNull(self::named($ami, 'TR6 Temperature')->value, 'No Reading');
        $this->assertSame('', self::named($ami, 'Backplane1 HD01')->text, 'an empty discrete reading');
    }

    public function testMergeAddsThresholdsByName(): void
    {
        $values = IpmiParser::sdrElist(self::fixture('skynet2', 'sdr-elist.txt'));
        $thresholds = IpmiParser::sensorList(self::fixture('skynet2', 'sensor-list.txt'));
        $merged = IpmiParser::merge($values, $thresholds);
        $this->assertCount(\count($values), $merged);
        $v12 = self::named($merged, '+12V');
        $this->assertSame(12.35, $v12->value, 'the value is elist\'s');
        $this->assertSame(13.804, $v12->ucr, 'the thresholds are sensor list\'s');
        $this->assertFalse(self::named($merged, 'PSU1 Power In')->hasThresholds());
    }

    public function testStatusWordsIncludingSdrForms(): void
    {
        $this->assertSame(SensorStatus::NonCritical, SensorStatus::parse('nc'));
        $this->assertSame(SensorStatus::NonCritical, SensorStatus::parse('unc'));
        $this->assertSame(SensorStatus::Critical, SensorStatus::parse('lcr'));
        $this->assertSame(SensorStatus::NonRecoverable, SensorStatus::parse('nr'));
        $this->assertSame(SensorStatus::NoSensor, SensorStatus::parse('ns'));
        $this->assertSame(SensorStatus::Unknown, SensorStatus::parse('0x0080'));
        $this->assertSame(SensorStatus::Critical, SensorStatus::worst(SensorStatus::NonCritical, SensorStatus::Critical));
        $this->assertSame('cr', SensorStatus::Critical->word());
    }

    public function testDcmiOnBothBmcs(): void
    {
        $ami = IpmiParser::dcmi(self::fixture('skynet2', 'dcmi-power-reading.txt'));
        $this->assertNotNull($ami);
        $this->assertSame([2064.0, 256.0, 2240.0, 320.0, 5], [$ami->watts, $ami->min, $ami->max, $ami->avg, $ami->period]);
        $this->assertTrue($ami->active);

        $hp = IpmiParser::dcmi(self::fixture('kvm521', 'dcmi-power-reading.txt'));
        $this->assertNotNull($hp);
        $this->assertSame([221.0, 213.0, 487.0, 222.0, 300], [$hp->watts, $hp->min, $hp->max, $hp->avg, $hp->period]);

        $this->assertNull(IpmiParser::dcmi("DCMI request failed because: Invalid command (c1)\n"));
    }

    public function testChassisStatus(): void
    {
        $c = IpmiParser::chassis(self::fixture('kvm521', 'chassis-status.txt'));
        $this->assertNotNull($c);
        $this->assertTrue($c->powerOn);
        $this->assertFalse($c->fanFault || $c->driveFault || $c->intrusion || $c->powerFault());
        $this->assertSame('previous', $c->restorePolicy);

        $bad = IpmiParser::chassis("System Power : off\nCooling/Fan Fault : true\nChassis Intrusion : active\nMain Power Fault : true\n");
        $this->assertNotNull($bad);
        $this->assertFalse($bad->powerOn);
        $this->assertTrue($bad->fanFault && $bad->intrusion && $bad->powerFault());
        $this->assertNull(IpmiParser::chassis(''));
    }

    public function testSelInfoAndTheNewestEntry(): void
    {
        $ami = IpmiParser::selInfo(self::fixture('skynet2', 'sel-info.txt'));
        $this->assertNotNull($ami);
        $this->assertSame([107, 2, false], [$ami->entries, $ami->percentUsed, $ami->overflow]);
        $this->assertSame('09/20/2026 01:40:17 PM EDT', $ami->lastAdd);

        $hp = IpmiParser::selInfo(self::fixture('kvm521', 'sel-info.txt'));
        $this->assertNotNull($hp);
        $this->assertSame([64, 100], [$hp->entries, $hp->percentUsed]);

        $this->assertSame(100, IpmiParser::selInfo("Entries : 12\nFree Space : 0 bytes\n")?->percentUsed, 'no Percent Used line: a full log by free space');
        $this->assertSame('', IpmiParser::selInfo("Entries : 0\nLast Add Time : Not Available\n")?->lastAdd);

        $this->assertSame(
            '09/20/2026 01:40:17 PM EDT · Power Unit PowerUnit · Power off/down · Deasserted',
            IpmiParser::selLatest(self::fixture('skynet2', 'sel-elist-last-5.txt')),
        );
        $this->assertSame(
            'Pre-Init 0000000103 · System ACPI Power State ACPI · S0/G0: working · Asserted',
            IpmiParser::selLatest(self::fixture('kvm521', 'sel-elist-last-5.txt')),
        );
        $this->assertSame('', IpmiParser::selLatest("SEL has no entries\n"));
    }

    public function testHeaderFactsFromMcFruAndLan(): void
    {
        $ami = IpmiParser::info(
            self::fixture('skynet2', 'mc-info.txt'),
            self::fixture('skynet2', 'fru-print-0.txt'),
            self::fixture('skynet2', 'lan-print-1.txt'),
            1,
        );
        $this->assertSame('ESC8000A-E12', $ami->machine());
        $this->assertSame('ASUS', $ami->bmcVendor);
        $this->assertSame('1.02', $ami->firmware);
        $this->assertSame('2.0', $ami->ipmiVersion);
        $this->assertSame(2623, $ami->manufacturerId);
        $this->assertSame('192.0.2.12', $ami->bmcIp);
        $this->assertSame('DHCP Address', $ami->ipSource);
        $this->assertSame(1, $ami->channel);
        $this->assertSame('K14PG-D24 Series', $ami->board);
        $this->assertSame(['chassis', 'board', 'product'], array_keys($ami->serials), 'slugs: labels are Lang::t(ipmi.serial.<slug>)');

        $hp = IpmiParser::info(self::fixture('kvm521', 'mc-info.txt'), self::fixture('kvm521', 'fru-print-0.txt'), self::fixture('kvm521', 'lan-print-2.txt'), 2);
        $this->assertSame('ProLiant DL360 Gen9', $hp->machine());
        $this->assertSame('iLO', $hp->bmcVendor);
        $this->assertSame('2.82', $hp->firmware);
        $this->assertSame('192.0.2.10', $hp->bmcIp);
        $this->assertArrayHasKey('asset', $hp->serials);

        $bare = IpmiParser::info('', '');
        $this->assertSame('', $bare->machine());
        $this->assertSame('', $bare->bmcIp);
    }

    public function testLanAddressSkipsUnconfiguredChannels(): void
    {
        $this->assertSame('192.0.2.12', IpmiParser::lanAddress(self::fixture('skynet2', 'lan-print-1.txt')));
        $this->assertSame('', IpmiParser::lanAddress("IP Address Source : Static Address\nIP Address : 0.0.0.0\n"));
        $this->assertSame('', IpmiParser::lanAddress('Invalid channel 1'));
    }

    public function testVendorFallsBackToTheManufacturerName(): void
    {
        $this->assertSame('iDRAC', IpmiParser::vendor(674, 'DELL Inc'));
        $this->assertSame('Acme', IpmiParser::vendor(99999, 'Acme Computer Inc.'));
        $this->assertSame('', IpmiParser::vendor(null, 'Unknown (0x1234)'));
    }

    public function testPairsKeepsTheFirstKeyAndSkipsContinuations(): void
    {
        $kv = IpmiParser::pairs("Auth Type Enable : Callback : MD5\n                 : User : MD5\nMAC Address : 00:00:5e:00:53:a8\n");
        $this->assertSame(['Auth Type Enable' => 'Callback : MD5', 'MAC Address' => '00:00:5e:00:53:a8'], $kv);
    }
}
