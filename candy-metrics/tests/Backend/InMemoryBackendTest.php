<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests\Backend;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class InMemoryBackendTest extends TestCase
{
    public function testCounterAccumulates(): void
    {
        $b = new InMemoryBackend();
        $b->counter('hits', 1.0);
        $b->counter('hits', 2.5);
        $b->counter('hits', 0.5);
        $this->assertSame(4.0, $b->counterValue('hits'));
        $this->assertSame(['hits' => 4.0], $b->counters());
    }

    public function testCounterWithTagsAccumulatesPerCombination(): void
    {
        $b = new InMemoryBackend();
        $b->counter('hits', 1.0, ['route' => '/a']);
        $b->counter('hits', 2.0, ['route' => '/b']);
        $b->counter('hits', 1.0, ['route' => '/a']);
        $this->assertSame(2.0, $b->counterValue('hits', ['route' => '/a']));
        $this->assertSame(2.0, $b->counterValue('hits', ['route' => '/b']));
    }

    public function testGaugeLastWriteWins(): void
    {
        $b = new InMemoryBackend();
        $b->gauge('temperature', 72.5);
        $b->gauge('temperature', 73.0);
        $b->gauge('temperature', 71.8);
        $this->assertSame(71.8, $b->gaugeValue('temperature'));
        $this->assertSame(71.8, $b->gauges()['temperature']);
    }

    public function testHistogramKeepsAllSamplesInOrder(): void
    {
        $b = new InMemoryBackend();
        $b->histogram('latency', 0.050);
        $b->histogram('latency', 0.120);
        $b->histogram('latency', 0.085);
        $values = $b->histogramValues('latency');
        $this->assertSame([0.050, 0.120, 0.085], $values);
        $this->assertCount(3, $b->histograms()['latency']);
    }

    public function testUpDownCounterSignedAccumulation(): void
    {
        $b = new InMemoryBackend();
        $b->upDownCounter('conns', 1.0);
        $b->upDownCounter('conns', 1.0);
        $b->upDownCounter('conns', -1.0);
        $this->assertSame(1.0, $b->upDownCounterValue('conns'));
        $this->assertSame(1.0, $b->upDownCounters()['conns']);
    }

    public function testAsyncCounterLastValue(): void
    {
        $b = new InMemoryBackend();
        $b->asyncCounter('pool_size', 10.0);
        $b->asyncCounter('pool_size', 12.0);
        $b->asyncCounter('pool_size', 8.0);
        $this->assertSame(8.0, $b->asyncCounterValue('pool_size'));
        $this->assertSame(8.0, $b->asyncCounters()['pool_size']);
    }

    public function testAsyncGaugeLastValue(): void
    {
        $b = new InMemoryBackend();
        $b->asyncGauge('heap', 256.0);
        $b->asyncGauge('heap', 512.0);
        $this->assertSame(512.0, $b->asyncGaugeValue('heap'));
        $this->assertNull($b->asyncGaugeValue('nonexistent'));
    }

    public function testGaugeReturnsNullWhenAbsent(): void
    {
        $b = new InMemoryBackend();
        $this->assertNull($b->gaugeValue('nonexistent'));
    }

    public function testCounterReturnsZeroWhenAbsent(): void
    {
        $b = new InMemoryBackend();
        $this->assertSame(0.0, $b->counterValue('nonexistent'));
    }

    public function testUpDownCounterReturnsZeroWhenAbsent(): void
    {
        $b = new InMemoryBackend();
        $this->assertSame(0.0, $b->upDownCounterValue('nonexistent'));
    }

    public function testHistogramReturnsEmptyWhenAbsent(): void
    {
        $b = new InMemoryBackend();
        $this->assertSame([], $b->histogramValues('nonexistent'));
    }

    public function testKeyTagSortingEqualBuckets(): void
    {
        $b = new InMemoryBackend();
        // Two different tag orderings for the same logical tags must land in the same bucket.
        $b->counter('hits', 1.0, ['b' => '2', 'a' => '1']);
        $b->counter('hits', 2.0, ['a' => '1', 'b' => '2']);
        $this->assertSame(3.0, $b->counterValue('hits', ['a' => '1', 'b' => '2']));
        $this->assertCount(1, $b->counters(), 'Sorted tag keys must produce exactly one bucket');
    }

    public function testHistogramReservoirIsBoundedUnderFlood(): void
    {
        // Security: a flood of histogram() calls on one key must NOT grow memory
        // without bound. With a cap of 8 and 10 000 samples, the stored reservoir
        // may never exceed 8 entries.
        $b = new InMemoryBackend(maxSamplesPerKey: 8);
        for ($i = 0; $i < 10000; $i++) {
            $b->histogram('lat', (float) $i);
        }
        $this->assertCount(8, $b->histogramValues('lat'));
        $this->assertCount(8, $b->histograms()['lat']);
        // Reservoir stays a proper zero-indexed list after random replacement.
        $this->assertSame(array_values($b->histogramValues('lat')), $b->histogramValues('lat'));
    }

    public function testHistogramKeepsEverythingBelowCap(): void
    {
        // Below the cap, Algorithm R degenerates to append-in-order, so callers
        // that stay under the bound see the exact stream (deterministic).
        $b = new InMemoryBackend(maxSamplesPerKey: 8);
        $b->histogram('lat', 0.1);
        $b->histogram('lat', 0.2);
        $b->histogram('lat', 0.3);
        $this->assertSame([0.1, 0.2, 0.3], $b->histogramValues('lat'));
    }

    public function testReservoirBoundIsPerSeries(): void
    {
        // The cap applies independently to each (name, tags) series.
        $b = new InMemoryBackend(maxSamplesPerKey: 4);
        for ($i = 0; $i < 100; $i++) {
            $b->histogram('lat', (float) $i, ['route' => '/a']);
            $b->histogram('lat', (float) $i, ['route' => '/b']);
        }
        $this->assertCount(4, $b->histogramValues('lat', ['route' => '/a']));
        $this->assertCount(4, $b->histogramValues('lat', ['route' => '/b']));
    }

    public function testRemoveResetsHistogramSeenSoReservoirRefills(): void
    {
        // remove() must clear the internal "seen" counter too, otherwise a
        // re-emitted series would immediately behave as if the reservoir were
        // already full.
        $b = new InMemoryBackend(maxSamplesPerKey: 4);
        for ($i = 0; $i < 100; $i++) {
            $b->histogram('lat', (float) $i);
        }
        $b->remove('lat');
        $this->assertSame([], $b->histogramValues('lat'));
        $b->histogram('lat', 1.0);
        $b->histogram('lat', 2.0);
        // Fresh accumulation in order — proves the seen-counter was reset.
        $this->assertSame([1.0, 2.0], $b->histogramValues('lat'));
    }

    public function testMaxSamplesPerKeyRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InMemoryBackend(maxSamplesPerKey: 0);
    }

    public function testMaxSamplesPerKeyDefaultsToBoundedValue(): void
    {
        // A genuine DoS guard requires a bounded default, not opt-in bounding.
        $b = new InMemoryBackend();
        $this->assertSame(4096, $b->maxSamplesPerKey());
    }

    public function testAllAccessorsReturnExpectedShapes(): void
    {
        $b = new InMemoryBackend();
        $b->counter('c', 1.0);
        $b->gauge('g', 2.0);
        $b->histogram('h', 0.5);
        $b->upDownCounter('ud', 3.0);
        $b->asyncCounter('ac', 4.0);
        $b->asyncGauge('ag', 5.0);

        $this->assertIsArray($b->counters());
        $this->assertIsArray($b->gauges());
        $this->assertIsArray($b->histograms());
        $this->assertIsArray($b->upDownCounters());
        $this->assertIsArray($b->asyncCounters());
        $this->assertIsArray($b->asyncGauges());
    }
}
