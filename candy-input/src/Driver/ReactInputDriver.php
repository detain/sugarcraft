<?php

declare(strict_types=1);

namespace SugarCraft\Input\Driver;

use Evenement\EventEmitterTrait;
use React\Stream\ReadableStreamInterface;
use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\Event;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event\ResizeEvent;

/**
 * ReactPHP-compatible input driver that wraps a ReadableStreamInterface.
 *
 * Feeds raw bytes from a ReactPHP stream through EscapeDecoder and emits
 * decoded Events as 'data' events. This driver is suitable for use in
 * async ReactPHP event loops where blocking stream_select() is not appropriate.
 *
 * Usage:
 * ```php
 * $loop = React\EventLoop\Loop::get();
 * $stdin = new React\Stream\ReadableResourceStream(STDIN, $loop);
 * $driver = new ReactInputDriver($stdin);
 *
 * $driver->on('data', function (Event $event) {
 *     // handle key, mouse, focus, paste, or resize event
 * });
 * ```
 *
 * @see ReadableStreamInterface
 * @see EscapeDecoder
 */
final class ReactInputDriver implements ReadableStreamInterface
{
    use EventEmitterTrait;

    /**
     * Upper bound on the number of bytes handed to a single decode() call.
     *
     * The synchronous StreamInputDriver caps every read at 8192 bytes
     * (fread($stream, 8192)), so its decoder never sees more than 8192 bytes
     * at once. This driver, by contrast, is handed the ENTIRE received chunk
     * in one ReactPHP 'data' event, so an unbounded paste bomb or a stuck
     * terminal could force one giant decode() that materialises an event list
     * as large as the input before any of it is emitted — a CPU/memory-DoS
     * vector. Slicing incoming chunks restores the same 8192-byte bound.
     */
    private const MAX_CHUNK_SIZE = 8192;

    private EscapeDecoder $decoder;

    private bool $closed = false;

    /** Buffered events pending emission */
    private array $eventBuffer = [];

    /** Whether the stream is paused */
    private bool $paused = false;

    public function __construct(
        private readonly ReadableStreamInterface $stream,
    ) {
        $this->decoder = new EscapeDecoder();
        $stream->on('data', fn(string $chunk) => $this->handleChunk($chunk));
        $stream->on('end', fn() => $this->handleEnd());
        $stream->on('error', fn(\Throwable $e) => $this->handleError($e));
        $stream->on('close', fn() => $this->close());
    }

    /**
     * Checks whether this stream is in a readable state.
     */
    public function isReadable(): bool
    {
        return !$this->closed && !$this->paused;
    }

    /**
     * Pauses receiving data events.
     *
     * For this driver, pause/resume forward to the underlying stream
     * since the decoding is immediate and we have no internal buffering
     * beyond pending events from a single chunk.
     */
    public function pause(): void
    {
        $this->paused = true;
        $this->stream->pause();
    }

    /**
     * Resumes receiving data events.
     */
    public function resume(): void
    {
        $this->paused = false;
        $this->stream->resume();
    }

    /**
     * Pipes all data from this readable stream into the given writable destination.
     */
    public function pipe(\React\Stream\WritableStreamInterface $dest, array $options = []): \React\Stream\WritableStreamInterface
    {
        return \React\Stream\ReadableStreamInterface::pipe($this, $dest, $options);
    }

    /**
     * Closes the stream and emits a 'close' event.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->paused = false;
        $this->stream->close();
        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * Handle an incoming data chunk from the underlying stream.
     *
     * The chunk is sliced into pieces of at most MAX_CHUNK_SIZE bytes before
     * decoding so a single hostile 'data' event can never force one unbounded
     * decode() (see sliceChunk / MAX_CHUNK_SIZE). Feeding the pieces to the
     * SAME decoder instance in order yields exactly the same events as one
     * whole-chunk decode, because EscapeDecoder stitches an incomplete trailing
     * sequence across decode() calls via its internal $remainder.
     */
    private function handleChunk(string $chunk): void
    {
        if ($this->closed) {
            return;
        }

        try {
            foreach (self::sliceChunk($chunk) as $piece) {
                foreach ($this->decoder->decode($piece) as $event) {
                    $this->emitEvent($event);
                }
            }
        } catch (\Throwable $e) {
            $this->emit('error', [$e]);
            $this->close();
        }
    }

    /**
     * Split a raw input chunk into pieces of at most MAX_CHUNK_SIZE bytes.
     *
     * EscapeDecoder buffers an incomplete trailing sequence — a CSI, SS3, Kitty
     * or bracketed-paste sequence, or a partial UTF-8 codepoint — in its
     * internal $remainder and completes it on the next decode() call, so
     * feeding these pieces to one decoder instance in order reproduces a whole
     * -chunk decode byte-for-byte, with a single exception: a lone trailing ESC
     * is emitted eagerly as an Escape key rather than buffered. To keep an
     * escape sequence that straddles a MAX_CHUNK_SIZE boundary intact, a piece
     * is never allowed to end on a lone ESC that begins (rather than ends) the
     * buffer; that ESC is carried into the next piece instead.
     *
     * @return list<string> Non-empty pieces whose concatenation equals $chunk.
     * @internal Public only so the DoS chunk bound is directly testable.
     */
    public static function sliceChunk(string $chunk): array
    {
        $len = strlen($chunk);
        if ($len <= self::MAX_CHUNK_SIZE) {
            return $len === 0 ? [] : [$chunk];
        }

        $pieces = [];
        $offset = 0;
        while ($offset < $len) {
            $take = min(self::MAX_CHUNK_SIZE, $len - $offset);
            // A full-size piece whose last byte is a lone ESC (its predecessor
            // is not itself an ESC) and that has bytes following it would split
            // an escape sequence right after its introducer. Carry the ESC into
            // the next piece so the decoder can pair it with its final bytes.
            if (
                $take === self::MAX_CHUNK_SIZE
                && $offset + $take < $len
                && $chunk[$offset + $take - 1] === "\x1b"
                && $chunk[$offset + $take - 2] !== "\x1b"
            ) {
                $take--;
            }
            $pieces[] = substr($chunk, $offset, $take);
            $offset += $take;
        }

        return $pieces;
    }

    /**
     * Emit a single event, buffering if paused.
     */
    private function emitEvent(Event $event): void
    {
        if ($this->closed) {
            return;
        }

        if ($this->paused) {
            $this->eventBuffer[] = $event;
            return;
        }

        $this->emit('data', [$event]);
    }

    /**
     * Handle the 'end' event from the underlying stream.
     */
    private function handleEnd(): void
    {
        if ($this->closed) {
            return;
        }

        // Flush any remaining events
        while (!$this->closed && !$this->paused && $this->eventBuffer !== []) {
            $event = array_shift($this->eventBuffer);
            $this->emit('data', [$event]);
        }

        $this->emit('end');
        $this->close();
    }

    /**
     * Handle an error from the underlying stream.
     */
    private function handleError(\Throwable $e): void
    {
        if ($this->closed) {
            return;
        }
        $this->emit('error', [$e]);
        $this->close();
    }
}
