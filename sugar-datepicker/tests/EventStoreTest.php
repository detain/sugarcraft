<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker\Tests;

use SugarCraft\DatePicker\EventStore;
use SugarCraft\DatePicker\PickerEvent;
use SugarCraft\DatePicker\EventStoreInterface;
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

    public function testInjectedClockStampsRecordedEvents(): void
    {
        // docs-fix-1023 E2: deterministic timestamps for tests/replay.
        $store = new EventStore(static fn(): int => 1_762_000_000);
        $store->record(PickerEvent::DATE_SELECTED, ['date' => '2026-05-01']);

        $events = $store->release();
        $this->assertCount(1, $events);
        $this->assertSame(1_762_000_000, $events[0]['time']);
    }

    public function testDefaultClockUsesWallTime(): void
    {
        $store = new EventStore();
        $before = time();
        $store->record(PickerEvent::CURSOR_MOVED, ['index' => 3]);
        $events = $store->release();

        $this->assertGreaterThanOrEqual($before, $events[0]['time']);
        $this->assertLessThanOrEqual(time(), $events[0]['time']);
    }

    /**
     * dp-20: the PickerEvent constant VALUES are published wire vocabulary.
     * The literals here are spelled independently of the class on purpose —
     * renaming a const value without a deliberate wire-change ruling reddens
     * this pin (and hosts' stored transcripts) loudly.
     */
    public function testEventConstantValuesAreStableWireContract(): void
    {
        // Independent literals (dp-20 law): renaming a wire VALUE reddens
        // exactly this pin. Roster = all eight kinds (dp-09 adds four).
        $this->assertSame('cursor_moved', PickerEvent::CURSOR_MOVED);
        $this->assertSame('range_started', PickerEvent::RANGE_STARTED);
        $this->assertSame('range_restarted', PickerEvent::RANGE_RESTARTED);
        $this->assertSame('range_cleared', PickerEvent::RANGE_CLEARED);
        $this->assertSame('date_selected', PickerEvent::DATE_SELECTED);
        $this->assertSame('date_cleared', PickerEvent::DATE_CLEARED);
        $this->assertSame('month_changed', PickerEvent::MONTH_CHANGED);
        $this->assertSame('selection_refused', PickerEvent::SELECTION_REFUSED);
    }

    public function testAllEightWireKindsRoundTripThroughTheStore(): void
    {
        $store = new EventStore();
        $store->record(PickerEvent::CURSOR_MOVED, ['index' => 2, 'date' => '2026-05-03']);
        $store->record(PickerEvent::RANGE_STARTED, ['date' => '2026-05-02']);
        $store->record(PickerEvent::RANGE_RESTARTED, ['from' => '2026-05-02', 'to' => '2026-05-09']);
        $store->record(PickerEvent::RANGE_CLEARED, ['from' => '2026-05-09']);
        $store->record(PickerEvent::DATE_SELECTED, ['date' => '2026-05-09']);
        $store->record(PickerEvent::DATE_CLEARED, []);
        $store->record(PickerEvent::MONTH_CHANGED, ['year' => 2026, 'month' => 6]);
        $store->record(PickerEvent::SELECTION_REFUSED, ['date' => '2026-05-11', 'reason' => 'out_of_bounds']);

        $types = array_map(static fn(array $e): string => $e['type'], $store->release());
        $this->assertSame(
            [
                PickerEvent::CURSOR_MOVED,
                PickerEvent::RANGE_STARTED,
                PickerEvent::RANGE_RESTARTED,
                PickerEvent::RANGE_CLEARED,
                PickerEvent::DATE_SELECTED,
                PickerEvent::DATE_CLEARED,
                PickerEvent::MONTH_CHANGED,
                PickerEvent::SELECTION_REFUSED,
            ],
            $types,
        );
    }
}
