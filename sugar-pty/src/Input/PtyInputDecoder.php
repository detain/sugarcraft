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
    private bool $sawEof = false;

    public function __construct(
        private readonly MasterPty $master,
        private readonly EscapeDecoder $decoder = new EscapeDecoder(),
        private int $readChunk = 8192,
    ) {
        if ($readChunk < 1) {
            throw new \InvalidArgumentException("readChunk must be >= 1, got {$readChunk}");
        }
    }

    /**
     * Read and decode the next chunk of input events.
     *
     * Returns an empty array for BOTH "nothing yet" and "the stream
     * ended" — the master read taxonomy distinguishes them (null =
     * idle, `''` = confirmed EOF) and the EOF verdict is latched here:
     * ask {@see isEof()} when the caller must know whether an empty
     * result means wait-more or stop-muxing (m2, round lib-fixes-1103).
     * Partial escape sequences stay buffered across calls.
     *
     * @return list<Event>
     */
    public function readEvents(float $timeout = 0.05): array
    {
        $bytes = $this->master->read($this->readChunk, $timeout);
        if ($bytes === '') {
            $this->sawEof = true;
            return [];
        }
        if ($bytes === null) {
            return [];
        }
        return $this->decoder->decode($bytes);
    }

    /**
     * Latched once a read observed master EOF (`''`). Cleared only by
     * {@see reset()}.
     */
    public function isEof(): bool
    {
        return $this->sawEof;
    }

    /**
     * Convenience: read events with a blocking wait until at least
     * one event is available.
     *
     * WARNING: passing `null` (the default) blocks INDEFINITELY — the
     * poll loop re-arms with no deadline and only returns once at least
     * one event decodes. A caller whose input stream can stall (child
     * waiting on a prompt, pipe with no writer) will hang here rather
     * than fail, which is exactly the shape this lib's HangWatchdog
     * exists to catch in tests. In unattended or deadline-bound
     * contexts, always pass an explicit `$timeout` and handle the empty
     * list it yields on expiry.
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
     * Reset the decoder state (clears partial sequence buffer and the
     * latched EOF verdict).
     */
    public function reset(): void
    {
        $this->decoder->reset();
        $this->sawEof = false;
    }
}
