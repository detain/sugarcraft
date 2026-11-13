<?php

declare(strict_types=1);

namespace SugarCraft\GitServer\Tests\Clipboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\GitServer\Clipboard\Osc52;

/**
 * @covers \SugarCraft\GitServer\Clipboard\Osc52
 */
final class Osc52Test extends TestCase
{
    private Osc52 $clipboard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clipboard = new Osc52();
    }

    // -------------------------------------------------------------------------
    // parse tests
    // -------------------------------------------------------------------------

    public function testParseWithValidWritePayload(): void
    {
        // Format: selection;base64payload
        $result = $this->clipboard->parse('c;SGVsbG8gV29ybGQ=');

        $this->assertSame('write', $result['kind']);
        $this->assertSame('c', $result['selection']);
        $this->assertSame('SGVsbG8gV29ybGQ=', $result['payload']);
    }

    public function testParseWithReadRequest(): void
    {
        $result = $this->clipboard->parse('c;?');

        $this->assertSame('read', $result['kind']);
        $this->assertSame('c', $result['selection']);
        $this->assertArrayNotHasKey('payload', $result);
    }

    public function testParseWithPrimarySelection(): void
    {
        $result = $this->clipboard->parse('p;dGVzdA==');

        $this->assertSame('write', $result['kind']);
        $this->assertSame('p', $result['selection']);
    }

    public function testParseWithSecondarySelection(): void
    {
        $result = $this->clipboard->parse('s;dGVzdA==');

        $this->assertSame('write', $result['kind']);
        $this->assertSame('s', $result['selection']);
    }

    public function testParseReturnsNullForMissingSemicolon(): void
    {
        $result = $this->clipboard->parse('c');

        $this->assertNull($result);
    }

    public function testParseReturnsNullForInvalidSelection(): void
    {
        $result = $this->clipboard->parse('x;dGVzdA==');

        $this->assertNull($result);
    }

    public function testParseReturnsNullForEmptySelection(): void
    {
        $result = $this->clipboard->parse(';dGVzdA==');

        $this->assertNull($result);
    }

    public function testParseAddsEventToPendingEvents(): void
    {
        $this->clipboard->parse('c;SGVsbG8=');

        $events = $this->clipboard->pendingEvents();

        $this->assertCount(1, $events);
        $this->assertSame('write', $events[0]['kind']);
    }

    // -------------------------------------------------------------------------
    // write tests
    // -------------------------------------------------------------------------

    public function testWriteStoresClipboardData(): void
    {
        $this->clipboard->write('c', 'Hello World');

        $this->assertTrue($this->clipboard->has('c'));
        $this->assertSame('Hello World', $this->clipboard->read('c'));
    }

    public function testWriteAddsWriteEvent(): void
    {
        $this->clipboard->write('c', 'Hello');

        $events = $this->clipboard->pendingEvents();

        $this->assertCount(1, $events);
        $this->assertSame('write', $events[0]['kind']);
        $this->assertSame('c', $events[0]['selection']);
    }

    public function testWriteIgnoresInvalidSelection(): void
    {
        $this->clipboard->write('x', 'data');

        $this->assertFalse($this->clipboard->has('x'));
        $this->assertNull($this->clipboard->read('x'));
    }

    public function testWriteOverwritesExistingData(): void
    {
        $this->clipboard->write('c', 'First');
        $this->clipboard->write('c', 'Second');

        $this->assertSame('Second', $this->clipboard->read('c'));
    }

    public function testWriteNotifiesListeners(): void
    {
        $received = [];
        $this->clipboard->onChange(function ($event) use (&$received) {
            $received[] = $event;
        });

        $this->clipboard->write('c', 'Hello');

        $this->assertCount(1, $received);
        $this->assertSame('write', $received[0]['kind']);
        $this->assertSame('c', $received[0]['selection']);
    }

    // -------------------------------------------------------------------------
    // read tests
    // -------------------------------------------------------------------------

    public function testReadReturnsStoredData(): void
    {
        $this->clipboard->write('c', 'Stored Data');

        $result = $this->clipboard->read('c');

        $this->assertSame('Stored Data', $result);
    }

    public function testReadReturnsNullForEmptySelection(): void
    {
        $result = $this->clipboard->read('c');

        $this->assertNull($result);
    }

    public function testReadMultipleSelections(): void
    {
        $this->clipboard->write('c', 'Clipboard');
        $this->clipboard->write('p', 'Primary');

        $this->assertSame('Clipboard', $this->clipboard->read('c'));
        $this->assertSame('Primary', $this->clipboard->read('p'));
    }

    // -------------------------------------------------------------------------
    // has tests
    // -------------------------------------------------------------------------

    public function testHasReturnsTrueForStoredData(): void
    {
        $this->clipboard->write('c', 'data');

        $this->assertTrue($this->clipboard->has('c'));
    }

    public function testHasReturnsFalseForEmptySelection(): void
    {
        $this->assertFalse($this->clipboard->has('c'));
    }

    public function testHasReturnsFalseForClearedSelection(): void
    {
        $this->clipboard->write('c', 'data');
        $this->clipboard->clear('c');

        $this->assertFalse($this->clipboard->has('c'));
    }

    // -------------------------------------------------------------------------
    // clear tests
    // -------------------------------------------------------------------------

    public function testClearRemovesSelection(): void
    {
        $this->clipboard->write('c', 'data');
        $this->clipboard->clear('c');

        $this->assertFalse($this->clipboard->has('c'));
        $this->assertNull($this->clipboard->read('c'));
    }

    public function testClearAddsClearEvent(): void
    {
        $this->clipboard->write('c', 'data');
        $this->clipboard->clear('c');

        $events = $this->clipboard->pendingEvents();

        // One for write, one for clear
        $this->assertCount(2, $events);
        $this->assertSame('clear', $events[1]['kind']);
    }

    public function testClearIgnoresInvalidSelection(): void
    {
        $this->clipboard->clear('x'); // Should not throw

        $this->assertFalse($this->clipboard->has('x'));
    }

    public function testClearNotifiesListeners(): void
    {
        $received = [];
        $this->clipboard->onChange(function ($event) use (&$received) {
            $received[] = $event;
        });

        $this->clipboard->clear('c');

        $this->assertCount(1, $received);
        $this->assertSame('clear', $received[0]['kind']);
    }

    // -------------------------------------------------------------------------
    // pendingEvents tests
    // -------------------------------------------------------------------------

    public function testPendingEventsReturnsAndClearsEvents(): void
    {
        $this->clipboard->write('c', 'data');
        $this->clipboard->parse('c;dGVzdA==');

        $first = $this->clipboard->pendingEvents();
        $second = $this->clipboard->pendingEvents();

        $this->assertCount(2, $first);
        $this->assertCount(0, $second);
    }

    public function testPendingEventsEmptyInitially(): void
    {
        $events = $this->clipboard->pendingEvents();

        $this->assertCount(0, $events);
    }

    // -------------------------------------------------------------------------
    // buildReadResponse tests
    // -------------------------------------------------------------------------

    public function testBuildReadResponseEncodesBase64(): void
    {
        $response = $this->clipboard->buildReadResponse('c', 'Hello');

        $this->assertStringContainsString('52;c;', $response);
        $this->assertStringContainsString(\base64_encode('Hello'), $response);
    }

    public function testBuildReadResponseWithDifferentSelections(): void
    {
        $clipResp = $this->clipboard->buildReadResponse('c', 'data');
        $primaryResp = $this->clipboard->buildReadResponse('p', 'data');

        $this->assertStringContainsString(';c;', $clipResp);
        $this->assertStringContainsString(';p;', $primaryResp);
    }

    // -------------------------------------------------------------------------
    // validSelections tests
    // -------------------------------------------------------------------------

    public function testValidSelectionsReturnsExpectedValues(): void
    {
        $selections = Osc52::validSelections();

        $this->assertContains('c', $selections);
        $this->assertContains('p', $selections);
        $this->assertContains('s', $selections);
        $this->assertCount(3, $selections);
    }

    // -------------------------------------------------------------------------
    // Event buffer cap tests
    // -------------------------------------------------------------------------

    public function testEventBufferIsCappedAndEvictsOldest(): void
    {
        // 1050 events with a 1000-event cap: the first 50 must be evicted
        for ($i = 0; $i < 1050; $i++) {
            $this->clipboard->parse("c;{$i}");
        }

        $events = $this->clipboard->pendingEvents();

        $this->assertCount(1000, $events);
        $this->assertSame('50', $events[0]['payload']);
        $this->assertSame('1049', $events[999]['payload']);
    }

    // -------------------------------------------------------------------------
    // S36-era re-aim + S37 (finding 104): the BYTE budget, ring eviction
    // -------------------------------------------------------------------------

    public function testByteBudgetEvictsLongPayloadsBeforeTheCountCap(): void
    {
        // 104: 1000 SHORT-event-shaped caps do not bound memory when each
        // payload is attacker-chosen length. Two ~200 KiB writes are past
        // the 256 KiB budget while being only 2 of 1000 allowed events —
        // the old shape held both; the budget now drops the oldest.
        $big = str_repeat('A', 200_000);
        $this->clipboard->write('c', $big);
        $this->clipboard->write('c', $big . 'x');

        $events = $this->clipboard->pendingEvents();

        $this->assertCount(1, $events, 'the byte budget must evict the older giant before count ever matters');
        $this->assertSame(200_001, strlen((string) $events[0]['payload']), 'the NEWEST tail survives');

        // A payload past the WHOLE budget evicts itself, not the backlog.
        $this->clipboard->write('c', str_repeat('C', 300_000));
        $this->clipboard->write('c', 'small-after-giant');
        $events = $this->clipboard->pendingEvents();
        $this->assertCount(1, $events);
        $this->assertSame('small-after-giant', $events[0]['payload'], 'the self-evicted giant leaves the next event intact');
    }

    public function testRingCursorAndBudgetResetCleanlyOnDrain(): void
    {
        // Cycle: overflow, drain, refill, overflow again. A stale head
        // cursor or byte counter across the drain would either refuse to
        // evict (the old buffer's ghost bytes) or evict the NEWEST event
        // (a head that runs past the fresh list).
        $big = str_repeat('B', 200_000);
        $this->clipboard->write('c', $big);
        $this->clipboard->write('c', $big);
        $this->clipboard->pendingEvents(); // drain: bytes 0, head reset

        $this->clipboard->write('c', $big);          // 200k — fits
        $this->clipboard->write('c', $big . 'x');    // 400k — evicts the first

        $events = $this->clipboard->pendingEvents();
        $this->assertCount(1, $events, 'the second overflow must still evict the oldest');
        $this->assertSame(200_001, strlen((string) $events[0]['payload']), 'and keep the newest');
        $this->assertSame([], $this->clipboard->pendingEvents(), 'drain empties');
    }

    public function testCountCapSurvivesUnderTheByteBudget(): void
    {
        // Thousands of tiny events stay within the byte budget, so the
        // secondary count cap does the bounding there (re-proves the
        // re-aimed while-condition without weakening the old pin).
        for ($i = 0; $i < 1005; $i++) {
            $this->clipboard->parse('c;a');
        }

        $this->assertCount(1000, $this->clipboard->pendingEvents());
    }

    public function testOffChangeDetachesTheExactListener(): void
    {
        $kept = [];
        $gone = [];
        $keptListener = static function (array $event) use (&$kept): void {
            $kept[] = $event['kind'];
        };
        $goneListener = static function (array $event) use (&$gone): void {
            $gone[] = $event['kind'];
        };

        $this->clipboard->onChange($keptListener);
        $this->clipboard->onChange($goneListener);

        $this->assertTrue($this->clipboard->offChange($goneListener));
        $this->clipboard->write('c', 'payload');

        $this->assertSame(['write'], $kept, 'the sibling listener still fires');
        $this->assertSame([], $gone, 'the removed listener never fires again (104)');
        $this->assertFalse($this->clipboard->offChange($goneListener), 'removing twice reports honestly');
    }

    public function testMidDispatchRemovalDoesNotAlterTheCurrentNotification(): void
    {
        $fired = [];
        $clipboard = $this->clipboard;
        $late = static function (array $event) use (&$fired): void {
            $fired[] = 'late';
        };
        $first = static function (array $event) use (&$fired, $clipboard, $late): void {
            $fired[] = 'first';
            $clipboard->offChange($late); // detach the sibling mid-dispatch
        };

        $this->clipboard->onChange($first);
        $this->clipboard->onChange($late);
        $this->clipboard->write('c', 'one');

        $this->assertSame(['first', 'late'], $fired, 'the in-flight dispatch is a snapshot — removals land NEXT time');

        $fired = [];
        $this->clipboard->write('c', 'two');
        $this->assertSame(['first'], $fired, 'the detached listener is gone from the next dispatch');
    }
}
