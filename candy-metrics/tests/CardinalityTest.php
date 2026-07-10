<?php

declare(strict_types=1);

namespace SugarCraft\Metrics\Tests;

use SugarCraft\Metrics\Backend\InMemoryBackend;
use SugarCraft\Metrics\Registry;
use PHPUnit\Framework\TestCase;

final class CardinalityTest extends TestCase
{
    public function testCardinalityStartsAtZero(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 100);
        $r->counter('visits');
        $this->assertSame(1, $r->cardinality('visits'));
    }

    public function testCardinalityIncrementsPerUniqueTagCombo(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 100);
        $r->counter('visits', 1.0, ['user' => 'alice']);
        $r->counter('visits', 1.0, ['user' => 'bob']);
        $r->counter('visits', 1.0, ['user' => 'carol']);
        $this->assertSame(3, $r->cardinality('visits'));
    }

    public function testSameTagsDoNotIncreaseCardinality(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 100);
        $r->counter('hits', 1.0, ['route' => '/x']);
        $r->counter('hits', 1.0, ['route' => '/x']);
        $r->counter('hits', 1.0, ['route' => '/x']);
        $this->assertSame(1, $r->cardinality('hits'));
    }

    public function testEvictsOldestWhenLimitExceeded(): void
    {
        $b = new InMemoryBackend();
        // limit of 3 triggers eviction on 4th unique combo
        $r = new Registry($b, [], 3);
        $r->counter('items', 1.0, ['id' => '1']);
        $r->counter('items', 1.0, ['id' => '2']);
        $r->counter('items', 1.0, ['id' => '3']);
        $this->assertSame(3, $r->cardinality('items'));
        // 4th combo evicts the oldest (id=1) from cardinality tracker
        $r->counter('items', 1.0, ['id' => '4']);
        $this->assertSame(3, $r->cardinality('items'));
        // The evicted series (id=1) must ALSO be gone from the backend —
        // otherwise a cardinality flood leaks series into unbounded backend
        // memory (DoS). counterValue returns 0.0 once the series is removed.
        $this->assertSame(0.0, $b->counterValue('items', ['id' => '1']));
        // id=2,3,4 retained in both cardinality tracker and backend
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '2']));
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '3']));
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '4']));
        // Re-adding id=1 re-tracks it but immediately triggers another eviction (id=2)
        // Final state: id=3, id=4, id=1 tracked; cardinality stays at 3
        $r->counter('items', 1.0, ['id' => '1']);
        $this->assertSame(3, $r->cardinality('items'));
        // id=2 is now the evicted series and must be gone from the backend too
        $this->assertSame(0.0, $b->counterValue('items', ['id' => '2']));
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '1']));
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '3']));
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '4']));
    }

    public function testCardinalityFloodDoesNotLeakBackendSeries(): void
    {
        // Regression for the DoS: flooding a metric with unique tag combos
        // must not grow the backend past the cardinality limit. With limit 10
        // and 100 unique combos, only the most-recent 10 series may survive in
        // the backend; the 90 evicted ones must read back as 0.0.
        $b = new InMemoryBackend();
        $limit = 10;
        $r = new Registry($b, [], $limit);
        for ($i = 0; $i < 100; $i++) {
            $r->counter('flood', 1.0, ['id' => (string) $i]);
        }
        $this->assertSame($limit, $r->cardinality('flood'));

        // Count how many series the backend still holds for this metric by
        // probing every combo we emitted — no more than $limit may be live.
        $live = 0;
        for ($i = 0; $i < 100; $i++) {
            if ($b->counterValue('flood', ['id' => (string) $i]) !== 0.0) {
                $live++;
            }
        }
        $this->assertSame($limit, $live);

        // The survivors must be exactly the most-recent $limit combos (90..99);
        // everything older must have been reclaimed from the backend.
        for ($i = 0; $i < 90; $i++) {
            $this->assertSame(0.0, $b->counterValue('flood', ['id' => (string) $i]));
        }
        for ($i = 90; $i < 100; $i++) {
            $this->assertSame(1.0, $b->counterValue('flood', ['id' => (string) $i]));
        }
    }

    public function testDeleteLabelValuesRemovesTrackingForSpecificCombo(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 100);
        $r->counter('events', 1.0, ['type' => 'click']);
        $r->counter('events', 1.0, ['type' => 'scroll']);
        $this->assertSame(2, $r->cardinality('events'));
        $r->deleteLabelValues('events', ['type' => 'click']);
        $this->assertSame(1, $r->cardinality('events'));
        // Backend data is untouched — cardinality tracking is independent of storage
        $this->assertSame(1.0, $b->counterValue('events', ['type' => 'click']));
        $this->assertSame(1.0, $b->counterValue('events', ['type' => 'scroll']));
    }

    public function testDefaultCardinalityLimitIs10000(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b);
        for ($i = 0; $i < 10001; $i++) {
            $r->counter('m', 1.0, ['k' => (string) $i]);
        }
        // Should have evicted one entry
        $this->assertSame(10000, $r->cardinality('m'));
    }

    public function testCardinalityPerMetricIsIndependent(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 3);
        $r->counter('a', 1.0, ['x' => '1']);
        $r->counter('a', 1.0, ['x' => '2']);
        $r->counter('b', 1.0, ['y' => '1']);
        $r->counter('b', 1.0, ['y' => '2']);
        $r->counter('b', 1.0, ['y' => '3']);
        // 'a' at 2, 'b' at 3 — independent
        $this->assertSame(2, $r->cardinality('a'));
        $this->assertSame(3, $r->cardinality('b'));
    }

    public function testUpDownCounterTracksCardinality(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 100);
        $r->upDownCounter('conn', 1.0, ['host' => 'a']);
        $r->upDownCounter('conn', 1.0, ['host' => 'b']);
        $this->assertSame(2, $r->cardinality('conn'));
    }

    public function testAsyncCounterTracksCardinality(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 100);
        $r->asyncCounter('gc', 1.0, ['gen' => 'gen0']);
        $r->asyncCounter('gc', 1.0, ['gen' => 'gen1']);
        $this->assertSame(2, $r->cardinality('gc'));
    }

    public function testAsyncGaugeTracksCardinality(): void
    {
        $b = new InMemoryBackend();
        $r = new Registry($b, [], 100);
        $r->asyncGauge('mem', 512.0, ['zone' => 'heap']);
        $r->asyncGauge('mem', 256.0, ['zone' => 'stack']);
        $this->assertSame(2, $r->cardinality('mem'));
    }
}
