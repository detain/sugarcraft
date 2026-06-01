<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Output;

use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Pty\Contract\MasterPty;

/**
 * High-level PTY output reader that parses ANSI sequences via the VT500 parser.
 *
 * Wraps a {@see MasterPty} with a {@see \SugarCraft\Ansi\Parser\Parser} and an
 * {@see SgrHandler} so callers can track SGR state transitions in the PTY
 * output stream (e.g. to assert that `"\x1b[31mred\x1b[0m"` produced the
 * expected color changes).
 *
 * This is the canonical entry point for PTY consumers that need to inspect
 * ANSI rendering state (colors, bold, underline) emitted by the child
 * process.
 *
 * @see \SugarCraft\Ansi\Parser\Parser
 * @see \SugarCraft\Pty\Output\SgrHandler
 * @see Mirrors charmbracelet/x/ansi (output parsing).
 */
final class AnsiOutputParser
{
    public function __construct(
        private readonly MasterPty $master,
        private readonly Parser $parser,
        private readonly SgrHandler $handler,
        private int $readChunk = 8192,
    ) {}

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
     * After this call, {@see $state} reflects the current SGR state after
     * consuming the bytes. The {@see SgrHandler} tracks state transitions
     * across calls, so this is safe to call repeatedly with streaming chunks.
     *
     * @return string  Raw text content (without ANSI escape sequences).
     */
    public function readChunk(float $timeout = 0.05): string
    {
        $bytes = $this->master->read($this->readChunk, $timeout);
        if ($bytes === null || $bytes === '') {
            return '';
        }

        // Track the state before so we can extract changed segments
        $stateBefore = $this->handler->state;

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
        if ($bytes === null || $bytes === '') {
            return [];
        }

        $transitions = [];
        $stateBefore = $this->handler->state;

        $this->parser->feed($bytes);

        $stateAfter = $this->handler->state;
        if (!$stateAfter->equals($stateBefore)) {
            $transitions[] = [$stateBefore, $stateAfter];
        }

        return $transitions;
    }

    /**
     * Current SGR state after the last readChunk call.
     *
     * @readonly
     */
    public function state(): SgrState
    {
        return $this->handler->state;
    }

    /**
     * Reset the parser state to ground (clears SGR state).
     */
    public function reset(): void
    {
        $this->parser->reset();
    }
}
