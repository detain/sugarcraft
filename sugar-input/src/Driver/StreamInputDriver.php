<?php

declare(strict_types=1);

namespace SugarCraft\Input\Driver;

use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\InputDriver;
use SugarCraft\Input\Event;

/**
 * InputDriver backed by a PHP resource (e.g. STDIN).
 *
 * Reads bytes from the stream in non-blocking mode and feeds them
 * through EscapeDecoder.
 *
 * @see InputDriver
 */
final class StreamInputDriver implements InputDriver
{
    private EscapeDecoder $decoder;

    /** Buffered events from the last decode that haven't been returned yet */
    private array $eventBuffer = [];

    /** Whether the caller's stream was blocking before we took it over */
    private bool $restoresBlocking = false;

    /**
     * @param resource $stream A readable stream (STDIN, fopen('php://stdin', 'r'), etc.)
     *
     * The driver needs a non-blocking stream and therefore clears the blocking
     * flag on the CALLER's resource; the previous state is remembered and put
     * back when this driver is destroyed, so the hand-over is not permanent.
     */
    public function __construct(
        private readonly mixed $stream,
    ) {
        $this->decoder = new EscapeDecoder();
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
     * Read the next Event from the stream, or null on EOF / non-blocking empty.
     */
    public function read(): Event|null
    {
        // Return buffered events first
        if ($this->eventBuffer !== []) {
            return array_shift($this->eventBuffer);
        }

        $chunk = $this->readNonBlocking();
        if ($chunk === '' || $chunk === false) {
            // At EOF nothing more will ever arrive: drain any remainder the
            // decoder is still holding (e.g. the "\r" that followed a
            // bracketed-paste terminator) instead of returning null forever.
            // A live stream keeps the remainder buffered awaiting more bytes.
            if (feof($this->stream)) {
                $this->eventBuffer = $this->decoder->decode('');
                return array_shift($this->eventBuffer);
            }
            return null;
        }

        $events = $this->decoder->decode($chunk);
        if ($events === []) {
            // Partial sequence — try again with more data
            $more = $this->readNonBlocking();
            if ($more === '' || $more === false) {
                return null;
            }
            $events = $this->decoder->decode($more);
            if ($events === []) {
                return null;
            }
        }

        if (count($events) > 1) {
            // Buffer excess events for subsequent read() calls
            $first = array_shift($events);
            $this->eventBuffer = $events;

            return $first;
        }

        return $events[0];
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
