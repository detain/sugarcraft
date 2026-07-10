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
    private function makeDriver(array &$sink): ThroughStream
    {
        $upstream = new ThroughStream();
        $driver = new ReactInputDriver($upstream);
        $driver->on('data', function (Event $event) use (&$sink): void {
            $sink[] = $event;
        });

        return $upstream;
    }

    /**
     * (a) A chunk far larger than MAX_CHUNK_SIZE must be decoded with nothing
     * dropped — one KeyEvent per printable byte, in order.
     */
    public function testOversizedChunkDropsNothing(): void
    {
        $events = [];
        $upstream = $this->makeDriver($events);

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
        $upstream = $this->makeDriver($events);

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
        $upstream = $this->makeDriver($events);

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
        $upstream = $this->makeDriver($events);

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
        $upstream = $this->makeDriver($events);

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
}
