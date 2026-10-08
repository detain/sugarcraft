<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Cpu;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\Support\FixtureTree;

final class CpuTest extends TestCase
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

    public function testFirstSampleIsSinceBootAverageLikeBtop(): void
    {
        [$snap] = Cpu::new($this->tree->paths())->sample();

        // totals 10000, idles 8000+500 → 15% busy since boot.
        $this->assertSame(15.0, $snap->total);
        $this->assertSame([15.0, 15.0], $snap->cores);
        $this->assertSame(2, $snap->coreCount());
        $this->assertSame([0.52, 0.58, 0.59], $snap->load);
        $this->assertSame(1000.0, $snap->uptime);
        $this->assertSame(80.0, $snap->fields['idle']);
        $this->assertSame(5.0, $snap->fields['iowait']);
    }

    public function testDeltaMathCountsIowaitIdleStealBusyAndExcludesGuest(): void
    {
        [, $cpu] = Cpu::new($this->tree->paths())->sample();

        // Kernel-consistent: the aggregate row is the sum of the core rows,
        // and guest time is already INSIDE user (cpu1: user +50 of which
        // guest +50 — a vCPU run), which is why totals must drop guest.
        //   cpu0 Δ user 300, system 50, idle 100, iowait 50
        //   cpu1 Δ user 50 (guest 50), system 50, idle 300, iowait 50, steal 100
        $this->tree->write('proc/stat', <<<STAT
            cpu  1350 0 600 8400 600 0 0 100 50 0
            cpu0 800 0 300 4100 300 0 0 0 0 0
            cpu1 550 0 300 4300 300 0 0 100 50 0
            STAT);
        [$snap, $next] = $cpu->sample();

        // Aggregate Δtotals 1050 (guest excluded), Δidles 500 → 550/1050.
        $this->assertEqualsWithDelta(52.381, $snap->total, 0.001);
        $this->assertEqualsWithDelta(33.333, $snap->fields['user'], 0.001);
        $this->assertEqualsWithDelta(9.524, $snap->fields['steal'], 0.001, 'steal is busy, not idle');
        $this->assertEqualsWithDelta(9.524, $snap->fields['iowait'], 0.001);
        $this->assertEqualsWithDelta(4.762, $snap->fields['guest'], 0.001);
        // cpu0: Δ500, idle+iowait Δ150 → 70; cpu1: Δ550, Δidles 350 → 36.36…
        $this->assertSame(70.0, $snap->cores[0]);
        $this->assertEqualsWithDelta(36.364, $snap->cores[1], 0.001);
        $this->assertNotSame($cpu, $next);
    }

    public function testSameJiffiesTwiceIsUnmeasuredNotBtopSpike(): void
    {
        [, $cpu] = Cpu::new($this->tree->paths())->sample();
        [$snap] = $cpu->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->total);
        $this->assertSame([Sentinel::UNMEASURED, Sentinel::UNMEASURED], $snap->cores);
    }

    public function testMissingCoreRowLeavesUnmeasuredHole(): void
    {
        $this->tree->write('proc/stat', "cpu  10 0 0 90 0 0 0 0 0 0\ncpu0 5 0 0 45 0 0 0 0 0 0\ncpu2 5 0 0 45 0 0 0 0 0 0\n");
        [$snap] = Cpu::new($this->tree->paths())->sample();

        $this->assertSame([10.0, Sentinel::UNMEASURED, 10.0], $snap->cores);
    }

    public function testMissingFilesDegradeToSentinelsAndKeepBaseline(): void
    {
        $cpu = Cpu::new($this->tree->paths());
        $this->tree->remove('proc/stat');
        $this->tree->remove('proc/loadavg');
        $this->tree->remove('proc/uptime');
        [$snap, $next] = $cpu->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->total);
        $this->assertSame([], $snap->cores);
        $this->assertSame([Sentinel::UNMEASURED, Sentinel::UNMEASURED, Sentinel::UNMEASURED], $snap->load);
        $this->assertSame(Sentinel::UNMEASURED, $snap->uptime);
        $this->assertSame($cpu, $next);
    }

    public function testTruncatedLineIsSkipped(): void
    {
        $this->tree->write('proc/stat', "cpu  1 2\ncpu0 10 0 0 90\n");
        [$snap] = Cpu::new($this->tree->paths())->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->total);
        $this->assertSame([10.0], $snap->cores);
    }
}
