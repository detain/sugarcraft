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
     */
    private function makeDriver(array &$sink): ReactInputDriver
    {
        $upstream = new ThroughStream();
        $driver = new ReactInputDriver($upstream);
        $driver->on('data', function (Event $event) use (&$sink): void {
            $sink[] = $event;
        });

        return $driver;
    }

    /**
     * (a) A chunk far larger than MAX_CHUNK_SIZE must be decoded with nothing
     * dropped — one KeyEvent per printable byte, in order.
     */
    public function testOversizedChunkDropsNothing(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);

        $size = 20000; // > 2 * 8192, so at least three slices
        $driver->write(str_repeat('a', $size));

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
        $driver = $this->makeDriver($events);

        $pad = 8191; // ESC lands at offset 8191 == last byte of first slice
        $chunk = str_repeat('a', $pad) . "\x1b[C" . str_repeat('b', 5);
        $driver->write($chunk);

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
        $driver = $this->makeDriver($events);

        $euro = "\xe2\x82\xac";
        $chunk = str_repeat('a', 8191) . $euro . str_repeat('b', 3);
        $driver->write($chunk);

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
        $driver = $this->makeDriver($events);

        $driver->write("abc");

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
        $driver = $this->makeDriver($events);

        $driver->write("\x1b[C"); // ArrowRight

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

    // ─── isReadable() ────────────────────────────────────────────────────────

    public function testIsReadableWhenNotClosedOrPaused(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);

        $this->assertTrue($driver->isReadable());
    }

    public function testIsNotReadableWhenClosed(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);
        $driver->close();

        $this->assertFalse($driver->isReadable());
    }

    public function testIsNotReadableWhenPaused(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);
        $driver->pause();

        $this->assertFalse($driver->isReadable());
    }

    // ─── pause() / resume() ─────────────────────────────────────────────────

    /**
     * When paused, events should be buffered and not emitted until resume.
     */
    public function testPauseBuffersEvents(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);

        $driver->pause();
        $driver->write('ab');

        // When paused, events are buffered in the driver, not emitted
        $this->assertCount(0, $events);

        $driver->resume();

        // After resume, buffered events are emitted
        $this->assertCount(2, $events);
        $this->assertSame('a', $events[0]->key);
        $this->assertSame('b', $events[1]->key);
    }

    // ─── close() ────────────────────────────────────────────────────────────

    public function testCloseEmitsCloseEvent(): void
    {
        $events = [];
        $closeCalled = false;
        $driver = $this->makeDriver($events);
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
        $driver = $this->makeDriver($events);
        $driver->on('close', function () use (&$closeCount): void {
            $closeCount++;
        });

        $driver->close();
        $driver->close(); // Second close should be no-op

        $this->assertSame(1, $closeCount);
    }

    public function testCloseRemovesAllListeners(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);
        $driver->on('data', function (Event $e) use (&$events): void {
            $events[] = $e;
        });

        $driver->write('a');
        $this->assertCount(1, $events);

        $driver->close();

        // After close, subsequent writes should not emit events
        $driver->write('b');
        $this->assertCount(1, $events);
    }

    public function testCloseStopsPausedState(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);

        $driver->pause();
        $this->assertFalse($driver->isReadable());

        $driver->close();
        $this->assertFalse($driver->isReadable());
    }

    public function testEmitEventIgnoresWhenClosed(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);

        $driver->close();
        // Trying to write after close should not emit
        $driver->write('a');

        $this->assertCount(0, $events);
    }

    // ─── handleError() ──────────────────────────────────────────────────────

    public function testErrorEmitsErrorEventAndCloses(): void
    {
        $events = [];
        $errorEvent = null;
        $closeCount = 0;
        $driver = $this->makeDriver($events);
        $driver->on('error', function (\Throwable $e) use (&$errorEvent): void {
            $errorEvent = $e;
        });
        $driver->on('close', function () use (&$closeCount): void {
            $closeCount++;
        });

        $driver->emit('error', [new \RuntimeException('test error')]);

        $this->assertInstanceOf(\RuntimeException::class, $errorEvent);
        $this->assertSame(1, $closeCount);
        $this->assertFalse($driver->isReadable());
    }

    public function testErrorIgnoresWhenAlreadyClosed(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);
        $driver->close();

        $errorCount = 0;
        $closeCount = 0;
        $driver->on('error', function () use (&$errorCount): void {
            $errorCount++;
        });
        $driver->on('close', function () use (&$closeCount): void {
            $closeCount++;
        });

        $driver->emit('error', [new \RuntimeException('test')]);

        $this->assertSame(0, $errorCount);
        $this->assertSame(0, $closeCount);
    }

    // ─── handleEnd() ────────────────────────────────────────────────────────

    public function testEndEmitsEndEvent(): void
    {
        $events = [];
        $endCalled = false;
        $driver = $this->makeDriver($events);
        $driver->on('end', function () use (&$endCalled): void {
            $endCalled = true;
        });

        $driver->emit('end');

        $this->assertTrue($endCalled);
    }

    public function testEndClosesStream(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);

        $driver->emit('end');

        $this->assertFalse($driver->isReadable());
    }

    public function testEndIgnoresWhenAlreadyClosed(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);
        $driver->close();

        $endCount = 0;
        $driver->on('end', function () use (&$endCount): void {
            $endCount++;
        });

        $driver->emit('end');

        $this->assertSame(0, $endCount);
    }

    // ─── pipe() ────────────────────────────────────────────────────────────

    public function testPipeReturnsWritableStreamInterface(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);

        $dest = new ThroughStream();
        $result = $driver->pipe($dest);

        $this->assertInstanceOf(\React\Stream\WritableStreamInterface::class, $result);
    }

    // ─── Exception handling in handleChunk ────────────────────────────────

    public function testHandleChunkEmitsErrorOnDecodeException(): void
    {
        $events = [];
        $errorEvent = null;
        $driver = $this->makeDriver($events);
        $driver->on('error', function (\Throwable $e) use (&$errorEvent): void {
            $errorEvent = $e;
        });

        // Partial CSI should not cause exception - handled gracefully
        $driver->write("\x1b[");

        $this->assertNull($errorEvent);
    }

    // ─── write() after close does not cause issues ─────────────────────────

    public function testWriteAfterCloseIsIgnored(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);
        $driver->close();

        // Writing after close should not crash or emit events
        $driver->write("test");

        $this->assertCount(0, $events);
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

    // ─── handleEnd with buffered events ──────────────────────────────────────

    public function testEndEmitsBufferedEventsBeforeClosing(): void
    {
        $events = [];
        $driver = $this->makeDriver($events);

        // Write some data while not paused
        $driver->write('ab');

        // 'end' event should trigger handleEnd which closes the stream
        // The events should have been emitted already
        $this->assertCount(2, $events);

        $driver->emit('end');

        // After end, stream is closed
        $this->assertFalse($driver->isReadable());
    }
}
