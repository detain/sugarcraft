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
        // id=1 tracking was removed so it no longer contributes to cardinality
        // id=2,3,4 retained in cardinality tracker
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '2']));
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '3']));
        $this->assertSame(1.0, $b->counterValue('items', ['id' => '4']));
        // Re-adding id=1 re-tracks it but immediately triggers another eviction (id=2)
        // Final state: id=3, id=4, id=1 tracked; cardinality stays at 3
        $r->counter('items', 1.0, ['id' => '1']);
        $this->assertSame(3, $r->cardinality('items'));
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
