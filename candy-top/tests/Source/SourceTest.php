<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Source;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Cpu;
use SugarCraft\Top\Collect\CpuSnapshot;
use SugarCraft\Top\Collect\MemorySnapshot;
use SugarCraft\Top\Collect\NetSnapshot;
use SugarCraft\Top\Collect\Paths;
use SugarCraft\Top\Collect\ProcSnapshot;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeCpu;
use SugarCraft\Top\Source\Fake\FakeMemory;
use SugarCraft\Top\Source\Fake\FakeNet;
use SugarCraft\Top\Source\Fake\FakeProcList;
use SugarCraft\Top\Source\Fake\Wave;
use SugarCraft\Top\Source\Samples;
use SugarCraft\Top\Source\SourceSet;

final class SourceTest extends TestCase
{
    public function testFakesProduceRealSnapshotTypesDeterministically(): void
    {
        [$cpu, $nextCpu] = FakeCpu::new(4)->sample();
        $this->assertInstanceOf(CpuSnapshot::class, $cpu);
        $this->assertCount(4, $cpu->cores);
        $this->assertSame(array_fill_keys(Cpu::FIELDS, true), array_map(static fn (): bool => true, $cpu->fields));
        $this->assertEquals($cpu, FakeCpu::new(4)->sample()[0], 'same step, same numbers');
        $this->assertNotEquals($cpu, $nextCpu->sample()[0], 'next step moves');

        $this->assertInstanceOf(MemorySnapshot::class, FakeMemory::new()->sample()[0]);
        $this->assertInstanceOf(NetSnapshot::class, FakeNet::new()->sample()[0]);
        [$procs] = FakeProcList::new(4)->sample();
        $this->assertInstanceOf(ProcSnapshot::class, $procs);
        $this->assertSame(4, $procs->coreCount);
    }

    public function testFakeValuesStayInRange(): void
    {
        for ($step = 0; $step < 200; $step++) {
            $v = Wave::percent($step, $step * 0.1);
            $this->assertGreaterThanOrEqual(0.0, $v);
            $this->assertLessThanOrEqual(100.0, $v);
        }
        [$mem] = FakeMemory::new(1000)->sample();
        $this->assertSame(1000, $mem->used + $mem->available);
    }

    public function testFakeNetAccumulatesTotals(): void
    {
        [$a, $next] = FakeNet::new(1.0)->sample();
        [$b] = $next->sample();
        $this->assertGreaterThan($a->interfaces['eth0']->rxTotal, $b->interfaces['eth0']->rxTotal);
        $this->assertSame('eth0', $b->selectedName);
    }

    public function testCollectorSourceAdaptsACollector(): void
    {
        $source = CollectorSource::of(Cpu::new(Paths::under(__DIR__ . '/../fixtures/linux')));
        [$snapshot, $next] = $source->sample();
        $this->assertInstanceOf(CpuSnapshot::class, $snapshot);
        $this->assertInstanceOf(CollectorSource::class, $next);
        $this->assertInstanceOf(Cpu::class, $next->collector());
    }

    public function testCollectorSourceRejectsNonCollectors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        CollectorSource::of(new \stdClass());
    }

    public function testSourceSetSamplesEveryMember(): void
    {
        [$samples, $next] = SourceSet::of(['cpu' => FakeCpu::new(2), 'mem' => FakeMemory::new()])->sample();
        $this->assertInstanceOf(Samples::class, $samples);
        $this->assertInstanceOf(CpuSnapshot::class, $samples->get('cpu'));
        $this->assertInstanceOf(MemorySnapshot::class, $samples->get('mem'));
        $this->assertNull($samples->get('net'));
        $this->assertInstanceOf(SourceSet::class, $next);
    }
}
