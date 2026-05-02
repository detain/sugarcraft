<?php

declare(strict_types=1);

namespace CandyCore\Crush;

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
 * **Why no streaming on the interface?** The current chat shell
 * paints the assistant turn as a single Markdown block once it
 * lands. Streaming token-by-token would require either a
 * generator return type or a callback parameter, both of which
 * leak backend mechanics into the model layer. v0 keeps it
 * synchronous; a `StreamingBackend` extension is the obvious
 * follow-up.
 *
 * @see Backend\EchoBackend  for the default offline / test impl
 */
interface Backend
{
    /**
     * @param list<Message> $history full conversation so far,
     *                                including the user turn we
     *                                want a reply to.
     */
    public function complete(array $history): Message;
}
