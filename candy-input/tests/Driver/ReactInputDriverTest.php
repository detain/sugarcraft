<?php

declare(strict_types=1);

namespace SugarCraft\Input\Tests\Driver;

use PHPUnit\Framework\TestCase;
use React\Stream\ThroughStream;
use SugarCraft\Input\Driver\ReactInputDriver;
use SugarCraft\Input\Event;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\EscapeDecoder;

/**
 * Tests for ReactInputDriver — in particular the DoS chunk bound that slices
 * an oversized 'data' event into <=8192-byte pieces before decoding, mirroring
 * StreamInputDriver's fread(8192) cap.
 *
 * The driver is driven through a real React\Stream\ThroughStream, whose write()
 * emits exactly one 'data' event carrying the whole payload — the same seam a
 * paste bomb would arrive on.
 */
final class ReactInputDriverTest extends TestCase
{
    /**
     * Wire a driver onto a fresh ThroughStream and collect every emitted Event
     * into $sink (by reference), so a test can write a chunk and inspect the
     * decoded events.
     *
     * @param list<Event> $sink
     * @return array{0: ReactInputDriver, 1: ThroughStream}
     */
    private function makeDriver(array &$sink): array
    {
        $upstream = new ThroughStream();
        $driver = new ReactInputDriver($upstream);
        $driver->on('data', function (Event $event) use (&$sink): void {
            $sink[] = $event;
        });

        return [$driver, $upstream];
    }

    /**
     * (a) A chunk far larger than MAX_CHUNK_SIZE must be decoded with nothing
     * dropped — one KeyEvent per printable byte, in order.
     */
    public function testOversizedChunkDropsNothing(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        $size = 20000; // > 2 * 8192, so at least three slices
        $upstream->write(str_repeat('a', $size));

        $this->assertCount($size, $events);
        foreach ($events as $event) {
            $this->assertInstanceOf(KeyEvent::class, $event);
            $this->assertSame('a', $event->key);
        }
    }

    /**
     * (b) A CSI escape sequence whose ESC introducer lands exactly on the 8192
     * boundary must still decode to the SAME single ArrowRight event as when
     * the chunk is fed whole. The 8191 leading bytes push the ESC to offset
     * 8191 (the last byte of the first slice); the "[C" tail lands in the next.
     */
    public function testCsiSequenceStraddlingBoundaryDecodesWhole(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        $pad = 8191; // ESC lands at offset 8191 == last byte of first slice
        $chunk = str_repeat('a', $pad) . "\x1b[C" . str_repeat('b', 5);
        $upstream->write($chunk);

        // Compare against a whole-chunk decode by a fresh decoder — the driver
        // must produce byte-identical events despite slicing internally.
        $whole = (new EscapeDecoder())->decode($chunk);
        $this->assertSame(
            array_map(fn(Event $e) => $e::class, $whole),
            array_map(fn(Event $e) => $e::class, $events),
        );

        // Exactly one ArrowRight, at the straddle position, and no stray keys.
        $arrows = array_values(array_filter(
            $events,
            fn(Event $e) => $e instanceof KeyEvent && $e->key === 'ArrowRight',
        ));
        $this->assertCount(1, $arrows);
        $this->assertCount($pad + 1 + 5, $events); // pad 'a' + ArrowRight + 5 'b'
    }

    /**
     * (b') A multibyte UTF-8 codepoint split across the boundary must decode to
     * one event, not two half-codepoint bytes. The euro sign (U+20AC, "\xe2\x82
     * \xac") has its lead byte at offset 8191, continuation bytes in the next
     * slice.
     */
    public function testUtf8CodepointStraddlingBoundaryDecodesWhole(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        $euro = "\xe2\x82\xac";
        $chunk = str_repeat('a', 8191) . $euro . str_repeat('b', 3);
        $upstream->write($chunk);

        $this->assertCount(8191 + 1 + 3, $events);
        $euroEvents = array_values(array_filter(
            $events,
            fn(Event $e) => $e instanceof KeyEvent && $e->key === $euro,
        ));
        $this->assertCount(1, $euroEvents);
        $this->assertSame($euro, $euroEvents[0]->raw);
    }

    /**
     * (c) A normal small chunk (< MAX_CHUNK_SIZE) is decoded unchanged: one
     * data event per key, in order.
     */
    public function testSmallChunkUnchanged(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        $upstream->write("abc");

        $this->assertCount(3, $events);
        $this->assertSame(['a', 'b', 'c'], array_map(
            fn(Event $e) => $e instanceof KeyEvent ? $e->key : null,
            $events,
        ));
    }

    /**
     * A single small CSI chunk still decodes to its one event.
     */
    public function testSmallEscapeSequenceUnchanged(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        $upstream->write("\x1b[C"); // ArrowRight

        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyEvent::class, $events[0]);
        $this->assertSame('ArrowRight', $events[0]->key);
    }

    /**
     * sliceChunk must never hand the decoder more than MAX_CHUNK_SIZE bytes,
     * and the pieces must reconstruct the input exactly (no bytes dropped).
     * This is the direct guard on the DoS bound.
     */
    public function testSliceChunkNeverExceedsLimit(): void
    {
        $chunk = str_repeat('a', 20000);
        $pieces = ReactInputDriver::sliceChunk($chunk);

        $this->assertGreaterThan(1, count($pieces), 'oversized chunk must be split');
        foreach ($pieces as $piece) {
            $this->assertLessThanOrEqual(8192, strlen($piece));
        }
        $this->assertSame($chunk, implode('', $pieces), 'slicing must be lossless');
    }

    /**
     * sliceChunk must not split immediately after a lone ESC that introduces an
     * escape sequence: the ESC is carried into the next piece so the persistent
     * decoder can pair it with its final bytes. Feeding the pieces to one
     * decoder must reproduce a whole-chunk decode exactly.
     */
    public function testSliceChunkCarriesTrailingEscForStraddle(): void
    {
        $chunk = str_repeat('a', 8191) . "\x1b[C" . str_repeat('b', 5);
        $pieces = ReactInputDriver::sliceChunk($chunk);

        // No piece may end on a lone ESC while more bytes follow.
        $count = count($pieces);
        foreach ($pieces as $index => $piece) {
            if ($index < $count - 1) {
                $this->assertNotSame(
                    "\x1b",
                    substr($piece, -1),
                    'a non-final piece must not end on a lone ESC',
                );
            }
        }
        $this->assertSame($chunk, implode('', $pieces), 'slicing must be lossless');

        // Sequential feed to ONE decoder == whole-chunk decode.
        $decoder = new EscapeDecoder();
        $sliced = [];
        foreach ($pieces as $piece) {
            foreach ($decoder->decode($piece) as $event) {
                $sliced[] = $event;
            }
        }
        $whole = (new EscapeDecoder())->decode($chunk);

        $this->assertSame(
            array_map(fn(Event $e) => $e::class, $whole),
            array_map(fn(Event $e) => $e::class, $sliced),
        );
        $arrows = array_values(array_filter(
            $sliced,
            fn(Event $e) => $e instanceof KeyEvent && $e->key === 'ArrowRight',
        ));
        $this->assertCount(1, $arrows);
    }

    /**
     * An empty chunk yields no pieces (and thus no spurious events).
     */
    public function testSliceChunkEmpty(): void
    {
        $this->assertSame([], ReactInputDriver::sliceChunk(''));
    }

    // ─── sliceChunk edge cases ─────────────────────────────────────────────

    public function testSliceChunkExactlyAtLimit(): void
    {
        // Exactly 8192 bytes - should be one piece
        $chunk = str_repeat('a', 8192);
        $pieces = ReactInputDriver::sliceChunk($chunk);

        $this->assertCount(1, $pieces);
        $this->assertSame($chunk, $pieces[0]);
    }

    public function testSliceChunkJustOverLimit(): void
    {
        // Just over 8192 bytes - should be two pieces
        $chunk = str_repeat('a', 8193);
        $pieces = ReactInputDriver::sliceChunk($chunk);

        $this->assertCount(2, $pieces);
        $this->assertSame(8192, strlen($pieces[0]));
        $this->assertSame(1, strlen($pieces[1]));
        $this->assertSame($chunk, implode('', $pieces));
    }

    public function testSliceChunkEscAtBoundaryCarriedToNextPiece(): void
    {
        // ESC at position 8191 (last byte of first piece) should be carried
        $chunk = str_repeat('a', 8191) . "\x1b[C";
        $pieces = ReactInputDriver::sliceChunk($chunk);

        // First piece should NOT end with lone ESC
        // It should include the ESC since it begins an escape sequence
        $this->assertGreaterThan(1, count($pieces));
        $this->assertSame($chunk, implode('', $pieces));
    }

    public function testSliceChunkEscEscSplitCorrectly(): void
    {
        // ESC ESC at boundary - the second ESC should be carried to next piece
        $chunk = str_repeat('a', 8190) . "\x1b\x1b";
        $pieces = ReactInputDriver::sliceChunk($chunk);

        $this->assertSame($chunk, implode('', $pieces));
    }

    public function testSliceChunkMultipleEscapes(): void
    {
        // Multiple ESC sequences throughout
        $chunk = "abc\x1b[Cdef\x1b[Dghi\x1b[A";
        $pieces = ReactInputDriver::sliceChunk($chunk);

        $this->assertSame($chunk, implode('', $pieces));
    }

    public function testSliceChunkThreePieces(): void
    {
        // More than 2x MAX_CHUNK_SIZE should produce at least 3 pieces
        $chunk = str_repeat('a', 20000);
        $pieces = ReactInputDriver::sliceChunk($chunk);

        $this->assertGreaterThanOrEqual(3, count($pieces));
        $this->assertSame($chunk, implode('', $pieces));
    }

    // ─── isReadable() ────────────────────────────────────────────────────────

    public function testIsReadableWhenNotClosedOrPaused(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        $this->assertTrue($driver->isReadable());
    }

    public function testIsNotReadableWhenClosed(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);
        $driver->close();

        $this->assertFalse($driver->isReadable());
    }

    // ─── close() ────────────────────────────────────────────────────────────

    public function testCloseEmitsCloseEvent(): void
    {
        $events = [];
        $closeCalled = false;
        [$driver, $upstream] = $this->makeDriver($events);
        $driver->on('close', function () use (&$closeCalled): void {
            $closeCalled = true;
        });

        $driver->close();

        $this->assertTrue($closeCalled);
        $this->assertFalse($driver->isReadable());
    }

    public function testCloseIdempotent(): void
    {
        $events = [];
        $closeCount = 0;
        [$driver, $upstream] = $this->makeDriver($events);
        $driver->on('close', function () use (&$closeCount): void {
            $closeCount++;
        });

        $driver->close();
        $driver->close(); // Second close should be no-op

        $this->assertSame(1, $closeCount);
    }

    public function testCloseStopsPausedState(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        $driver->pause();
        $this->assertFalse($driver->isReadable());

        $driver->close();
        $this->assertFalse($driver->isReadable());
    }

    // ─── Error and end events from underlying stream ─────────────────────────

    /**
     * When the underlying stream emits 'end', the driver should emit 'end' too.
     */
    public function testDriverEmitsEndWhenUpstreamEnds(): void
    {
        $events = [];
        $endCalled = false;
        [$driver, $upstream] = $this->makeDriver($events);
        $driver->on('end', function () use (&$endCalled): void {
            $endCalled = true;
        });

        // Emit 'end' on the upstream (simulating end of stream)
        $upstream->emit('end');

        $this->assertTrue($endCalled);
    }

    /**
     * When the underlying stream is closed, isReadable should return false.
     */
    public function testIsNotReadableAfterUpstreamClose(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        // Close the upstream
        $upstream->close();

        $this->assertFalse($driver->isReadable());
    }

    // ─── Double ESC sequence ────────────────────────────────────────────────

    public function testDoubleEscapeProducesAltEscape(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        $upstream->write("\x1b\x1b");

        $this->assertCount(1, $events);
        $this->assertInstanceOf(KeyEvent::class, $events[0]);
        $this->assertSame('Escape', $events[0]->key);
        $this->assertTrue($events[0]->modifiers->includes(\SugarCraft\Input\KeyModifier::ALT));
    }

    // ─── Partial sequences across chunks ─────────────────────────────────

    public function testPartialSequenceCompletedAcrossWrites(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        // Write ESC [ (incomplete)
        $upstream->write("\x1b[");
        $this->assertCount(0, $events); // Nothing yet

        // Complete the sequence
        $upstream->write("A");
        $this->assertCount(1, $events);
        $this->assertSame('ArrowUp', $events[0]->key);
    }

    // ─── Exception handling ────────────────────────────────────────────────

    public function testPartialCsiDoesNotCrash(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        // Partial CSI should not cause exception
        $upstream->write("\x1b[");

        $this->assertCount(0, $events);
    }

    public function testInvalidUtf8DoesNotCrash(): void
    {
        $events = [];
        [$driver, $upstream] = $this->makeDriver($events);

        // Invalid UTF-8 bytes
        $upstream->write("\xff\xfe");

        $this->assertCount(2, $events);
    }
}
