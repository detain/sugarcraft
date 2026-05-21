<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Tests\Clipboard;

use PHPUnit\Framework\TestCase;
use SugarCraft\Serve\Clipboard\Osc52;

/**
 * @covers \SugarCraft\Serve\Clipboard\Osc52
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
        $this->clipboard->clear('x');  // Should not throw

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
}
