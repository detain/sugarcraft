<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Events;

/**
 * One incremental fragment of the model's THINKING — its reasoning trace — as
 * opposed to {@see TokenDelta}, which is the reply it is composing.
 *
 * The last hop of E456. Round 56 built the reasoning channel all the way from
 * the provider's chunk, through {@see \SugarCraft\Crush\Runtime::run()}'s
 * `$onProgress`, across {@see \SugarCraft\Crush\Backend\EngineBackend}'s fork
 * as a `reasoning` frame, and out of `completeAsync()`'s `$onReasoning`
 * callback — and then stopped, because nothing passed one.
 * {@see \SugarCraft\Crush\Chat::backendCmd()} handed the backend four
 * arguments and the sink would have been a fifth, so a user daily-driving the
 * app watched a static "assistant is thinking…" for two minutes with the
 * thinking itself already crossing the socket and being dropped on the floor.
 *
 * ## Why this is not a {@see TokenDelta}
 *
 * Not a style choice, and not merely so the two can be painted differently.
 * {@see \SugarCraft\Crush\Runtime::runStreaming()} accumulates `$onToken`'s
 * bytes into the {@see \SugarCraft\Crush\Messages\AssistantMessage} that the
 * agentic loop feeds back to the model and that the transcript checkpoints. A
 * thought delivered on the token channel would therefore not merely look wrong
 * — it would enter the conversation, be re-sent as if the assistant had said
 * it, and be compacted and checkpointed alongside real replies. The separation
 * is a correctness boundary; see
 * {@see \SugarCraft\Crush\Tests\Backend\ReasoningPaintTest} for the pin.
 *
 * A DELTA, never a running total, on the same contract {@see TokenDelta}
 * states: consumers append.
 *
 * The EMPTY string never reaches here, and the reason is worth stating per PATH
 * rather than in general, because it is NOT a property of the whole channel.
 * An empty fragment is meaningful one layer down — a chunk with nothing to show
 * is still a sign of life for {@see \SugarCraft\Crush\Backend\EngineBackend}'s
 * idle ceiling — so `EngineBackend::complete()` threads it through untouched and
 * a direct caller of the SYNC method does see it. Both of `completeAsync()`'s
 * paths drop it first: the forked path in the parent's `reasoning`-frame branch,
 * the pcntl-less fallback in its own `$paintable` wrapper. `Chat` uses
 * `completeAsync()` and additionally refuses an empty delta at its own sink, so
 * a `ReasoningDelta` carrying '' cannot be constructed on the live path.
 *
 * Rides {@see \SugarCraft\Crush\Chat}'s ONE shared live inbox alongside
 * {@see TokenDelta}/{@see ToolStarted}/{@see ToolFinished}, for the reason
 * {@see TokenDelta} gives: the relative ORDER of thinking, speaking and tool
 * use is the story of an agentic turn, and parallel queues cannot preserve it.
 */
final readonly class ReasoningDelta
{
    public function __construct(public string $text) {}
}
