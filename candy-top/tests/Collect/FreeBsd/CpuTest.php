<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Collect\FreeBsd\Cpu;
use SugarCraft\Top\Collect\Sentinel;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

final class CpuTest extends TestCase
{
    private function probe(): FixtureProbe
    {
        return new FixtureProbe(
            [FixtureProbe::fixture('sysctl-reference.txt'), FixtureProbe::fixture('sysctl-cp_times-next.synthetic.txt')],
            epoch: 1790798684.7257 + 3600.0,
        );
    }

    public function testFirstSampleIsTheSinceBootAverageFromTheReferenceHost(): void
    {
        $probe = $this->probe();
        [$snap] = Cpu::new($probe)->sample();

        $this->assertInstanceOf(CpuSnapshot::class, $snap);
        $this->assertSame(4, $snap->coreCount(), 'kern.cp_times carries 5 states per core');
        // core0: user 1840157 nice 0 sys 909396 intr 149224 idle 82708621
        $this->assertEqualsWithDelta(100.0 * (1840157 + 909396 + 149224) / (1840157 + 909396 + 149224 + 82708621), $snap->cores[0], 1e-9);
        $this->assertSame([0.32, 0.33, 0.25], $snap->load);
        $this->assertEqualsWithDelta(3600.0, $snap->uptime, 1e-6);
        $this->assertSame(['user', 'nice', 'system', 'irq', 'idle'], array_keys($snap->fields));
        $this->assertSame([['hw.ncpu', 'kern.boottime', 'kern.cp_times', 'vm.loadavg']], $probe->sysctls, 'one batched sysctl per sample');
    }

    public function testDeltasPerCoreAndAggregateIncludingInterruptTime(): void
    {
        $probe = $this->probe();
        [, $cpu] = Cpu::new($probe)->sample();
        $probe->advance();
        [$snap] = $cpu->sample();

        // per-core deltas of 100 ticks: core0 50u/20s/10i/20idle, core1+2 all idle, core3 80u/20s.
        $this->assertEqualsWithDelta([80.0, 0.0, 0.0, 100.0], $snap->cores, 1e-9);
        $this->assertEqualsWithDelta(45.0, $snap->total, 1e-9);
        $this->assertEqualsWithDelta(32.5, $snap->fields['user'], 1e-9);
        $this->assertEqualsWithDelta(10.0, $snap->fields['system'], 1e-9);
        $this->assertEqualsWithDelta(2.5, $snap->fields['irq'], 1e-9, 'intr stays in totals (top(1)), unlike btop');
        $this->assertEqualsWithDelta(55.0, $snap->fields['idle'], 1e-9);
        $this->assertSame([0.40, 0.35, 0.26], $snap->load);
        $this->assertSame(['kern.cp_times', 'vm.loadavg'], $probe->sysctls[1], 'hw.ncpu and kern.boottime are static: read once');
        $this->assertEqualsWithDelta(3600.0, $snap->uptime, 1e-6, 'the cached boottime still gives uptime');
    }

    public function testCpTimesBeyondNcpuAreDropped(): void
    {
        // kern.cp_times is sized by mp_maxid + 1: a 2-cpu host can publish a 4-cpu vector.
        $text = "hw.ncpu=2\nkern.cp_times=10 0 0 0 90 0 0 0 0 100 0 0 0 0 0 0 0 0 0 0\n";
        [$snap] = Cpu::new(new FixtureProbe($text))->sample();

        $this->assertSame(2, $snap->coreCount());
        $this->assertEqualsWithDelta([10.0, 0.0], $snap->cores, 1e-9);
        $this->assertEqualsWithDelta(5.0, $snap->total, 1e-9);
    }

    public function testSameTicksTwiceIsUnmeasuredNotASpike(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'));
        [, $cpu] = Cpu::new($probe)->sample();
        [$snap] = $cpu->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->total);
        $this->assertSame(Sentinel::UNMEASURED, $snap->cores[0]);
    }

    public function testMissingSysctlIsUnmeasuredAndKeepsTheBaseline(): void
    {
        $cpu = Cpu::new(new FixtureProbe(''));
        [$snap, $next] = $cpu->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->total);
        $this->assertSame([], $snap->cores);
        $this->assertSame([Sentinel::UNMEASURED, Sentinel::UNMEASURED, Sentinel::UNMEASURED], $snap->load);
        $this->assertSame(Sentinel::UNMEASURED, $snap->uptime);
        $this->assertInstanceOf(Cpu::class, $next);
        [, $again] = $next->sample();
        $this->assertInstanceOf(Cpu::class, $again, 'nothing was cached from the failed read: it is asked again');
    }

    public function testMalformedCpTimesIsUnmeasured(): void
    {
        [$snap] = Cpu::new(new FixtureProbe("kern.cp_times=1 2 3 4\n"))->sample();

        $this->assertSame(Sentinel::UNMEASURED, $snap->total);
    }
}
