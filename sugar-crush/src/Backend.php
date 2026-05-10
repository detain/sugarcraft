<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

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
     */
    public function complete(array $history, callable $onToken = null): Message;
}
