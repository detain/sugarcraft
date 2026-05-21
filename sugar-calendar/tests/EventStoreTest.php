<?php

declare(strict_types=1);

namespace SugarCraft\Calendar\Tests;

use SugarCraft\Calendar\EventStore;
use SugarCraft\Calendar\EventStoreInterface;
use PHPUnit\Framework\TestCase;

final class EventStoreTest extends TestCase
{
    public function testRecordsEvent(): void
    {
        $store = new EventStore();
        $store->record('test', ['key' => 'value']);

        $this->assertTrue($store->hasEvents());
        $this->assertSame(1, $store->count());
    }

    public function testReleaseReturnsEvents(): void
    {
        $store = new EventStore();
        $store->record('event1', ['a' => 1]);
        $store->record('event2', ['b' => 2]);

        $events = $store->release();

        $this->assertCount(2, $events);
        $this->assertSame('event1', $events[0]['type']);
        $this->assertSame(['a' => 1], $events[0]['payload']);
        $this->assertSame('event2', $events[1]['type']);
        $this->assertSame(['b' => 2], $events[1]['payload']);
        $this->assertArrayHasKey('time', $events[0]);
    }

    public function testReleaseClearsEvents(): void
    {
        $store = new EventStore();
        $store->record('test');
        $store->release();

        $this->assertFalse($store->hasEvents());
        $this->assertSame(0, $store->count());
    }

    public function testHasEventsIsFalseWhenEmpty(): void
    {
        $store = new EventStore();
        $this->assertFalse($store->hasEvents());
    }

    public function testCount(): void
    {
        $store = new EventStore();
        $this->assertSame(0, $store->count());

        $store->record('a');
        $this->assertSame(1, $store->count());

        $store->record('b');
        $this->assertSame(2, $store->count());

        $store->release();
        $this->assertSame(0, $store->count());
    }

    public function testImplementsInterface(): void
    {
        $store = new EventStore();
        $this->assertInstanceOf(EventStoreInterface::class, $store);
    }
}
