<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend\CancellationToken;

/**
 * Pluggable assistant backend.
 *
 * Implement this interface to wire SugarCrush to your LLM of
 * choice (Anthropic, OpenAI, Ollama, a local script, anything
 * that returns text). The chat shell calls `complete()` with the
 * full message history each time the user submits a turn; the
 * adapter is responsible for whatever HTTP / IPC / streaming the
 * backend requires.
 *
 * **Streaming:** Pass an optional `$onToken` callback. If provided
 * and streaming is enabled on the chat, the backend SHOULD call
 * it for each token as it arrives, then return the complete
 * Message. If `$onToken` is null or the backend doesn't support
 * streaming, it must still return a valid Message (synchronous
 * fallback).
 *
 * **Tool lifecycle:** Pass an optional `$onEvent` callback to observe
 * the tool calls a backend makes *during* a turn
 * ({@see Events\ToolStarted} / {@see Events\ToolFinished}). It exists
 * because the returned Message is a single opaque final answer: an
 * agentic backend such as {@see Backend\EngineBackend} can run several
 * rounds of tool calls behind it, and without this callback none of
 * them are observable by the caller at all (crush_feat.md §1 E1).
 * A backend that never calls tools ignores it.
 *
 * **Reasoning:** deliberately NOT a fifth parameter here. The model's thinking
 * is a channel of its own — see {@see Events\ReasoningDelta} for why it must
 * never arrive on `$onToken`, whose bytes become the assistant message that is
 * fed back to the model and checkpointed — and a backend that can report it
 * says so by implementing {@see Backend\ObservesReasoning}. Adding the
 * parameter to THIS interface instead would be a load-time fatal for every
 * four-parameter implementation, in this package and outside it, because PHP
 * rejects an implementation with fewer parameters than its interface even when
 * the extra one is optional. MEASURED, and the measurement is a test rather
 * than a sentence pointing at another sentence:
 * {@see \SugarCraft\Crush\Tests\Backend\BackendContractWideningTest}
 * compiles both polarities in a fresh interpreter and reports the interpreter's
 * own version in every failure message. See also that interface's docblock, and
 * {@see Backend\ReportsContextWindow}, which made the same call for the same
 * reason.
 *
 * @see Backend\EchoBackend  for the default offline / test impl
 */
interface Backend
{
    /**
     * @param list<Message> $history full conversation so far,
     *                                including the user turn we
     *                                want a reply to.
     * @param callable|null $onToken optional callback receiving
     *                                each token as it arrives when
     *                                streaming is enabled. Signature:
     *                                `function(string $token): void`
     * @param callable|null $onEvent optional tool-lifecycle observer.
     *                                Signature:
     *                                `function(Events\ToolStarted|Events\ToolFinished $event): void`
     */
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message;

    /**
     * Async version of {@see complete()}. Returns a promise that
     * resolves to the assistant's reply Message.
     *
     * Default implementation wraps the sync {@see complete()} call
     * in a promise. Backends with native async can override.
     *
     * @param CancellationToken|null $cancellation Optional shared cancel
     *                                flag; a backend whose work can outlive
     *                                the caller's interest in it (e.g. one
     *                                that forks off a real request) SHOULD
     *                                poll {@see CancellationToken::isCancelled()}
     *                                and reject early instead of running to
     *                                completion. A backend that can't act on
     *                                this may ignore it.
     * @param callable|null $onEvent Optional tool-lifecycle observer, see
     *                                {@see complete()}. A backend that moves the
     *                                work off-process MUST still deliver these
     *                                in the CALLER's process (replayed if it
     *                                cannot forward them live) — a callback
     *                                invoked inside a forked child reaches
     *                                nobody.
     */
    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface;
}
