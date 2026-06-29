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
