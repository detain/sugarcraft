<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu;
use SugarCraft\Top\Collect\Gpu\Accelerators;
use SugarCraft\Top\Collect\GpuOutcome;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Config\Config;
use SugarCraft\Top\Panel\Gpu\GpuDemand;
use SugarCraft\Top\Panel\Gpu\GpuSampling;
use SugarCraft\Top\Source\CollectorSource;
use SugarCraft\Top\Source\Fake\FakeGpu;

/** Collect-time retuning: shown_gpus → vendors, the per-process hook. */
final class GpuSamplingTest extends TestCase
{
    public function testShownGpusBecomesTheVendorFilter(): void
    {
        $this->assertSame(['nvidia', 'amd', 'intel', 'apple'], GpuSampling::vendors(Config::new()));
        $this->assertSame([], GpuSampling::vendors(Config::new()->with('shown_gpus', '  ')));
        $tuned = GpuSampling::tune(CollectorSource::of(Accelerators::nvidiaOnly()), Config::new()->with('shown_gpus', 'amd  intel'));
        $this->assertInstanceOf(CollectorSource::class, $tuned);
        $collector = $tuned->collector();
        $this->assertInstanceOf(Accelerators::class, $collector);
        $vendors = (new \ReflectionProperty($collector, 'vendors'))->getValue($collector);
        $this->assertSame([GpuVendor::Amd, GpuVendor::Intel], $vendors);
    }

    public function testTheProcessHookIsOffUntilTheProcColumnsKeyExists(): void
    {
        $this->assertFalse(GpuSampling::processesWanted(Config::new()), 'no `proc_gpu_columns` option yet');
        $tuned = GpuSampling::tune(CollectorSource::of(Accelerators::nvidiaOnly()), Config::new());
        $this->assertInstanceOf(CollectorSource::class, $tuned);
        $collector = $tuned->collector();
        $this->assertInstanceOf(Accelerators::class, $collector);
        $this->assertFalse($collector->processesEnabled());
        // An already-on collector is switched OFF only on a change; never re-armed every tick.
        $on = GpuSampling::tune(CollectorSource::of(Accelerators::nvidiaOnly()->withProcesses()), Config::new());
        $this->assertInstanceOf(CollectorSource::class, $on);
        $this->assertFalse($on->collector()->processesEnabled());
    }

    public function testOtherSourcesPassThrough(): void
    {
        $fake = FakeGpu::new();
        $this->assertSame($fake, GpuSampling::tune($fake, Config::new()));
        $this->assertNull(GpuSampling::tune(null, Config::new()));
        $other = CollectorSource::of(\SugarCraft\Top\Collect\Cpu::new());
        $this->assertSame($other, GpuSampling::tune($other, Config::new()));
    }

    public function testTuneForFlipsPerProcessCollectionOnlyOnAChange(): void
    {
        $fake = FakeGpu::new();
        $on = GpuSampling::tuneFor($fake, GpuDemand::new(processes: true));
        $this->assertInstanceOf(FakeGpu::class, $on);
        $this->assertTrue($on->processesEnabled());
        $this->assertSame($on, GpuSampling::tuneFor($on, GpuDemand::new(processes: true)), 'no change, same source');
        $this->assertFalse(GpuSampling::tuneFor($on, GpuDemand::new())->processesEnabled());

        $nvidia = CollectorSource::of(Gpu::new(static fn (array $a): array => [GpuOutcome::Absent, ''], candidates: ['nvidia-smi']));
        $tuned = GpuSampling::withProcesses($nvidia, true);
        $this->assertInstanceOf(CollectorSource::class, $tuned);
        $this->assertTrue($tuned->collector()->processesEnabled(), 'a bare nvidia-smi collector too');
        $this->assertSame($tuned, GpuSampling::withProcesses($tuned, true));

        $acc = GpuSampling::tuneFor(CollectorSource::of(Accelerators::nvidiaOnly()), GpuDemand::new(['amd'], true));
        $this->assertInstanceOf(CollectorSource::class, $acc);
        $this->assertTrue($acc->collector()->processesEnabled());
        $this->assertSame([GpuVendor::Amd], (new \ReflectionProperty($acc->collector(), 'vendors'))->getValue($acc->collector()));
    }
}
