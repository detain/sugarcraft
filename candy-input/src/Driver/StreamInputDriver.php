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

    /** @param resource $stream A readable stream (STDIN, fopen('php://stdin', 'r'), etc.) */
    public function __construct(
        private readonly mixed $stream,
    ) {
        $this->decoder = new EscapeDecoder();
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
            return null;
        }

        $events = $this->decoder->decode($chunk);
        if ($events === []) {
            // Partial sequence — try again with more data
            $more = $this->readNonBlocking();
            if ($more === '' || $more === false) {
                return null;
            }
            $events = $this->decoder->decode($this->decoder->remainder() . $more);
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
        $meta = stream_get_meta_data($this->stream);

        // If it's a non-blocking stream, check if data is available
        if (!($meta['blocked'] ?? false)) {
            $read = [$this->stream];
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, 0);
            if ($changed === false || $changed === 0) {
                return '';
            }
        }

        return fread($this->stream, 8192);
    }
}
