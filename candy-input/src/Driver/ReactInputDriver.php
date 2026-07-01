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
     */
    private function handleChunk(string $chunk): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $events = $this->decoder->decode($chunk);
            foreach ($events as $event) {
                $this->emitEvent($event);
            }
        } catch (\Throwable $e) {
            $this->emit('error', [$e]);
            $this->close();
        }
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
