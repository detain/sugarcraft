<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\GpuDevice;
use SugarCraft\Top\Panel\Gpu\GpuHold;

/** The bounded #1008 hold for accelerators. */
final class GpuHoldTest extends TestCase
{
    private static function live(float $util = 40.0): GpuDevice
    {
        return new GpuDevice(0, 'RTX', $util, 1024, 4096, 60.0, 50.0, powerLimit: 100.0, uuid: 'GPU-1');
    }

    public function testLimitIsThirtySecondsOfSamplesButAtLeastFive(): void
    {
        $this->assertSame(15, GpuHold::limit(2000));
        $this->assertSame(300, GpuHold::limit(100));
        $this->assertSame(5, GpuHold::limit(10000));
        $this->assertSame(5, GpuHold::limit(86_400_000));
        $this->assertSame(30000, GpuHold::limit(0), 'a zero period cannot divide by zero');
    }

    public function testOneNaColumnHoldsWithoutLimit(): void
    {
        [$hold] = GpuHold::new()->apply([self::live()], 2);
        $partial = new GpuDevice(0, 'RTX', -1.0, 1024, 4096, 61.0, 50.0, uuid: 'GPU-1');
        for ($i = 0; $i < 10; $i++) {
            [$hold, $expired] = $hold->apply([$partial], 2);
            $this->assertSame([], $expired);
        }
        $this->assertSame(40.0, $hold->devices()[0]->utilization, 'a device that answers something holds every column');
        $this->assertSame(61.0, $hold->devices()[0]->temp);
    }

    public function testASilentStandInExpiresAfterTheLimit(): void
    {
        [$hold] = GpuHold::new()->apply([self::live()], 2);
        $standIn = self::live()->unmeasured();
        [$hold, $expired] = $hold->apply([$standIn], 2);
        [$hold, $expired2] = $hold->apply([$standIn], 2);
        $this->assertSame([[], []], [$expired, $expired2]);
        $this->assertSame(40.0, $hold->devices()[0]->utilization);
        [$hold, $expired] = $hold->apply([$standIn], 2);
        $this->assertSame([0], $expired);
        $this->assertSame(-1.0, $hold->devices()[0]->utilization);
        $this->assertSame('RTX', $hold->devices()[0]->name);
        // It comes back: measured again, held again.
        [$hold, $expired] = $hold->apply([self::live(10.0)], 2);
        $this->assertSame([[], 10.0], [$expired, $hold->devices()[0]->utilization]);
    }

    public function testAnEmptySnapshotHoldsThenTurnsEveryDeviceNa(): void
    {
        [$hold, $expired] = GpuHold::new()->apply([], 1);
        $this->assertSame([[], []], [$expired, $hold->devices()]);
        [$hold] = $hold->apply([self::live(), self::live(20.0)], 1);
        [$hold, $expired] = $hold->apply([], 1);
        $this->assertSame([], $expired);
        $this->assertSame(40.0, $hold->devices()[0]->utilization);
        [$hold, $expired] = $hold->apply([], 1);
        $this->assertSame([0, 1], $expired);
        $this->assertSame([-1.0, -1.0], [$hold->devices()[0]->utilization, $hold->devices()[1]->utilization]);
        $this->assertSame(4096, $hold->devices()[0]->memTotal, 'stand-ins keep identity and total');
    }

    public function testMeasured(): void
    {
        $this->assertTrue(GpuHold::measured(self::live()));
        $this->assertFalse(GpuHold::measured(self::live()->unmeasured()));
        $this->assertTrue(GpuHold::measured(new GpuDevice(0, 'x', -1.0, -1, -1, -1.0, -1.0, fanSpeed: 30.0)));
    }
}
