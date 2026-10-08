<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Proc;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\GpuProcess;
use SugarCraft\Top\Collect\GpuSnapshot;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Panel\Proc\GpuUsage;

/**
 * The #1552 per-pid join and its hold law: a cycle without per-process
 * rows keeps every value, a measured cycle replaces the map, an
 * UNMEASURED reading keeps that pid's previous value.
 */
final class GpuUsageTest extends TestCase
{
    /** @param list<GpuProcess>|null $processes */
    private static function snap(?array $processes): GpuSnapshot
    {
        return new GpuSnapshot([], $processes);
    }

    public function testNoneIsUnmeasuredAndEmpty(): void
    {
        $u = GpuUsage::none();
        $this->assertFalse($u->measured());
        $this->assertSame(0.0, $u->utilization(1));
        $this->assertSame(0, $u->memory(1));
        $this->assertSame([], $u->pids());
    }

    public function testSumsAcrossGpusAndClampsUtilizationTo100(): void
    {
        $u = GpuUsage::none()->withSnapshot(self::snap([
            new GpuProcess(10, 0, 'a', 1000, 70.0),
            new GpuProcess(10, 1, 'b', 500, 60.0),
            new GpuProcess(11, 1, 'b', 64, 12.5),
        ]));
        $this->assertTrue($u->measured());
        $this->assertSame(100.0, $u->utilization(10), 'the PR clamps gpu_p after summing');
        $this->assertSame(1500, $u->memory(10));
        $this->assertSame(12.5, $u->utilization(11));
        $this->assertSame([10, 11], $u->pids());
    }

    public function testAnUnmeasuredCycleHoldsEveryValue(): void
    {
        $u = GpuUsage::none()->withSnapshot(self::snap([new GpuProcess(10, 0, 'a', 2048, 40.0)]));
        $held = $u->withSnapshot(self::snap(null));
        $this->assertSame($u, $held);
        $this->assertSame(40.0, $held->utilization(10));
        $this->assertSame(2048, $held->memory(10));
    }

    public function testAMeasuredCycleDropsPidsItNoLongerLists(): void
    {
        $u = GpuUsage::none()
            ->withSnapshot(self::snap([new GpuProcess(10, 0, 'a', 2048, 40.0)]))
            ->withSnapshot(self::snap([new GpuProcess(11, 0, 'a', 64, 1.0)]));
        $this->assertSame(0.0, $u->utilization(10));
        $this->assertSame(0, $u->memory(10));
        $this->assertSame(1.0, $u->utilization(11));
    }

    public function testUnmeasuredReadingsKeepThePidsPreviousValueElseZero(): void
    {
        $u = GpuUsage::none()->withSnapshot(self::snap([
            new GpuProcess(10, 0, 'a', 2048, 40.0),
        ]))->withSnapshot(self::snap([
            new GpuProcess(10, 0, 'a', Sentinel::UNMEASURED_INT, Sentinel::UNMEASURED),
            new GpuProcess(12, 0, 'a', 4096, Sentinel::UNMEASURED),
        ]));
        $this->assertSame(40.0, $u->utilization(10), 'pmon missed it this cycle (#1008)');
        $this->assertSame(2048, $u->memory(10), '[N/A] memory keeps the last value');
        $this->assertSame(0.0, $u->utilization(12), 'compute-apps without pmon: 0 until measured');
        $this->assertSame(4096, $u->memory(12));
    }

    public function testAnEmptyMeasuredListStillMarksTheHostMeasured(): void
    {
        $u = GpuUsage::none()->withSnapshot(self::snap([]));
        $this->assertTrue($u->measured(), 'per-process data exists, nothing is using the GPU');
        $this->assertSame([], $u->pids());
    }

    public function testClearedDropsValuesButKeepsMeasured(): void
    {
        $u = GpuUsage::none()->withSnapshot(self::snap([new GpuProcess(10, 0, 'a', 2048, 40.0)]));
        $this->assertTrue($u->fresh());
        $c = $u->cleared();
        $this->assertTrue($c->measured());
        $this->assertFalse($c->fresh(), 'cleared values are not fit to filter on');
        $this->assertFalse($c->withSnapshot(self::snap(null))->fresh(), 'an unmeasured cycle does not refresh them');
        $this->assertTrue($c->withSnapshot(self::snap([]))->fresh());
        $this->assertFalse(GpuUsage::none()->fresh());
        $this->assertSame(0.0, $c->utilization(10));
        $this->assertSame($c, $c->cleared());
        $this->assertSame(GpuUsage::none()->measured(), GpuUsage::none()->cleared()->measured());
    }
}
