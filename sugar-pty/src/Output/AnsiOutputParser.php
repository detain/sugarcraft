<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Output;

use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\Pty\Contract\MasterPty;

/**
 * High-level PTY output reader that parses ANSI sequences via the VT500 parser.
 *
 * Wraps a {@see MasterPty} with a {@see \SugarCraft\AnsiParser\Parser\Parser} and an
 * {@see SgrHandler} so callers can track SGR state transitions in the PTY
 * output stream (e.g. to assert that `"\x1b[31mred\x1b[0m"` produced the
 * expected color changes).
 *
 * This is the canonical entry point for PTY consumers that need to inspect
 * ANSI rendering state (colors, bold, underline) emitted by the child
 * process.
 *
 * @see \SugarCraft\AnsiParser\Parser\Parser
 * @see \SugarCraft\Pty\Output\SgrHandler
 * @see Mirrors charmbracelet/x/ansi (output parsing).
 */
final class AnsiOutputParser
{
    private bool $sawEof = false;

    public function __construct(
        private readonly MasterPty $master,
        private readonly Parser $parser,
        private readonly SgrHandler $handler,
        private int $readChunk = 8192,
    ) {
        if ($readChunk < 1) {
            throw new \InvalidArgumentException("readChunk must be >= 1, got {$readChunk}");
        }
    }

    /**
     * Create a new parser for the given master PTY.
     *
     * Convenience factory that wires up the Parser + SgrHandler + state
     * tracking automatically.
     */
    public static function forMaster(MasterPty $master): self
    {
        $handler = new SgrHandler();
        $parser = new Parser($handler);
        return new self($master, $parser, $handler);
    }

    /**
     * Read and parse the next chunk of output bytes.
     *
     * After this call, {@see self::state()} reflects the current SGR state after
     * consuming the bytes. The {@see SgrHandler} tracks state transitions
     * across calls, so this is safe to call repeatedly with streaming chunks.
     *
     * @return string  Raw bytes exactly as read from the master, ANSI escape
     *                 sequences included — this method does NOT strip them.
     *                 SGR state is tracked in the handler (drain transitions
     *                 via {@see SgrHandler::drainTransitions()} or use
     *                 {@see readChunkWithTransitions()}).
     *
     * An empty return covers BOTH "nothing yet" and "the stream ended" —
     * the master read taxonomy distinguishes them (null = idle, `''` =
     * confirmed EOF) and the EOF verdict is latched here: ask
     * {@see isEof()} when the caller must know whether an empty chunk
     * means wait-more or stop-reading (the twin's rule, mirrored from
     * {@see \SugarCraft\Pty\Input\PtyInputDecoder}).
     */
    public function readChunk(float $timeout = 0.05): string
    {
        $bytes = $this->master->read($this->readChunk, $timeout);
        if ($bytes === '') {
            $this->sawEof = true;

            return '';
        }
        if ($bytes === null) {
            return '';
        }

        // Feed through the parser — the SgrHandler updates $state on SGR 'm' sequences
        $this->parser->feed($bytes);

        // Return the raw bytes for consumers who need them
        // (the SGR state is tracked in $this->handler->state)
        return $bytes;
    }

    /**
     * Read and parse with SGR transition tracking.
     *
     * Returns a list of (before_state, after_state) pairs for every SGR
     * transition encountered in the chunk. Plain text and control
     * characters do not produce transition events.
     *
     * @return list<array{SgrState, SgrState}>
     */
    public function readChunkWithTransitions(float $timeout = 0.05): array
    {
        $bytes = $this->master->read($this->readChunk, $timeout);
        if ($bytes === '') {
            $this->sawEof = true;

            return [];
        }
        if ($bytes === null) {
            return [];
        }

        // Drain any stale events from prior calls so we only return
        // transitions from this chunk.
        $this->handler->drainTransitions();

        $this->parser->feed($bytes);

        return $this->handler->drainTransitions();
    }

    /**
     * Current SGR state after the last readChunk call. The state object
     * itself is replaced wholesale by the handler on each chunk, so the
     * value returned here is a snapshot; it is not a language-level
     * readonly member (@readonly belongs on declarations, not methods).
     */
    public function state(): SgrState
    {
        return $this->handler->state;
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
     * Reset to ground state: clears the VT parser, the tracked SGR state,
     * the pending transition log, and the latched EOF verdict — after this
     * call the parser behaves as if it had never consumed any bytes.
     */
    public function reset(): void
    {
        $this->parser->reset();
        $this->handler->reset();
        $this->sawEof = false;
    }
}
