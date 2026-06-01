<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Input;

use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\Event;
use SugarCraft\Pty\Contract\MasterPty;

/**
 * High-level PTY input reader that decodes raw bytes into typed Events.
 *
 * Wraps a {@see MasterPty} with an {@see EscapeDecoder} so callers receive
 * parsed key/mouse/focus/paste events instead of raw escape bytes.
 * Partial sequences are buffered across calls — the decoder is reentrant.
 *
 * This is the canonical entry point for PTY consumers that need symbolic
 * input (arrow keys, Kitty keyboard protocol, SGR mouse, bracketed paste).
 *
 * @see \SugarCraft\Input\EscapeDecoder
 * @see Mirrors charmbracelet/bubbletea (input handling).
 */
final class PtyInputDecoder
{
    public function __construct(
        private readonly MasterPty $master,
        private readonly EscapeDecoder $decoder = new EscapeDecoder(),
        private int $readChunk = 8192,
    ) {}

    /**
     * Read and decode the next chunk of input events.
     *
     * Returns an empty array when no complete events are available yet
     * (partial escape sequences are buffered internally).
     *
     * @return list<Event>
     */
    public function readEvents(float $timeout = 0.05): array
    {
        $bytes = $this->master->read($this->readChunk, $timeout);
        if ($bytes === null || $bytes === '') {
            return [];
        }
        return $this->decoder->decode($bytes);
    }

    /**
     * Convenience: read events with a blocking wait until at least
     * one event is available.
     *
     * @return list<Event>
     */
    public function readEventsBlocking(?float $timeout = null): array
    {
        $deadline = $timeout !== null ? \microtime(true) + $timeout : null;
        while (true) {
            $remaining = $deadline !== null ? $deadline - \microtime(true) : 0.05;
            if ($remaining <= 0 && $timeout !== null) {
                return [];
            }
            $events = $this->readEvents(\min($remaining ?? 0.05, 0.05));
            if ($events !== []) {
                return $events;
            }
            // No events yet — brief sleep before retrying
            if ($deadline === null || \microtime(true) < $deadline) {
                \usleep(10_000);
            }
        }
    }

    /**
     * Get buffered bytes that could not form complete events.
     * Use this when shutting down to drain partially-read sequences.
     */
    public function remainder(): string
    {
        return $this->decoder->remainder();
    }

    /**
     * Reset the decoder state (clears partial sequence buffer).
     */
    public function reset(): void
    {
        $this->decoder->reset();
    }
}
