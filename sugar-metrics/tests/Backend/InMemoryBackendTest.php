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

    public function testLookalikeTagSetsDoNotCollapseIntoOneAccumulator(): void
    {
        // Storage-key collision from the audit: 'x=a|y=b' was reachable
        // both from the single tag ['x' => 'a|y=b'] and the pair
        // ['x' => 'a', 'y' => 'b'], so one series silently accumulated
        // into the other. Separators are escaped now; they must not mix.
        $b = new InMemoryBackend();
        $b->counter('m', 1.0, ['x' => 'a|y=b']);
        $b->counter('m', 2.0, ['x' => 'a', 'y' => 'b']);
        $this->assertSame(1.0, $b->counterValue('m', ['x' => 'a|y=b']));
        $this->assertSame(2.0, $b->counterValue('m', ['x' => 'a', 'y' => 'b']));
    }

    public function testNameContainingPipeEqualsFoldCannotImpersonateTagsSeries(): void
    {
        // 013 (HIGH): the name-side twin of the lookalike family — the raw
        // name 'x|y=1' used to BE the storage key the tagged 'x' with
        // ['y' => '1'] folds to, so two distinct series merged into one
        // accumulator (contaminated values; a remove of one wiped the
        // other). The name segment now rides the same escaper, and both
        // series store, read, and reclaim independently.
        $b = new InMemoryBackend();
        $b->counter('x|y=1', 1.0);
        $b->counter('x', 2.0, ['y' => '1']);
        $this->assertSame(1.0, $b->counterValue('x|y=1'));
        $this->assertSame(2.0, $b->counterValue('x', ['y' => '1']));
        $b->remove('x|y=1');
        $this->assertSame(0.0, $b->counterValue('x|y=1'));
        $this->assertSame(2.0, $b->counterValue('x', ['y' => '1']), 'an innocent twin across the fold must survive the reclaim');
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

    public function testPostCapReservoirDrawStaysInBoundsAndKeepsReplacing(): void
    {
        // 046 (MET-RAND): the post-cap Algorithm R draw moved random_int →
        // mt_rand. Same inclusive [0, seen-1] range, identical uniform quality
        // — the bounds are what the swap must not break. Two ways the range
        // can rot: an upper bound that excludes replaceable slots (never
        // replaces — the reservoir fossilizes the first cap samples), or a
        // negative draw (a stray key corrupts the zero-indexed list).
        // mt_srand keeps the check deterministic.
        mt_srand(42);
        $b = new InMemoryBackend(maxSamplesPerKey: 8);
        for ($i = 0; $i < 100; $i++) {
            $b->histogram('lat', (float) $i);
        }
        $values = $b->histogramValues('lat');
        $this->assertCount(8, $values);
        $this->assertSame(array_values($values), $values);
        $late = array_filter($values, static fn (float $v): bool => $v >= 8.0);
        $this->assertNotEmpty($late, 'post-cap draws must keep replacing slots: flood values land in the reservoir');
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

    public function testNameTokenMemoRidesTheColdFoldAndDropsOnClear(): void
    {
        // sugar-metrics-015: the key() name segment memoizes the pure
        // per-name fold — adversarial names must still land on the same
        // escaped token the cold sweep produces (the MEMORYFOLD pins are
        // the byte contract; this proves the memo holds exactly that fold
        // and clear() returns the cache to zero).
        $b = new InMemoryBackend();
        $b->gauge('x', 1.0, ['k' => 'v']);
        $b->gauge('x', 2.0, ['k' => 'v']); // memo-hot
        $b->gauge('x|y=1', 3.0);
        self::assertSame(2.0, $b->gaugeValue('x', ['k' => 'v']));
        self::assertSame(3.0, $b->gaugeValue('x|y=1'));
        $ref = new \ReflectionProperty(InMemoryBackend::class, 'nameTokens');
        self::assertSame(['x' => 'x', 'x|y=1' => 'x\|y\=1'], $ref->getValue($b));
        $b->clear();
        self::assertSame([], $ref->getValue($b));
    }
    public function testSnapshotDecodesAdversarialStorageKeysFaithfully(): void
    {
        // sugar-metrics-022 fold-proof read surface: the consumer-side twin
        // of the 013 guard — a raw name carrying separators and a tag fold
        // of the same bytes must return as TWO rows with faithful names and
        // tags, exactly what hand-splitting `name|k=v` on every '|' gets
        // wrong.
        $b = new InMemoryBackend();
        $b->counter('x|y=1', 5.0);
        $b->counter('x', 3.0, ['y' => '1']);
        $b->gauge("a\\b\0c", 1.0, ['we=ird' => 'va|lue\\x']);
        $rows = $b->snapshot();
        $this->assertCount(3, $rows);
        $this->assertSame(
            [['x|y=1', []], ['x', ['y' => '1']], ["a\\b\0c", ['we=ird' => 'va|lue\\x']]],
            [[$rows[0]['name'], $rows[0]['tags']], [$rows[1]['name'], $rows[1]['tags']], [$rows[2]['name'], $rows[2]['tags']]],
            'decoded name+tags must be byte-identical to what was emitted'
        );
        $this->assertSame('counter', $rows[0]['kind']);
        $this->assertSame(5.0, $rows[0]['value']);
        $this->assertSame('gauge', $rows[2]['kind']);
    }

    public function testSnapshotCoversAllSixKindsWithHistogramValuesAsSums(): void
    {
        $b = new InMemoryBackend();
        $b->counter('c', 1.0);
        $b->gauge('g', 2.0);
        $b->histogram('h', 0.1, ['q' => 'a']);
        $b->histogram('h', 0.2, ['q' => 'a']);
        $b->upDownCounter('u', 3.0);
        $b->asyncCounter('ac', 4.0);
        $b->asyncGauge('ag', 5.0);
        $rows = $b->snapshot();
        $this->assertCount(6, $rows);
        $byKind = [];
        foreach ($rows as $row) {
            $byKind[$row['kind']] = $row;
        }
        $this->assertSame(['counter', 'gauge', 'histogram', 'upDownCounter', 'asyncCounter', 'asyncGauge'], array_keys($byKind));
        $this->assertSame('h', $byKind['histogram']['name']);
        $this->assertSame(['q' => 'a'], $byKind['histogram']['tags']);
        $this->assertEqualsWithDelta(0.3, $byKind['histogram']['value'], 1e-12, 'the histogram row value is the reservoir sum');
        $this->assertSame(2.0, $byKind['gauge']['value']);
    }

    public function testHistogramSummaryReportsReservoirAndObservedSeparately(): void
    {
        $b = new InMemoryBackend(2);
        $b->histogram('lat', 1.0);
        $b->histogram('lat', 2.0);
        $summary = $b->histogramSummary('lat');
        $this->assertIsArray($summary);
        $this->assertSame(2, $summary['count']);
        $this->assertSame(2, $summary['observed']);
        $this->assertSame(3.0, $summary['sum']);
        $this->assertSame(1.5, $summary['mean']);
        $b->histogram('lat', 30.0); // reservoir full: one replacement draw may or may not land
        $after = $b->histogramSummary('lat');
        $this->assertSame(2, $after['count'], 'the reservoir stays capped');
        $this->assertSame(3, $after['observed'], 'observed counts the whole stream, not the reservoir');
        $this->assertNull($b->histogramSummary('absent'));
        $b->remove('lat');
        $this->assertNull($b->histogramSummary('lat'), 'removed series summarize as null');
    }

    public function testSnapshotIsSweptByClearAndRemove(): void
    {
        $b = new InMemoryBackend();
        $b->counter('one', 1.0);
        $b->gauge('two', 2.0, ['t' => 'v']);
        $this->assertCount(2, $b->snapshot());
        $b->remove('one');
        $this->assertCount(1, $b->snapshot());
        $b->clear();
        $this->assertSame([], $b->snapshot());
    }

}
