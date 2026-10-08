<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Battery;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class BatteryTest extends TestCase
{
    private FixtureTree $tree;
    private string $bat = 'sys/class/power_supply/BAT0/';

    protected function setUp(): void
    {
        $this->tree = FixtureTree::copy();
    }

    protected function tearDown(): void
    {
        $this->tree->destroy();
    }

    public function testDischargingEnergyBasedTimeAndPower(): void
    {
        $battery = Battery::new($this->tree->paths());
        [$snap, $next] = $battery->sample();

        $this->assertTrue($snap->present());
        $this->assertSame('BAT0', $snap->name);
        $this->assertSame(75, $snap->percent);
        $this->assertSame('discharging', $snap->status);
        $this->assertSame(3 * 3600, $snap->seconds, '30 Wh / 10 W');
        $this->assertSame(10.0, $snap->watts);
        $this->assertSame($battery, $next);
    }

    public function testPercentFallsBackToEnergyThenCharge(): void
    {
        $this->tree->remove($this->bat . 'capacity');
        [$snap] = Battery::new($this->tree->paths())->sample();
        $this->assertSame(75, $snap->percent);

        foreach (['energy_now', 'energy_full', 'power_now'] as $f) {
            $this->tree->remove($this->bat . $f);
        }
        $this->tree->write($this->bat . 'charge_now', "2000000\n");
        $this->tree->write($this->bat . 'charge_full', "4000000\n");
        $this->tree->write($this->bat . 'current_now', "1000000\n");
        $this->tree->write($this->bat . 'voltage_now', "12000000\n");
        [$snap] = Battery::new($this->tree->paths())->sample();

        $this->assertSame(50, $snap->percent);
        $this->assertSame(2 * 3600, $snap->seconds, '2 Ah / 1 A');
        $this->assertSame(12.0, $snap->watts, '1 A × 12 V');
    }

    public function testChargingTimeToFull(): void
    {
        $this->tree->write($this->bat . 'status', "Charging\n");
        [$snap] = Battery::new($this->tree->paths())->sample();

        $this->assertSame('charging', $snap->status);
        $this->assertSame(3600, $snap->seconds, '(40 − 30) Wh / 10 W');
    }

    public function testTimeToFullNeverNegative(): void
    {
        // Worn cells report energy_now above energy_full while topping off.
        $this->tree->write($this->bat . 'status', "Charging\n");
        $this->tree->write($this->bat . 'energy_now', "41000000\n");
        [$snap] = Battery::new($this->tree->paths())->sample();

        $this->assertSame(0, $snap->seconds);
    }

    public function testUnknownStatusResolvedFromOnlineFlags(): void
    {
        $this->tree->write($this->bat . 'status', "Unknown\n");
        [$snap] = Battery::new($this->tree->paths())->sample();
        $this->assertSame('discharging', $snap->status, 'sibling Mains offline');

        $this->tree->write('sys/class/power_supply/AC/online', "1\n");
        [$snap] = Battery::new($this->tree->paths())->sample();
        $this->assertSame('charging', $snap->status);

        $this->tree->write($this->bat . 'capacity', "100\n");
        $this->tree->write($this->bat . 'AC0/online', "1\n");
        [$snap] = Battery::new($this->tree->paths())->sample();
        $this->assertSame('full', $snap->status);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->seconds);
    }

    public function testTimeToEmptyMinutesFallback(): void
    {
        $this->tree->remove($this->bat . 'power_now');
        $this->tree->write($this->bat . 'time_to_empty', "90\n");
        [$snap] = Battery::new($this->tree->paths())->sample();

        $this->assertSame(5400, $snap->seconds);
        $this->assertSame(Sentinel::UNMEASURED, $snap->watts);
    }

    public function testNotPresentOrNotBatteryIsAbsent(): void
    {
        $this->tree->write($this->bat . 'present', "0\n");
        [$snap] = Battery::new($this->tree->paths())->sample();

        $this->assertFalse($snap->present());
        $this->assertSame(Sentinel::UNAVAILABLE, $snap->status);
        $this->assertSame(Sentinel::UNMEASURED_INT, $snap->seconds);
    }

    public function testSelectionPrefersBatteryTypeAndHonoursOverride(): void
    {
        $ups = 'sys/class/power_supply/AUPS/';
        foreach (['type' => 'UPS', 'present' => '1', 'capacity' => '40', 'status' => 'Full'] as $f => $v) {
            $this->tree->write($ups . $f, $v . "\n");
        }
        [$auto] = Battery::new($this->tree->paths())->sample();
        $this->assertSame('BAT0', $auto->name);

        [$picked] = Battery::new($this->tree->paths(), 'AUPS')->sample();
        $this->assertSame('AUPS', $picked->name);
        $this->assertSame(40, $picked->percent);
    }

    public function testNoPowerSupplyDirectory(): void
    {
        $this->tree->remove('sys/class/power_supply');
        [$snap] = Battery::new($this->tree->paths())->sample();

        $this->assertFalse($snap->present());
    }
}
