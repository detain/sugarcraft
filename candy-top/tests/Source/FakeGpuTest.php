<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Source;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\AcceleratorKind;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Source\Fake\FakeGpu;

/** --fake accelerators: deterministic, btop-box-complete GPUs plus an Intel NPU. */
final class FakeGpuTest extends TestCase
{
    public function testDeterministicGpusThenNpus(): void
    {
        $fake = FakeGpu::new();
        $this->assertSame(3, $fake->count());
        [$a, $next] = $fake->sample();
        [$b] = FakeGpu::new()->sample();
        $this->assertEquals($a, $b, 'same step, same snapshot');
        $this->assertInstanceOf(GpuSnapshot::class, $a);
        $this->assertCount(2, $a->devices);
        $this->assertCount(1, $a->npus);
        $this->assertSame([0, 1], array_map(static fn ($d): int => $d->index, $a->devices));
        [$c] = $next->sample();
        $this->assertNotEquals($a, $c, 'the wave moves');

        $gpu = $a->devices[0];
        foreach ([$gpu->utilization, $gpu->temp, $gpu->watts, $gpu->memUtilization, $gpu->clockGraphics, $gpu->clockMem, $gpu->encoderUtilization, $gpu->decoderUtilization] as $v) {
            $this->assertGreaterThanOrEqual(0.0, $v, 'every btop gpu box column answers');
        }
        $this->assertGreaterThan(0, $gpu->memTotal);
        $this->assertLessThanOrEqual($gpu->memTotal, $gpu->memUsed);
        $this->assertMatchesRegularExpression('/^P\d$/', $gpu->pstate);

        $npu = $a->npus[0];
        $this->assertSame([AcceleratorKind::Npu, GpuVendor::Intel, 'intel_vpu'], [$npu->kind, $npu->vendor, $npu->driver]);
        $this->assertSame([-1, -1.0, -1.0], [$npu->memTotal, $npu->temp, $npu->watts], '#985 reads busy %, memory used and frequency only');
    }

    public function testCountsAreClamped(): void
    {
        $this->assertSame(0, FakeGpu::new(-1, -2)->count());
        [$snap] = FakeGpu::new(4, 0)->sample();
        $this->assertCount(4, $snap->devices);
        $this->assertSame([], $snap->npus);
    }

    public function testPerProcessRowsAreOptInAndSitOnTheseGpus(): void
    {
        $fake = FakeGpu::new();
        $this->assertFalse($fake->processesEnabled());
        [$off] = $fake->sample();
        $this->assertNull($off->processes, 'off by default, like the live collector');

        $on = $fake->withProcesses();
        $this->assertTrue($on->processesEnabled());
        [$snap, $next] = $on->sample();
        $this->assertEquals($off->devices, $snap->devices, 'the devices do not change');
        $uuids = array_map(static fn ($d): string => $d->uuid, $snap->devices);
        $this->assertEquals(\SugarCraft\Top\Source\Fake\FakeGpuProcesses::rows(0, $uuids), $snap->processes, 'the demo pids');
        $this->assertTrue($next->processesEnabled(), 'kept across samples');
        $this->assertSame([5133, 6969, 880], array_map(static fn ($p): int => $p->pid, FakeGpu::new(1, 0)->withProcesses()->sample()[0]->processes), 'rows on a missing GPU are dropped');
    }
}
