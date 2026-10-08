<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Panel\Gpu;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\Gpu\Accelerators;
use SugarCraft\Top\Collect\GpuVendor;
use SugarCraft\Top\Config\Config;
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
}
