<?php

declare(strict_types=1);

namespace SugarCraft\Code\Events;

/**
 * One incremental fragment of the assistant's reply, emitted by
 * {@see \SugarCraft\Code\Runtime} the moment a provider's SSE chunk is
 * parsed rather than once the whole response has been re-buffered.
 *
 * Exists for the same reason {@see ToolStarted} does, one layer up: the
 * engine parsed the stream correctly at the wire and then threw the
 * incrementality away, so "streaming on" and "streaming off" rendered
 * identically — a silent "assistant is thinking…" placeholder followed by the
 * entire reply at once (crush_code.md Phase 0 item 13).
 *
 * A DELTA, never a running total: consumers append. That is the contract every
 * producer on the path honours — {@see \SugarCraft\Code\Runtime::runStreaming()}
 * forwards each provider chunk verbatim, and the non-streaming
 * {@see \SugarCraft\Code\Runtime::runBatch()} emits the whole content as a
 * single delta so a consumer needs no capability check of its own.
 *
 * Carried alongside {@see ToolStarted}/{@see ToolFinished} in
 * {@see \SugarCraft\Code\Chat}'s ONE shared live inbox rather than on a
 * channel of its own, because the relative ORDER of "the model said this" and
 * "the model then called that tool" is the whole story of an agentic turn and
 * two independent queues cannot preserve it.
 */
final readonly class TokenDelta
{
    public function __construct(public string $text) {}
}
