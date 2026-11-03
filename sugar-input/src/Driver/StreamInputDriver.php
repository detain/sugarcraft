<?php

declare(strict_types=1);

namespace SugarCraft\Input\Driver;

use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\EscapeDecoderOptions;
use SugarCraft\Input\InputDriver;
use SugarCraft\Input\Event;

/**
 * InputDriver backed by a PHP stream resource (e.g. STDIN).
 *
 * Reads bytes from the stream in non-blocking mode and feeds them
 * through EscapeDecoder.
 *
 * Idle vs EOF: read() returns null for BOTH "nothing to read right now"
 * and "stream ended" — the null alone does not distinguish them. Call
 * isEof() to tell the difference; a poll loop that only checks for null
 * must back off (sleep between empty reads) or it busy-spins at EOF,
 * and should stop once isEof() answers true with an empty buffer.
 *
 * @see InputDriver
 */
final class StreamInputDriver implements InputDriver
{
    private EscapeDecoder $decoder;

    /** Buffered events from the last decode that haven't been returned yet */
    private array $eventBuffer = [];

    /**
     * Cursor into {@see $eventBuffer}: events are consumed by advancing this
     * offset instead of array_shift-ing, so no drain ever reindexes bytes the
     * loop has already handed out (M17). The buffer compacts to empty once
     * fully consumed.
     */
    private int $bufferOffset = 0;

    /** Whether the caller's stream was blocking before we took it over */
    private bool $restoresBlocking = false;

    /** Set once readNonBlocking() observes end-of-file on the stream. */
    private bool $eof = false;

    /**
     * @param resource              $stream  A readable stream resource (STDIN, fopen('php://stdin', 'r'), etc.)
     * @param EscapeDecoderOptions|null $options Decoder protocol options; null uses all-defaults
     *
     * The driver needs a non-blocking stream and therefore clears the blocking
     * flag on the CALLER's resource; the previous state is remembered and put
     * back when this driver is destroyed, so the hand-over is not permanent.
     */
    public function __construct(
        private readonly mixed $stream,
        ?EscapeDecoderOptions $options = null,
    ) {
        // Parse, don't validate: a non-resource is a usage error at the
        // boundary, not a condition read() can recover from.
        if (!is_resource($this->stream)) {
            throw new \InvalidArgumentException(sprintf(
                'StreamInputDriver needs a readable stream resource, got %s.',
                get_debug_type($this->stream),
            ));
        }

        $this->decoder = new EscapeDecoder($options);
        $meta = @stream_get_meta_data($this->stream);
        if (is_array($meta) && ($meta['blocked'] ?? false) === true) {
            $this->restoresBlocking = true;
        }
        stream_set_blocking($this->stream, false);
    }

    /**
     * Hand the caller's stream back the way we found it.
     */
    public function __destruct()
    {
        if ($this->restoresBlocking && is_resource($this->stream)) {
            @stream_set_blocking($this->stream, true);
        }
    }

    /**
     * The decoder instance behind this driver — the seam for hosts that need
     * reset() after a forged unterminated paste, flushDeferredEscape() on an
     * input-idle timer (with deferTrailingEscape), or the drop/drain counters.
     */
    public function escapeDecoder(): EscapeDecoder
    {
        return $this->decoder;
    }

    /**
     * True once the stream has reached end-of-file AND every buffered and
     * remainder event has been handed out.
     */
    public function isEof(): bool
    {
        return $this->eof && !$this->hasPending();
    }

    /**
     * True when more events are already decoded and waiting (driver buffer or
     * decoder remainder) — distinguishes "keep pulling" from a true idle.
     */
    public function hasPending(): bool
    {
        return $this->bufferOffset < count($this->eventBuffer) || $this->decoder->remainder() !== '';
    }

    /**
     * Read the next Event from the stream, or null when the non-blocking read
     * found no data (also returned forever after EOF once buffers are drained).
     */
    public function read(): Event|null
    {
        $buffered = count($this->eventBuffer);
        if ($this->bufferOffset < $buffered) {
            $event = $this->eventBuffer[$this->bufferOffset++];
            if ($this->bufferOffset === $buffered) {
                $this->eventBuffer = [];
                $this->bufferOffset = 0;
            }

            return $event;
        }

        $chunk = $this->readNonBlocking();
        if ($chunk === '' || $chunk === false) {
            // At EOF nothing more will ever arrive: drain any remainder the
            // decoder is still holding (e.g. the "\r" that followed a
            // bracketed-paste terminator) instead of returning null forever.
            // A live stream keeps the remainder buffered awaiting more bytes.
            if (feof($this->stream)) {
                $this->eof = true;
                $this->primeBuffer($this->decoder->decode(''));
            }

            return $this->takeBuffered();
        }

        $events = $this->decoder->decode($chunk);
        if ($events === []) {
            // Partial sequence — try once more with more data
            $more = $this->readNonBlocking();
            if ($more !== '' && $more !== false) {
                $events = $this->decoder->decode($more);
            }
        }
        $this->primeBuffer($events);

        return $this->takeBuffered();
    }

    /**
     * Replace a fully-consumed buffer with freshly decoded events.
     *
     * @param list<Event> $events
     */
    private function primeBuffer(array $events): void
    {
        if ($events === []) {
            return;
        }

        $this->eventBuffer = $events;
        $this->bufferOffset = 0;
    }

    /** Pop the next buffered event, compacting the buffer when drained. */
    private function takeBuffered(): Event|null
    {
        $buffered = count($this->eventBuffer);
        if ($this->bufferOffset >= $buffered) {
            return null;
        }

        $event = $this->eventBuffer[$this->bufferOffset++];
        if ($this->bufferOffset === $buffered) {
            $this->eventBuffer = [];
            $this->bufferOffset = 0;
        }

        return $event;
    }

    /**
     * Read available bytes from the stream without blocking.
     */
    private function readNonBlocking(): string|false
    {
        $read = [$this->stream];
        $write = null;
        $except = null;
        $changed = @stream_select($read, $write, $except, 0, 0);
        if ($changed === false || $changed === 0) {
            return '';
        }

        return fread($this->stream, 8192);
    }
}
