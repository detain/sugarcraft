<?php

declare(strict_types=1);

namespace SugarCraft\Spark;

use SugarCraft\Ansi\Parser\Handler;
use SugarCraft\Ansi\Parser\Parser;
use SugarCraft\Ansi\Parser\State;

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
        $this->parser->flush();

        // Emit a bare ESC if the parser ended in Escape state (ESC alone with
        // no following byte to complete a sequence).
        if ($this->parser->currentState() === State::Escape) {
            $this->handler->segments[] = new SequenceSegment(
                "\x1b",
                Inspector::describeEsc(''),
            );
        }

        // Emit any buffered SS3 intermediate (e.g. ESC O at end-of-stream).
        if ($this->handler->isSs3Buffered()) {
            $this->handler->segments[] = new SequenceSegment(
                "\x1b" . chr($this->handler->getSs3Intermediate()),
                'SS3 ' . chr($this->handler->getSs3Intermediate()),
            );
        }

        // Flush any remaining buffered text before draining.
        $this->handler->flushText();

        $out = $this->handler->drainSegments();
        $this->handler->reset();
        return $out;
    }
}
