<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Descriptor;
use SugarCraft\Metrics\Instrument\AsyncCounter;
use SugarCraft\Metrics\Instrument\AsyncGauge;
use SugarCraft\Metrics\Instrument\UpDownCounter;
use SugarCraft\Metrics\Registry;
use PHPUnit\Framework\TestCase;

final class RegistryTest extends TestCase
{
    public function testCounterAccumulates(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->counter('hits');
        $r->counter('hits');
        $r->counter('hits', 3.5);
        $this->assertSame(5.5, $b->counterValue('hits'));
    }

    public function testGaugeReplaces(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->gauge('queue.depth', 4);
        $r->gauge('queue.depth', 7);
        $r->gauge('queue.depth', 2);
        $this->assertSame(2.0, $b->gaugeValue('queue.depth'));
    }

    public function testHistogramAppendsAllSamples(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        foreach ([0.1, 0.2, 0.4] as $v) {
            $r->histogram('latency', $v);
        }
        $this->assertSame([0.1, 0.2, 0.4], $b->histogramValues('latency'));
    }

    public function testTimeRecordsElapsedAsHistogram(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $stop = $r->time('handler');
        usleep(2000);
        $elapsed = $stop();
        $samples = $b->histogramValues('handler');
        $this->assertCount(1, $samples);
        $this->assertGreaterThan(0.001, $samples[0]);
        $this->assertSame($samples[0], $elapsed);
    }

    public function testWithTagsStampsEveryEmit(): void
    {
        $b = new InMemoryBackend();
        $r = (new Registry($b))->withTags(['user' => 'alice', 'env' => 'prod']);
        $r->counter('hits');
        $this->assertSame(1.0, $b->counterValue('hits', ['user' => 'alice', 'env' => 'prod']));
        $this->assertSame(0.0, $b->counterValue('hits'));
    }

    public function testCallSiteTagsMergeOverDefaults(): void
    {
        $b = new InMemoryBackend();
        $r = (new Registry($b))->withTags(['env' => 'prod']);
        $r->counter('hits', 1.0, ['route' => '/x']);
        $this->assertSame(1.0, $b->counterValue('hits', ['env' => 'prod', 'route' => '/x']));
    }

    public function testTagsKeyedByNameAndTagsTuple(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $r->counter('hits', 1.0, ['user' => 'alice']);
        $r->counter('hits', 1.0, ['user' => 'bob']);
        $r->counter('hits', 1.0, ['user' => 'alice']);
        $this->assertSame(2.0, $b->counterValue('hits', ['user' => 'alice']));
        $this->assertSame(1.0, $b->counterValue('hits', ['user' => 'bob']));
    }

    public function testRegisterIsIdempotent(): void
    {
        $r = new Registry(new InMemoryBackend());
        $d1 = new Descriptor('conns', 'Active connections', 'gauge');
        $d2 = new Descriptor('conns', 'Different help text', 'gauge'); // same name, diff help

        $r->register($d1);
        // The second registration with the same name must be a no-op (idempotent).
        // After the fix, $this->descriptors['conns'] is already set, so the guard returns early.
        $r->register($d2);

        // Register a different descriptor to confirm it was stored (not overwritten).
        $r->register(new Descriptor('requests', 'Total requests', 'counter'));

        // Re-reading from registry's private state is not possible directly.
        // Instead, verify idempotency by checking that registering the same name twice
        // does NOT cause describe() to be called twice on the backend.
        // We verify indirectly: after idempotent fix, d1's help is preserved.
        // (d2's different help text is NOT passed to the backend).
        // The InMemoryBackend.describe() is a no-op, so we check registry descriptors directly
        // via reflection to confirm d1's help is intact.
        $ref = new \ReflectionClass(Registry::class);
        $prop = $ref->getProperty('descriptors');
        $prop->setAccessible(true);
        $descriptors = $prop->getValue($r);

        $this->assertArrayHasKey('conns', $descriptors);
        $this->assertSame('Active connections', $descriptors['conns']->help);
        $this->assertArrayHasKey('requests', $descriptors);
    }

    public function testInstrumentFactoriesReturnInstruments(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);

        // newUpDownCounter returns an UpDownCounter whose add() records via the backend.
        $counter = $r->newUpDownCounter('conns', 'Active connections');
        $this->assertInstanceOf(UpDownCounter::class, $counter);
        $counter->add(1);
        $counter->add(-1);
        $this->assertSame(0.0, $b->upDownCounterValue('conns'));

        // newAsyncCounter returns an AsyncCounter that observes via the callback.
        $asyncC = $r->newAsyncCounter('jvm_gc', 'JVM GC count', fn() => 5.0);
        $this->assertInstanceOf(AsyncCounter::class, $asyncC);
        $asyncC->observe();
        $this->assertSame(5.0, $b->asyncCounterValue('jvm_gc'));

        // newAsyncGauge returns an AsyncGauge that observes via the callback.
        $asyncG = $r->newAsyncGauge('heap_used', 'Heap memory used', fn() => 128.5);
        $this->assertInstanceOf(AsyncGauge::class, $asyncG);
        $asyncG->observe();
        $this->assertSame(128.5, $b->asyncGaugeValue('heap_used'));
    }

    public function testBackendAccessorReturnsBackend(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        $this->assertSame($b, $r->backend());
    }

    public function testWithTagsPropagatesCardinalityLimit(): void
    {
        $b = new InMemoryBackend();
        $parent = new Registry($b, [], 1);  // cardinality limit of 1
        $child = $parent->withTags(['x' => '1']);

        // Child inherits the same cardinality limit.
        $child->counter('m', 1.0, ['y' => 'a']);
        $child->counter('m', 1.0, ['y' => 'b']); // exceeds limit of 1

        // Only one unique label combo should survive (FIFO eviction).
        $this->assertLessThanOrEqual(1, $child->cardinality('m'));
    }
}
