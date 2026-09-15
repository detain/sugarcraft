<?php

declare(strict_types=1);

namespace SugarCraft\Spark;

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
    /**
     * Segment-collecting handler, owning the incremental parser it drives so
     * one-shot and streaming runs share one byte-fidelity implementation.
     */
    private AnsiHandler $handler;

    public function __construct()
    {
        $this->handler = new AnsiHandler();
    }

    /**
     * Feed a chunk of input. Segments completed by this chunk are returned;
     * incomplete sequences are held in the parser state for the next chunk.
     *
     * @return list<Segment>
     */
    public function feed(string $data): array
    {
        $this->handler->feed($data);
        return $this->handler->drainSegments();
    }

    /**
     * Flush any remaining buffered text and finalise pending sequences.
     *
     * A bare ESC at the end of the stream (e.g. "\x1b" with no following byte),
     * a buffered SS3 intermediate (ESC O with no final byte) and the exact
     * bytes of any sequence the stream never completed are emitted as their own
     * SequenceSegments, so no *dangling tail* the caller sent goes unreported.
     * (Bytes candy-ansi drops before dispatch — the losses `README.md` lists —
     * never reach this method and are not reported by it.)
     *
     * @return list<Segment>
     */
    public function finish(): array
    {
        $this->handler->finish();

        $out = $this->handler->drainSegments();
        $this->handler->reset();
        return $out;
    }
}
