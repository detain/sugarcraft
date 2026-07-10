<?php

declare(strict_types=1);

namespace SugarCraft\Spark;

use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\Parser;

/**
 * Streaming incremental parser for ANSI escape sequences.
 *
 * Unlike {@see Inspector::parse()} which requires complete input,
 * StreamingInspector can be fed input in chunks. It yields complete
 * segments as they are finished, and buffers incomplete sequences
 * and plain text between calls to {@see feed()}.
 *
 * Uses candy-ansi's incremental {@see Parser} — sequences split across
 * chunks are automatically continued from the correct state.  C0 control
 * codes are isolated as their own segments (matching one-shot behavior),
 * and the 4:N underline sub-parameter form is preserved through the
 * shared AnsiHandler.
 *
 * Text segments are flushed when a sequence is encountered or at
 * end-of-stream via {@see finish()}.
 */
final class StreamingInspector
{
    /** Persistent ANSI parser. */
    private Parser $parser;

    /** Segment-collecting handler backed by $this. */
    private AnsiHandler $handler;

    public function __construct()
    {
        $this->handler = new AnsiHandler();
        $this->parser = new Parser($this->handler);
    }

    /**
     * Feed a chunk of input. Segments completed by this chunk are returned;
     * incomplete sequences are held in the parser state for the next chunk.
     *
     * @return list<Segment>
     */
    public function feed(string $data): array
    {
        $this->parser->feed($data);
        return $this->handler->drainSegments();
    }

    /**
     * Flush any remaining buffered text and finalise pending sequences.
     *
     * A bare ESC at the end of the stream (e.g. "\x1b" with no following byte)
     * is emitted as its own SequenceSegment.  A buffered SS3 intermediate
     * (ESC O with no final byte) is also emitted.
     *
     * @return list<Segment>
     */
    public function finish(): array
    {
        // Capture the parser state BEFORE flush(): flush() resets it to Ground,
        // so a post-flush check would never observe State::Escape and a trailing
        // bare ESC would be silently dropped. finishPending() then flushes any
        // remaining text and emits the bare ESC / dangling SS3 tail via public
        // AnsiHandler API — no reaching into private/protected members.
        $stateBeforeFlush = $this->parser->currentState();
        $this->parser->flush();

        $this->handler->finishPending($stateBeforeFlush);

        $out = $this->handler->drainSegments();
        $this->handler->reset();
        return $out;
    }
}
