<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

/**
 * A provider that can CONTINUE an assistant reply: a request whose last
 * message is a non-empty assistant message ({@see CompleteRequest::continuesFinalMessage()})
 * is answered with more of that message, not with a new one (roadmap 2.7-2).
 *
 * SGLang does it on request (`continue_final_message`); the Anthropic Messages
 * API (Vertex rawPredict, Bedrock Converse) treats a trailing assistant turn
 * as a prefill natively. A provider without this interface is continued with
 * an explicit "continue" user row instead ({@see ReplyContinuation}), which
 * every chat template accepts but which the model can answer less exactly.
 *
 * Per model, because one provider serves families that differ: Vertex's
 * Gemini route has no prefill, and some Anthropic models refuse one outright
 * — {@see ReplyContinuation::rejectsPrefill()} catches that refusal and falls
 * back to the user row.
 */
interface AcceptsAssistantPrefill
{
    /** Whether a request to $model may end on the assistant reply to continue. */
    public function acceptsAssistantPrefill(string $model): bool;
}
