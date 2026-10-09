<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Ipmi;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Top\Collect\Ipmi\IpmiPower;
use SugarCraft\Top\Collect\Ipmi\IpmiReader;
use SugarCraft\Top\Collect\Ipmi\IpmiSensor;
use SugarCraft\Top\Collect\Ipmi\IpmiSnapshot;
use SugarCraft\Top\Collect\Ipmi\IpmiState;
use SugarCraft\Top\Collect\Ipmi\SensorKind;
use SugarCraft\Top\Collect\Ipmi\SensorStatus;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Msg\SampledMsg;
use SugarCraft\Top\Panel\Ipmi\IpmiData;
use SugarCraft\Top\Panel\Ipmi\IpmiNames;
use SugarCraft\Top\Panel\Ipmi\IpmiView;
use SugarCraft\Top\Panel\IpmiPanel;
use SugarCraft\Top\Source\Fake\FakeIpmi;
use SugarCraft\Top\Source\Fake\FakeIpmiCaptures;

/**
 * The ipmi panel's data path: collect() rounds (settled, pending, busy),
 * merging partial rounds and failures (#1008), the held history, and the
 * pure helpers the view leans on.
 */
final class IpmiPanelTest extends TestCase
{
    private static function context(int $w = 120, int $h = 40): \SugarCraft\Top\Panel\PanelContext
    {
        return IpmiPaintKit::context(Config::new(), $w, $h);
    }

    public function testNoReaderNoSample(): void
    {
        $this->assertNull(IpmiPanel::new(null)->collect(self::context()));
        $this->assertSame('ipmi', IpmiPanel::new(null)->box());
    }

    public function testASettledRoundAnswersAtOnce(): void
    {
        $panel = IpmiPanel::new(FakeIpmi::demo());
        $msg = ($panel->collect(self::context()))();
        $this->assertInstanceOf(SampledMsg::class, $msg);
        $this->assertSame('ipmi', $msg->box);
        $next = $panel->update($msg, self::context())->panel;
        $this->assertInstanceOf(IpmiPanel::class, $next);
        $this->assertSame(IpmiState::Ready, $next->data()->state);
        $this->assertSame('ESC8000A-E12', $next->data()->info?->machine());
        $this->assertSame(IpmiState::Starting, $panel->data()->state, 'immutable');
    }

    public function testAPendingRoundIsAnAsyncCmdAndABusyReaderIsSkipped(): void
    {
        $deferred = new Deferred();
        $reader = new class ($deferred) implements IpmiReader {
            public int $polls = 0;

            public function __construct(private readonly Deferred $d)
            {
            }

            public function poll(int $slowMs): ?PromiseInterface
            {
                return ++$this->polls === 1 ? $this->d->promise() : null;
            }

            public function sample(): array
            {
                return [IpmiSnapshot::starting(), $this];
            }
        };
        $panel = IpmiPanel::new($reader);
        $this->assertInstanceOf(AsyncCmd::class, ($panel->collect(self::context()))());
        $this->assertNull(($panel->collect(self::context()))(), 'in flight: no second child, no message');
    }

    public function testTheSlowCadenceComesFromTheConfigWhenTheSchemaHasIt(): void
    {
        $config = Config::new();
        $this->assertSame($config->has('ipmi_update_ms') ? $config->int('ipmi_update_ms') : IpmiPanel::SLOW_MS, IpmiPanel::slowMs($config));
        $this->assertSame(IpmiPanel::SLOW_MS, IpmiPanel::slowMs($config), 'the default cadence');
        $this->assertFalse(IpmiPanel::showSerials(Config::new()), 'serials stay hidden by default');
    }

    public function testKeysAreNeverClaimed(): void
    {
        $panel = IpmiPanel::new(FakeIpmi::demo());
        $this->assertFalse($panel->capturesKey(new KeyMsg(KeyType::Char, 'i'), self::context()));
        $this->assertFalse($panel->modal(self::context()));
        $this->assertSame($panel, $panel->update(new KeyMsg(KeyType::Char, 'i'), self::context())->panel);
    }

    public function testPartialRoundsKeepWhatTheyDidNotRead(): void
    {
        $full = FakeIpmi::fromCaptures(FakeIpmiCaptures::skynet2())->sample()[0];
        $d = IpmiData::new()->merged($full);
        $this->assertTrue($d->sensorsRead);
        $this->assertCount(14, $d->readings->temps);

        $powerOnly = new IpmiSnapshot(IpmiState::Ready, null, new IpmiPower(900, 800, 1000, 850, 5), null, null, null, '', 1);
        $d2 = $d->merged($powerOnly);
        $this->assertSame(900.0, $d2->watts());
        $this->assertCount(14, $d2->readings->temps, 'sensors held');
        $this->assertSame('ESC8000A-E12', $d2->info?->machine(), 'header held');
        $this->assertSame([2064.0, 900.0], $d2->watts, 'one sample per round');

        $failed = $d2->merged(IpmiSnapshot::failed(IpmiState::TimedOut, '', 2));
        $this->assertSame(IpmiState::TimedOut, $failed->state);
        $this->assertSame(900.0, $failed->watts(), 'data held through a failure');
        $this->assertTrue($failed->any());

        $back = $failed->merged(new IpmiSnapshot(IpmiState::Ready, null, null, null, null, null, '', 3));
        $this->assertSame(IpmiState::Ready, $back->state);
        $this->assertSame($d2, $d2->merged(IpmiSnapshot::starting()));
    }

    public function testTheHistoryIsCapped(): void
    {
        $d = IpmiData::new();
        for ($i = 0; $i < 10; $i++) {
            $d = $d->merged(new IpmiSnapshot(IpmiState::Ready, null, new IpmiPower(100 + $i, 0, 0, 0), null, null, null, '', $i), 4);
        }
        $this->assertSame([106.0, 107.0, 108.0, 109.0], $d->watts);
    }

    public function testWithoutDcmiThePowerComesFromTheSuppliesOrAMeter(): void
    {
        $in = new IpmiSensor('PSU1 Power In', 400.0, 'Watts', SensorKind::Power, SensorStatus::Ok);
        $d = IpmiData::new()->merged(new IpmiSnapshot(IpmiState::Ready, null, null, [$in], null, null, '', 0));
        $this->assertSame(400.0, $d->watts());
        $this->assertSame([400.0, 400.0, 400.0], $d->window(), 'min/avg/max from the box\'s own history');

        $meter = new IpmiSensor('Power Meter', 220.0, 'Watts', SensorKind::Power, SensorStatus::Ok);
        $this->assertSame(220.0, IpmiData::new()->merged(new IpmiSnapshot(IpmiState::Ready, null, null, [$meter], null, null, '', 0))->watts());
        $this->assertNull(IpmiData::new()->watts());
        $this->assertNull(IpmiData::new()->window());
    }

    public function testScales(): void
    {
        $this->assertSame(1.0, IpmiData::nice(0.0));
        $this->assertSame(250.0, IpmiData::nice(221.0));
        $this->assertSame(2500.0, IpmiData::nice(2064.0));
        $this->assertSame(10000.0, IpmiData::nice(9000.0));
        $hp = IpmiData::new()->merged(FakeIpmi::fromCaptures(IpmiPaintKit::captures(\dirname(__DIR__, 2) . '/fixtures/ipmi/kvm521'))->sample()[0]);
        $this->assertSame(600.0, $hp->scale(), 'no PSU rating: the window max (487 W) rounded up');
        $this->assertSame(100.0, $hp->fanScale($hp->readings->fans[0]), 'duty');
        $ami = IpmiData::new()->merged(FakeIpmi::fromCaptures(FakeIpmiCaptures::skynet2())->sample()[0]);
        $this->assertSame(10368.0, $ami->scale(), 'four rated supplies');
        $this->assertSame(20000.0, $ami->fanScale($ami->readings->fans[0]), 'fastest fan seen (17.9k) rounded up');
    }

    public function testTemperatureSeriesFollowTheSensors(): void
    {
        $fake = FakeIpmi::demo();
        $d = IpmiData::new();
        for ($i = 0; $i < 3; $i++) {
            $d = $d->merged($fake->sample()[0]);
        }
        $this->assertCount(3, $d->series['CPU1 Temperature'] ?? []);
        $this->assertArrayNotHasKey('CPU2 Temperature', $d->series, 'no reading, no series');
    }

    public function testNames(): void
    {
        $s = static fn (string $n, ?float $ucr = null, ?float $lnc = null, ?float $unc = null): IpmiSensor => new IpmiSensor($n, 1.0, 'degrees C', SensorKind::Temperature, SensorStatus::Ok, lnc: $lnc, unc: $unc, ucr: $ucr);
        $this->assertSame('Inlet Ambient', IpmiNames::label($s('01-Inlet Ambient')));
        $this->assertSame('CPU1', IpmiNames::label($s('CPU1 Temperature')));
        $this->assertSame('CPU1 DIMMA1', IpmiNames::label($s('CPU1_DIMMA1_Temp')));
        $this->assertSame('CPU', IpmiNames::label($s('CPU_Power')));
        $this->assertTrue(IpmiNames::inlet($s('Inlet Temp')));
        $this->assertFalse(IpmiNames::inlet($s('11-PS 1 Inlet')), 'a PSU inlet is not intake air');
        $this->assertSame(42.0, IpmiNames::tempLimit($s('01-Inlet Ambient', 42.0)));
        $this->assertSame(45.0, IpmiNames::tempLimit($s('Inlet Temp')));
        $this->assertSame(85.0, IpmiNames::tempLimit($s('DIMM 3')));
        $this->assertSame(95.0, IpmiNames::tempLimit($s('CPU2')));
        $this->assertSame(110.0, IpmiNames::tempLimit($s('VR P1')));
        $this->assertSame(90.0, IpmiNames::tempLimit($s('Chipset')));
        $this->assertSame(12.0, IpmiNames::nominal($s('+12V', null, 10.8, 13.2)));
        $this->assertSame(3.3, IpmiNames::nominal($s('+3.3VSB')));
        $this->assertSame(3.0, IpmiNames::nominal($s('VBAT')));
        $this->assertNull(IpmiNames::nominal($s('+VCORE0_CPU1')));
        $this->assertNull(IpmiNames::nominal($s('+5V', null, 5.5, 6.0)), 'outside its thresholds');
    }

    public function testUniqueLabelsKeepTheNumberOnlyWhereStrippingCollides(): void
    {
        $t = static fn (string $n): IpmiSensor => new IpmiSensor($n, 30.0, 'degrees C', SensorKind::Temperature, SensorStatus::Ok);
        $labels = IpmiNames::unique([$t('13-VR P1'), $t('15-VR P1 Mem'), $t('16-VR P1 Mem'), $t('X'), $t('X')]);
        $this->assertSame(['13-VR P1' => 'VR P1', '15-VR P1 Mem' => '15-VR P1 Mem', '16-VR P1 Mem' => '16-VR P1 Mem', 'X' => 'X #2'], $labels);
        $this->assertSame('15-VR P1 Mem', IpmiNames::label($t('15-VR P1 Mem'), false));

        $hp = IpmiData::new()->merged(FakeIpmi::fromCaptures(IpmiPaintKit::captures(\dirname(__DIR__, 2) . '/fixtures/ipmi/kvm521'))->sample()[0]);
        $labels = array_map($hp->label(...), $hp->readings->temps);
        $this->assertSame(\count($labels), \count(array_unique($labels)), 'every HP temperature label is distinct');
    }

    public function testTheDemoWindowAlwaysHoldsItsReading(): void
    {
        $fake = FakeIpmi::demo();
        for ($i = 0; $i < 60; $i++) {
            $p = $fake->sample()[0]->power;
            $this->assertNotNull($p);
            $this->assertLessThanOrEqual($p->watts, $p->min);
            $this->assertGreaterThanOrEqual($p->watts, $p->max);
            $this->assertTrue($p->min <= $p->avg && $p->avg <= $p->max);
        }
    }

    public function testPickTempsPutsIntakeFirstAndHottestRelativeFirstWhenShort(): void
    {
        $t = static fn (string $n, float $v, float $ucr): IpmiSensor => new IpmiSensor($n, $v, 'degrees C', SensorKind::Temperature, SensorStatus::Ok, ucr: $ucr);
        $all = [$t('CPU', 60, 100), $t('Inlet', 20, 40), $t('DIMM', 80, 85), $t('VR', 50, 120)];
        [$shown, $hidden] = IpmiView::pickTemps($all, 10);
        $this->assertSame(['Inlet', 'CPU', 'DIMM', 'VR'], array_map(static fn ($s) => $s->name, $shown));
        $this->assertSame(0, $hidden);
        [$shown, $hidden] = IpmiView::pickTemps($all, 3);
        $this->assertSame(['Inlet', 'DIMM', 'CPU'], array_map(static fn ($s) => $s->name, $shown));
        $this->assertSame(1, $hidden);
    }

    public function testFakeIpmiIsDeterministicAndCanFail(): void
    {
        $a = FakeIpmi::demo();
        $b = FakeIpmi::demo();
        for ($i = 0; $i < 3; $i++) {
            $this->assertEquals($a->sample()[0], $b->sample()[0]);
        }
        $c = FakeIpmi::demo();
        $this->assertNotSame($c->sample()[0]->power?->watts, $c->sample()[0]->power?->watts, 'the demo moves round by round');
        $fail = FakeIpmi::failing(IpmiState::NoAccess);
        $s = $fail->sample()[0];
        $this->assertSame(IpmiState::NoAccess, $s->state);
        $this->assertSame('/dev/ipmi0', $s->device);
        $this->assertNotNull($fail->poll(1));
    }
}
